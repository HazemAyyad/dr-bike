<?php

namespace App\Http\Controllers\API;

use App\Exceptions\BoxAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Models\Seller;
use App\Services\BoxAccessService;
use App\Services\DebtLedgerService;
use App\Services\EmployeeActivityLogger;
use App\Services\SalesDailySessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    try {
        $this->prepareBoxLogNoteInput($request);

        $request->validate([
            'type'        => 'required|string|in:payment,receive',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'seller_id'   => 'nullable|integer|exists:sellers,id',
            'box_id'      => 'nullable|integer',
            'box_value'   => 'nullable|numeric|min:0',
            'box_log_note' => 'nullable|string|max:500',

            'checks' => 'nullable|array',
            'checks.*.check_value'    => 'required|numeric|min:1',
            'checks.*.check_currency' => 'required|string|max:255',
            'checks.*.check_id'       => 'required|string',
            'checks.*.bank_name'      => 'required|string',
            'checks.*.due_date'       => 'nullable|date',
            'checks.*.img'            => 'nullable|image',
            'checks.*.notes'            => 'nullable|string',

            'debts' => 'nullable|array',
            'debts.*.total'    => 'required|numeric|min:1',
            'debts.*.box_id'    => 'required|integer',

            'debts.*.due_date' => 'nullable|date',
        ]);

        $type = $request->type;

        $isEmbeddedSaleReceive = $type === 'receive'
            && $request->filled('box_id')
            && trim((string) $request->input('box_log_note', '')) !== '';

        if (! $isEmbeddedSaleReceive
            && ! $request->filled('customer_id')
            && ! $request->filled('seller_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.must_select_customer_or_seller'),
            ], 200);
        }

        // Ensure either customer OR seller is provided
        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

        /** ---------------- BOX HANDLING ---------------- */
        if ($request->filled('box_id')) {
            if (!$request->filled('box_value')) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.must_enter_box_value')
                    ], 200);
                }
            $box = $this->boxAccess->findAccessible($request->user(), (int) $request->box_id);
            if ($type === 'receive' && $box->isDailySalesBox()) {
                app(SalesDailySessionService::class)->assertSessionAllowsPayment($request->user(), $box);
            }
            $boxValue = (float) $request->box_value;
            $currentTotal = (float) ($box->total ?? 0);

            if ($type === 'payment') {
                if ($currentTotal < $boxValue) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.box_out_of_money'),
                    ], 200);
                }
                $box->total = $currentTotal - $boxValue;
                BoxLogs::createBoxLog(
                    $box,
                    'سحب — دفع من الصندوق',
                    'minus',
                    -$boxValue,
                    'دفع نقدي من الصندوق بقيمة '.number_format($boxValue, 2, '.', '')
                );
            } else { // receive
                $box->total = $currentTotal + $boxValue;
                $receiveNote = $this->clampBoxLogNote(
                    (string) $request->input('box_log_note', '')
                ) ?? '';
                if ($receiveNote === '') {
                    $receiveNote = 'قبض نقدي في الصندوق بقيمة '.number_format($boxValue, 2, '.', '');
                }
                BoxLogs::createBoxLog(
                    $box,
                    'قبض — بيع فوري / قبض نقدي',
                    'add',
                    $boxValue,
                    $receiveNote
                );
            }

            $box->save();
        }

        /** ---------------- CHECKS HANDLING ---------------- */
        if ($request->filled('checks')) {
                if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('messages.must_select_customer_or_seller')
                        ], 200);
                    }  
            $path=null;
            $personName='غير معروف';
            if($request->filled('customer_id')){
                $customer = Customer::findOrFail($request->customer_id);
                $personName = $customer->name;
            }
            elseif($request->filled('seller_id')){
                $seller = Seller::findOrFail($request->seller_id);
                $personName = $seller->name;
            }


            if($type==='payment'){
                $path = 'OutgoingChecksImages';
            } 
            else
                { 

                    $path = 'IncomingCheckImages/front' ;
                
                }
            foreach ($request->checks as $index => $checkData) {
                $checkImageName = null;

                if ($request->hasFile("checks.$index.img")) {
                    $image = $request->file("checks.$index.img");
                    $imageName = $image->getClientOriginalName();
                    $image->move(public_path($path), $imageName);
                    $checkImageName = $imageName;
                }

                if ($type === 'payment') {
                    $check = OutgoingCheck::create([
                        'total'     => $checkData['check_value'],
                        'due_date'  => $checkData['due_date'] ?? null,
                        'currency'  => $checkData['check_currency'],
                        'check_id'  => $checkData['check_id'],
                        'bank_name' => $checkData['bank_name'],
                        'img'       => $checkImageName,
                        'status' => 'cashed_to_person',
                        'customer_id' => $request->customer_id??null,
                        'seller_id' => $request->seller_id??null,
                        'notes'     => $checkData['notes'],
                       
                    ]);



                    app(DebtLedgerService::class)->syncOutgoingCheckToLedger($check->fresh());

                    Logs::createLog(
                        'صرف شيك صادر في دفتر الديون',
                        'تم تسجيل صرف شيك صادر بقيمة '.$check->total.' '.$check->currency.' لصالح '.$personName.' في دفتر الديون',
                        'debts'
                    );

                    Logs::createLog(
                        'اضافة شيك صادر والتصرف فيه',
                        "تمت إضافة شيك صادر بقيمة {$check->total}  {$check->currency}".' '.'والتصرف فيه لصالح'.$personName,
                        'outgoing_checks'
                    );
                } else {
                    $check = IncomingCheck::create([
                        'total'        => $checkData['check_value'],
                        'due_date'     => $checkData['due_date'] ?? null,
                        'currency'     => $checkData['check_currency'],
                        'check_id'     => $checkData['check_id'],
                        'bank_name'    => $checkData['bank_name'],
                        'front_image'  => $checkImageName,
                        'from_customer'=> $request->customer_id ?? null,
                        'from_seller'  => $request->seller_id ?? null,
                        'notes'     => $checkData['notes'],

                    ]);

                    app(DebtLedgerService::class)->syncIncomingCheckToLedger(
                        $check->fresh(['fromCustomer', 'fromSeller'])
                    );

                    Logs::createLog(
                        'اضافة شيك وارد جديد',
                        "تمت إضافة شيك وارد بقيمة {$check->total} {$check->currency}"." "."من الشخص"." ".$personName,
                        'incoming_checks'
                    );
                }
            }
        }

        /** ---------------- DEBTS HANDLING ---------------- */
        if ($request->filled('debts')) {
            if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.must_select_customer_or_seller')
                ], 200);
            }
            DB::transaction(function () use ($request, $type) {
                foreach ($request->debts as $debtData) {
                    $box = $this->boxAccess->findAccessible(
                        $request->user(),
                        (int) $debtData['box_id'],
                        lockForUpdate: true,
                    );
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
                        'debts'
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
                        ]
                    );
                }
            });
        }

        return response()->json([
            'status'  => 'success',
            'message' => $type === 'payment'
                ? __('messages.payment_success')
                : __('messages.receive_success'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
            'errors' => $e->errors(),
        ], 200);

    } catch (BoxAccessDeniedException $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 200);

    } catch (QueryException $e) {
        Log::error('handlePayment QueryException', [
            'message' => $e->getMessage(),
            'sql' => $e->getSql(),
            'bindings' => $e->getBindings(),
        ]);

        $userMessage = __('messages.create_data_error');
        if (config('app.debug')) {
            $userMessage = $e->getMessage();
        }

        return response()->json([
            'status' => 'error',
            'message' => $userMessage,
        ], 200);

    } catch (\Throwable $e) {
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

}
