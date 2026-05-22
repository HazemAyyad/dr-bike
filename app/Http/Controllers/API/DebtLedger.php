<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DebtTransaction;
use App\Services\DebtLedgerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DebtLedger extends Controller
{
    public function __construct(private DebtLedgerService $ledger)
    {
    }

    public function summary()
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->ledger->getGlobalSummary(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function people(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:customers,sellers',
                'search' => 'nullable|string',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $people = $this->ledger->getPeopleList(
                $request->type,
                $request->search,
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'status' => 'success',
                'people' => $people,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function peoplePicker(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:customers,sellers',
                'search' => 'nullable|string',
            ]);

            $people = $this->ledger->getPeoplePickerList(
                $request->type,
                $request->search
            );

            return response()->json([
                'status' => 'success',
                'people' => $people,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function person(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $query = $this->ledger->baseQuery($request->customer_id, $request->seller_id);
            $this->ledger->applyDateFilter($query, $request->start_date, $request->end_date);

            $transactions = (clone $query)
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get();

            $totals = $this->ledger->calculateTotals(
                $request->customer_id,
                $request->seller_id,
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'balances' => $this->ledger->calculateBalancesByCurrency(
                    $request->customer_id,
                    $request->seller_id,
                    $request->start_date,
                    $request->end_date
                ),
                'active_transactions_count' => $transactions->count(),
                'transactions' => $transactions->map(fn ($t) => $this->ledger->formatTransaction($t))->values(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.customer_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function storeTransaction(Request $request)
    {
        try {
            $data = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
                'type' => 'required|in:taken,given',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'transaction_date' => 'required|date',
                'note' => 'nullable|string',
                'box_id' => 'nullable|integer|exists:boxes,id',
                'receipt_images' => 'nullable|array',
                'receipt_images.*' => 'image',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $imageNames = [];
            $uploadedFiles = $request->file('receipt_images');
            if (!is_array($uploadedFiles) && $uploadedFiles) {
                $uploadedFiles = [$uploadedFiles];
            }
            if (is_array($uploadedFiles)) {
                foreach ($uploadedFiles as $image) {
                    if (!$image) {
                        continue;
                    }
                    $imageName = time() . '_' . uniqid() . '_' . $image->getClientOriginalName();
                    $image->move(public_path('DebtsReceipts'), $imageName);
                    $imageNames[] = $imageName;
                }
            }

            $currency = $data['currency'] ?? null;
            if (! empty($data['box_id'])) {
                $box = \App\Models\Box::find($data['box_id']);
                $currency = $box?->currency ?? $currency;
            }

            $transaction = $this->ledger->createTransaction([
                'customer_id' => $request->customer_id,
                'seller_id' => $request->seller_id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'currency' => $this->ledger->normalizeCurrency($currency),
                'transaction_date' => $data['transaction_date'],
                'note' => $data['note'] ?? null,
                'box_id' => $data['box_id'] ?? null,
                'receipt_images' => $imageNames ?: null,
                'source' => 'manual',
            ], auth()->id());

            $personTotals = $this->ledger->calculateTotals($request->customer_id, $request->seller_id);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transaction_created'),
                'transaction' => $this->ledger->formatTransaction($transaction),
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_failed'),
            ], 200);
        }
    }

    public function showTransaction(int $id)
    {
        try {
            $transaction = DebtTransaction::active()->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'transaction' => $this->ledger->formatTransaction($transaction),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function updateTransaction(Request $request, int $id)
    {
        try {
            $data = $request->validate([
                'type' => 'required|in:taken,given',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'transaction_date' => 'required|date',
                'note' => 'nullable|string',
                'box_id' => 'nullable|integer|exists:boxes,id',
            ]);

            $transaction = DebtTransaction::active()->findOrFail($id);

            $imageNames = $transaction->receipt_images ?? [];
            if ($request->hasFile('receipt_images')) {
                $imageNames = [];
                $uploadedFiles = $request->file('receipt_images');
                if (!is_array($uploadedFiles)) {
                    $uploadedFiles = [$uploadedFiles];
                }
                foreach ($uploadedFiles as $image) {
                    if (!$image) {
                        continue;
                    }
                    $imageName = time() . '_' . uniqid() . '_' . $image->getClientOriginalName();
                    $image->move(public_path('DebtsReceipts'), $imageName);
                    $imageNames[] = $imageName;
                }
            }

            $currency = $data['currency'] ?? $transaction->currency;
            if ($request->filled('box_id')) {
                $box = \App\Models\Box::find($data['box_id']);
                $currency = $box?->currency ?? $currency;
            }

            $updatePayload = [
                'type' => $data['type'],
                'amount' => $data['amount'],
                'currency' => $this->ledger->normalizeCurrency($currency),
                'transaction_date' => $data['transaction_date'],
                'note' => $data['note'] ?? null,
                'receipt_images' => $request->hasFile('receipt_images') ? $imageNames : null,
            ];

            if ($request->filled('box_id')) {
                $updatePayload['box_id'] = (int) $data['box_id'];
            }

            $updated = $this->ledger->updateTransaction($transaction, $updatePayload);

            $personTotals = $this->ledger->calculateTotals(
                $transaction->customer_id,
                $transaction->seller_id
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transaction_updated'),
                'transaction' => $this->ledger->formatTransaction($updated),
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_failed'),
            ], 200);
        }
    }

    public function archiveTransaction(int $id)
    {
        try {
            $transaction = DebtTransaction::active()->findOrFail($id);
            $this->ledger->archiveTransaction($transaction);

            $personTotals = $this->ledger->calculateTotals(
                $transaction->customer_id,
                $transaction->seller_id
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transaction_archived'),
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function deleteTransaction(int $id)
    {
        try {
            $transaction = DebtTransaction::active()->findOrFail($id);
            $this->ledger->deleteTransaction($transaction);

            $personTotals = $this->ledger->calculateTotals(
                $transaction->customer_id,
                $transaction->seller_id
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transaction_permanently_deleted'),
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function createPersonShareLink(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $token = Str::uuid()->toString();
            Cache::put('debt_ledger_share:' . $token, [
                'customer_id' => $request->customer_id,
                'seller_id' => $request->seller_id,
            ], now()->addDays(90));

            return response()->json([
                'status' => 'success',
                'share_url' => url('debt-ledger/share/' . $token),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function updatePersonMeta(Request $request)
    {
        try {
            $data = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
                'notes' => 'nullable|string',
                'collection_reminder_at' => 'nullable|date',
                'clear_collection_reminder' => 'nullable|boolean',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $touchNotes = $request->has('notes');
            $touchReminder = $request->has('collection_reminder_at')
                || $request->boolean('clear_collection_reminder');

            $reminderDate = $request->boolean('clear_collection_reminder')
                ? null
                : ($data['collection_reminder_at'] ?? null);

            $person = $this->ledger->updatePersonMeta(
                $request->customer_id,
                $request->seller_id,
                $data['notes'] ?? null,
                $reminderDate,
                $touchNotes,
                $touchReminder
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_person_updated'),
                'person' => $person,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.customer_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function personArchive(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $transactions = $this->ledger->archivedQuery($request->customer_id, $request->seller_id)
                ->orderByDesc('archived_at')
                ->orderByDesc('id')
                ->get();

            $totals = $this->ledger->calculateArchivedTotals(
                $request->customer_id,
                $request->seller_id
            );

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'archived_transactions_count' => $transactions->count(),
                'transactions' => $transactions->map(fn ($t) => $this->ledger->formatTransaction($t))->values(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function personDeleted(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $transactions = $this->ledger->deletedQuery($request->customer_id, $request->seller_id)
                ->orderByDesc('deleted_at')
                ->orderByDesc('id')
                ->get();

            $totals = $this->ledger->calculateDeletedTotals(
                $request->customer_id,
                $request->seller_id
            );

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'deleted_transactions_count' => $transactions->count(),
                'transactions' => $transactions->map(fn ($t) => $this->ledger->formatTransaction($t))->values(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function archiveTransactionsBulk(Request $request)
    {
        try {
            $data = $request->validate([
                'transaction_ids' => 'required|array|min:1',
                'transaction_ids.*' => 'integer|exists:debt_transactions,id',
            ]);

            $count = $this->ledger->archiveTransactions($data['transaction_ids']);

            $first = DebtTransaction::find($data['transaction_ids'][0]);

            $personTotals = ['total_taken' => 0, 'total_given' => 0, 'balance' => 0];
            if ($first) {
                $personTotals = $this->ledger->calculateTotals(
                    $first->customer_id,
                    $first->seller_id
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transactions_archived'),
                'archived_count' => $count,
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function restoreTransactionsBulk(Request $request)
    {
        try {
            $data = $request->validate([
                'transaction_ids' => 'required|array|min:1',
                'transaction_ids.*' => 'integer|exists:debt_transactions,id',
            ]);

            $count = $this->ledger->restoreTransactions($data['transaction_ids']);

            $first = DebtTransaction::find($data['transaction_ids'][0]);
            $personTotals = ['total_taken' => 0, 'total_given' => 0, 'balance' => 0];
            $archiveTotals = ['total_taken' => 0, 'total_given' => 0, 'balance' => 0];

            if ($first) {
                $personTotals = $this->ledger->calculateTotals(
                    $first->customer_id,
                    $first->seller_id
                );
                $archiveTotals = $this->ledger->calculateArchivedTotals(
                    $first->customer_id,
                    $first->seller_id
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.ledger_transaction_restored'),
                'restored_count' => $count,
                'total_taken' => $personTotals['total_taken'],
                'total_given' => $personTotals['total_given'],
                'balance' => $personTotals['balance'],
                'archive_balance' => $archiveTotals['balance'],
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function personReport(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'period' => 'nullable|in:all,today,yesterday,current_week,last_week,current_month,last_month,custom',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            [$startDate, $endDate] = $this->ledger->resolvePeriodDates(
                $request->period ?? 'all',
                $request->start_date,
                $request->end_date
            );

            if ($request->period === 'custom' && (!$startDate || !$endDate)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                ], 200);
            }

            $person = $this->ledger->getPersonInfo($request->customer_id, $request->seller_id);
            $query = $this->ledger->baseQuery($request->customer_id, $request->seller_id);
            $this->ledger->applyDateFilter($query, $startDate, $endDate);

            $transactions = $query
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            $totals = $this->ledger->calculateTotals(
                $request->customer_id,
                $request->seller_id,
                $startDate,
                $endDate
            );

            $periodLabel = $this->formatPeriodLabel($request->period, $startDate, $endDate);

            $reportHtml = view('pdf.debt-ledger-report', [
                'person' => $person,
                'transactions' => $transactions,
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'period_label' => $periodLabel,
                'generated_at' => now()->format('Y-m-d H:i'),
                'transactions_count' => $transactions->count(),
            ])->render();

            $arabic = new Arabic();
            $positions = $arabic->arIdentify($reportHtml);

            for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
                $utf8ar = $arabic->utf8Glyphs(
                    substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
                );
                $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
            }

            $pdf = Pdf::loadHTML($reportHtml);

            if ($request->boolean('json_response')) {
                $fileName = 'debt_ledger_' . $person['id'] . '_' . time() . '.pdf';
                $path = 'debt-ledger-reports/' . $fileName;
                $fullPath = public_path($path);

                if (!is_dir(dirname($fullPath))) {
                    mkdir(dirname($fullPath), 0755, true);
                }

                $pdf->save($fullPath);

                return response()->json([
                    'status' => 'success',
                    'report' => [
                        'pdf_url' => url($path),
                        'file_name' => $fileName,
                        'person' => $person,
                        'total_taken' => $totals['total_taken'],
                        'total_given' => $totals['total_given'],
                        'balance' => $totals['balance'],
                        'transactions_count' => $transactions->count(),
                        'period_label' => $periodLabel,
                    ],
                ], 200);
            }

            return $pdf->download('debt_ledger_report.pdf');
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_report_failed'),
            ], 200);
        }
    }

    private function formatPeriodLabel(?string $period, ?string $startDate, ?string $endDate): string
    {
        return match ($period) {
            'today' => 'اليوم',
            'yesterday' => 'الأمس',
            'current_week' => 'الأسبوع الحالي',
            'last_week' => 'الأسبوع الماضي',
            'current_month' => 'الشهر الحالي',
            'last_month' => 'الشهر الماضي',
            'custom' => ($startDate && $endDate) ? "من {$startDate} إلى {$endDate}" : 'فترة مخصصة',
            default => 'جميع التواريخ',
        };
    }

    public function transactionActivity(int $id)
    {
        try {
            DebtTransaction::query()->where('id', $id)->firstOrFail();

            return response()->json([
                'status' => 'success',
                'activity' => $this->ledger->getTransactionActivity($id),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.ledger_transaction_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function personActivity(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'seller_id' => 'nullable|exists:sellers,id',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            return response()->json([
                'status' => 'success',
                'activity' => $this->ledger->getPersonActivity(
                    $request->customer_id,
                    $request->seller_id
                ),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
