<?php

namespace App\Http\Controllers\API;

use App\Exceptions\BoxAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Customer;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Models\Seller;
use App\Services\BoxAccessService;
use App\Services\DebtLedgerService;
use App\Services\EmployeeActivityLogger;
use App\Services\SalesDailySessionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentAndRecieve extends Controller
{
    public function __construct(private BoxAccessService $boxAccess) {}

    private function clampBoxLogNote(?string $note, int $max = 500): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) <= $max) {
            return $note === '' ? null : $note;
        }

        return mb_substr($note, 0, $max - 3).'...';
    }

    private function prepareBoxLogNoteInput(Request $request): void
    {
        if (! $request->has('box_log_note')) {
            return;
        }

        $clamped = $this->clampBoxLogNote((string) $request->input('box_log_note'));
        $request->merge(['box_log_note' => $clamped ?? '']);
    }

    public function handlePayment(Request $request)
    {
        /** @var array<int, string> $createdFiles */
        $createdFiles = [];
        $transactionCommitted = false;

        try {
            $this->prepareBoxLogNoteInput($request);

            $request->validate([
                'type' => 'required|string|in:payment,receive',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'seller_id' => 'nullable|integer|exists:sellers,id',
                'box_id' => 'nullable|integer',
                'box_value' => 'nullable|numeric|min:0',
                'box_log_note' => 'nullable|string|max:500',
                'checks' => 'nullable|array',
                'checks.*.check_value' => 'required|numeric|min:1',
                'checks.*.check_currency' => 'required|string|max:255',
                'checks.*.check_id' => 'required|string',
                'checks.*.bank_name' => 'required|string',
                'checks.*.due_date' => 'nullable|date',
                'checks.*.img' => 'nullable|image',
                'checks.*.notes' => 'nullable|string',
                'debts' => 'nullable|array',
                'debts.*.total' => 'required|numeric|min:1',
                'debts.*.box_id' => 'required|integer',
                'debts.*.due_date' => 'nullable|date',
            ]);

            $type = (string) $request->type;
            $isEmbeddedSaleReceive = $type === 'receive'
                && $request->filled('box_id')
                && trim((string) $request->input('box_log_note', '')) !== '';

            if (! $isEmbeddedSaleReceive
                && ! $request->filled('customer_id')
                && ! $request->filled('seller_id')) {
                return $this->errorResponse(__('messages.must_select_customer_or_seller'));
            }
            if ($request->filled('customer_id') && $request->filled('seller_id')) {
                return $this->errorResponse(__('messages.must_select_either_customer_or_seller'));
            }
            if ($request->filled('box_id') && ! $request->filled('box_value')) {
                return $this->errorResponse(__('messages.must_enter_box_value'));
            }
            if (($request->filled('checks') || $request->filled('debts'))
                && ! $request->filled('customer_id')
                && ! $request->filled('seller_id')) {
                return $this->errorResponse(__('messages.must_select_customer_or_seller'));
            }

            $personName = $this->personName($request);

            DB::transaction(function () use ($request, $type, $personName, &$createdFiles): void {
                $boxes = $this->lockAndPreflightBoxes($request, $type);
                $this->applyTopLevelCash($request, $type, $boxes);
                $this->createChecks($request, $type, $personName, $createdFiles);
                $this->createDebtTransactions($request, $type, $boxes);
            });
            $transactionCommitted = true;

            return response()->json([
                'status' => 'success',
                'message' => $type === 'payment'
                    ? __('messages.payment_success')
                    : __('messages.receive_success'),
            ], 200);
        } catch (ValidationException $e) {
            if (! $transactionCommitted) {
                $this->cleanupCreatedFiles($createdFiles);
            }
            $boxError = $e->errors()['box_id'][0] ?? null;

            return response()->json([
                'status' => 'error',
                'message' => $boxError === __('messages.box_out_of_money')
                    ? $boxError
                    : __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (BoxAccessDeniedException $e) {
            if (! $transactionCommitted) {
                $this->cleanupCreatedFiles($createdFiles);
            }

            return $this->errorResponse($e->getMessage());
        } catch (QueryException $e) {
            if (! $transactionCommitted) {
                $this->cleanupCreatedFiles($createdFiles);
            }
            Log::error('handlePayment QueryException', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return $this->errorResponse(config('app.debug') ? $e->getMessage() : __('messages.create_data_error'));
        } catch (\Throwable $e) {
            if (! $transactionCommitted) {
                $this->cleanupCreatedFiles($createdFiles);
            }
            Log::error('handlePayment error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    /** @return array<int, Box> */
    private function lockAndPreflightBoxes(Request $request, string $type): array
    {
        $boxIds = collect($request->input('debts', []))
            ->pluck('box_id')
            ->when($request->filled('box_id'), fn ($ids) => $ids->push((int) $request->box_id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $boxes = [];
        foreach ($boxIds as $boxId) {
            $isSaleReceiptBox = $type === 'receive'
                && $request->filled('box_id')
                && (int) $request->box_id === $boxId;

            $boxes[$boxId] = $isSaleReceiptBox
                ? $this->boxAccess->findAccessibleForSaleReceipt(
                    $request->user(),
                    $boxId,
                    lockForUpdate: true,
                )
                : $this->boxAccess->findAccessible(
                    $request->user(),
                    $boxId,
                    lockForUpdate: true,
                );
        }

        if ($request->filled('box_id') && $type === 'receive') {
            $box = $boxes[(int) $request->box_id];
            if ($box->isDailySalesBox()) {
                app(SalesDailySessionService::class)->assertSessionAllowsPayment($request->user(), $box);
            }
        }

        if ($type === 'payment') {
            $requiredByBox = [];
            if ($request->filled('box_id')) {
                $boxId = (int) $request->box_id;
                $requiredByBox[$boxId] = ($requiredByBox[$boxId] ?? 0) + (float) $request->box_value;
            }
            foreach ($request->input('debts', []) as $debt) {
                $boxId = (int) $debt['box_id'];
                $requiredByBox[$boxId] = ($requiredByBox[$boxId] ?? 0) + (float) $debt['total'];
            }
            foreach ($requiredByBox as $boxId => $required) {
                if ((float) $boxes[$boxId]->total + 0.0001 < $required) {
                    throw ValidationException::withMessages([
                        'box_id' => [__('messages.box_out_of_money')],
                    ]);
                }
            }
        }

        return $boxes;
    }

    /** @param array<int, Box> $boxes */
    private function applyTopLevelCash(Request $request, string $type, array $boxes): void
    {
        if (! $request->filled('box_id')) {
            return;
        }

        $box = $boxes[(int) $request->box_id];
        $boxValue = (float) $request->box_value;
        if ($type === 'payment') {
            $box->update(['total' => (float) $box->total - $boxValue]);
            BoxLogs::createBoxLog(
                $box,
                'سحب — دفع من الصندوق',
                'minus',
                -$boxValue,
                'دفع نقدي من الصندوق بقيمة '.number_format($boxValue, 2, '.', ''),
            );

            return;
        }

        $box->update(['total' => (float) $box->total + $boxValue]);
        $receiveNote = $this->clampBoxLogNote((string) $request->input('box_log_note', ''))
            ?? 'قبض نقدي في الصندوق بقيمة '.number_format($boxValue, 2, '.', '');
        BoxLogs::createBoxLog(
            $box,
            'قبض — بيع فوري / قبض نقدي',
            'add',
            $boxValue,
            $receiveNote,
        );
    }

    /** @param array<int, string> $createdFiles */
    private function createChecks(Request $request, string $type, string $personName, array &$createdFiles): void
    {
        foreach ($request->input('checks', []) as $index => $checkData) {
            $checkImageName = $this->storeCheckImage($request, (int) $index, $type, $createdFiles);

            if ($type === 'payment') {
                $check = OutgoingCheck::create([
                    'total' => $checkData['check_value'],
                    'due_date' => $checkData['due_date'] ?? null,
                    'currency' => $checkData['check_currency'],
                    'check_id' => $checkData['check_id'],
                    'bank_name' => $checkData['bank_name'],
                    'img' => $checkImageName,
                    'status' => 'cashed_to_person',
                    'customer_id' => $request->customer_id ?? null,
                    'seller_id' => $request->seller_id ?? null,
                    'notes' => $checkData['notes'] ?? null,
                ]);
                app(DebtLedgerService::class)->syncOutgoingCheckToLedger($check->fresh());
                Logs::createLog(
                    'صرف شيك صادر في دفتر الديون',
                    'تم تسجيل صرف شيك صادر بقيمة '.$check->total.' '.$check->currency.' لصالح '.$personName.' في دفتر الديون',
                    'debts',
                );
                Logs::createLog(
                    'اضافة شيك صادر والتصرف فيه',
                    "تمت إضافة شيك صادر بقيمة {$check->total} {$check->currency} والتصرف فيه لصالح {$personName}",
                    'outgoing_checks',
                );

                continue;
            }

            $check = IncomingCheck::create([
                'total' => $checkData['check_value'],
                'due_date' => $checkData['due_date'] ?? null,
                'currency' => $checkData['check_currency'],
                'check_id' => $checkData['check_id'],
                'bank_name' => $checkData['bank_name'],
                'front_image' => $checkImageName,
                'from_customer' => $request->customer_id ?? null,
                'from_seller' => $request->seller_id ?? null,
                'notes' => $checkData['notes'] ?? null,
            ]);
            app(DebtLedgerService::class)->syncIncomingCheckToLedger(
                $check->fresh(['fromCustomer', 'fromSeller']),
            );
            Logs::createLog(
                'اضافة شيك وارد جديد',
                "تمت إضافة شيك وارد بقيمة {$check->total} {$check->currency} من الشخص {$personName}",
                'incoming_checks',
            );
        }
    }

    /** @param array<int, Box> $boxes */
    private function createDebtTransactions(Request $request, string $type, array $boxes): void
    {
        foreach ($request->input('debts', []) as $debtData) {
            $box = $boxes[(int) $debtData['box_id']];
            $transaction = app(DebtLedgerService::class)->createTransaction([
                'customer_id' => $request->customer_id ?? null,
                'seller_id' => $request->seller_id ?? null,
                'type' => $type === 'receive' ? 'taken' : 'given',
                'amount' => $debtData['total'],
                'currency' => $box->currency,
                'transaction_date' => $debtData['due_date'] ?? now()->toDateString(),
                'box_id' => $box->id,
                'source' => 'manual',
                'note' => $type === 'payment'
                    ? 'دفعة دين يدوية من شاشة الدفع والاستلام'
                    : 'قبض دين يدوي من شاشة الدفع والاستلام',
            ], $request->user()?->id, actor: $request->user());

            Logs::createLog(
                'إنشاء حركة في دفتر الديون',
                'تم إنشاء الحركة #'.$transaction->id.' من شاشة الدفع والاستلام بقيمة '.$transaction->amount.' '.$transaction->currency,
                'debts',
            );
            app(EmployeeActivityLogger::class)->log(
                null,
                $request->user(),
                'debts',
                'created_debt_ledger_transaction',
                'إضافة حركة إلى دفتر الديون',
                'تمت إضافة حركة دفتر ديون بقيمة '.$transaction->amount,
                $transaction,
                (float) $transaction->amount,
                [
                    'person_type' => $transaction->customer_id ? 'customer' : 'seller',
                    'person_id' => (int) ($transaction->customer_id ?: $transaction->seller_id),
                    'transaction_type' => $transaction->type,
                ],
            );
        }
    }

    /** @param array<int, string> $createdFiles */
    private function storeCheckImage(Request $request, int $index, string $type, array &$createdFiles): ?string
    {
        if (! $request->hasFile("checks.$index.img")) {
            return null;
        }

        $image = $request->file("checks.$index.img");
        $path = $type === 'payment' ? 'OutgoingChecksImages' : 'IncomingCheckImages/front';
        $directory = public_path($path);
        File::ensureDirectoryExists($directory);
        $extension = strtolower((string) $image->getClientOriginalExtension());
        $imageName = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');
        $image->move($directory, $imageName);
        $createdFiles[] = $directory.DIRECTORY_SEPARATOR.$imageName;

        return $imageName;
    }

    /** @param array<int, string> $createdFiles */
    private function cleanupCreatedFiles(array $createdFiles): void
    {
        foreach ($createdFiles as $path) {
            if (is_file($path)) {
                File::delete($path);
            }
        }
    }

    private function personName(Request $request): string
    {
        if ($request->filled('customer_id')) {
            return (string) Customer::query()->findOrFail($request->customer_id)->name;
        }
        if ($request->filled('seller_id')) {
            return (string) Seller::query()->findOrFail($request->seller_id)->name;
        }

        return 'غير معروف';
    }

    private function errorResponse(string $message)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 200);
    }
}
