<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\ContactCategory;
use App\Models\ContactCategoryAssignment;
use App\Models\DebtTransaction;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesOrder;
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'category_id' => 'nullable|integer|exists:contact_categories,id',
            ]);

            $people = $this->ledger->getPeopleList(
                $request->type,
                $request->search,
                $request->start_date,
                $request->end_date,
                $request->currency,
                $request->filled('category_id') ? (int) $request->category_id : null
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

    public function categories()
    {
        try {
            $categories = ContactCategory::query()
                ->with('assignments:id,contact_category_id,customer_id,seller_id')
                ->withCount([
                    'assignments as customers_count' => fn ($q) => $q->whereNotNull('customer_id'),
                    'assignments as sellers_count' => fn ($q) => $q->whereNotNull('seller_id'),
                ])
                ->latest()
                ->get()
                ->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'color' => $category->color,
                    'customers_count' => (int) $category->customers_count,
                    'sellers_count' => (int) $category->sellers_count,
                    'customer_ids' => $category->assignments
                        ->pluck('customer_id')
                        ->filter()
                        ->values(),
                    'seller_ids' => $category->assignments
                        ->pluck('seller_id')
                        ->filter()
                        ->values(),
                ]);

            return response()->json([
                'status' => 'success',
                'categories' => $categories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function storeCategory(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:80',
                'color' => 'nullable|string|max:20',
                'customer_ids' => 'nullable|array',
                'customer_ids.*' => 'integer|exists:customers,id',
                'seller_ids' => 'nullable|array',
                'seller_ids.*' => 'integer|exists:sellers,id',
            ]);

            $category = ContactCategory::create([
                'name' => $data['name'],
                'color' => $data['color'] ?? '#2196F3',
            ]);
            $this->syncCategoryAssignments($category, $data['customer_ids'] ?? [], $data['seller_ids'] ?? []);

            return response()->json([
                'status' => 'success',
                'message' => 'تم حفظ التصنيف',
                'category' => $category,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }

    public function updateCategory(Request $request, int $id)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:80',
                'color' => 'nullable|string|max:20',
                'customer_ids' => 'nullable|array',
                'customer_ids.*' => 'integer|exists:customers,id',
                'seller_ids' => 'nullable|array',
                'seller_ids.*' => 'integer|exists:sellers,id',
            ]);

            $category = ContactCategory::findOrFail($id);
            $category->update([
                'name' => $data['name'],
                'color' => $data['color'] ?? $category->color,
            ]);
            $this->syncCategoryAssignments($category, $data['customer_ids'] ?? [], $data['seller_ids'] ?? []);

            return response()->json([
                'status' => 'success',
                'message' => 'تم تعديل التصنيف',
                'category' => $category->fresh(),
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
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function deleteCategory(int $id)
    {
        try {
            ContactCategory::findOrFail($id)->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'تم حذف التصنيف',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function syncCategoryAssignments(ContactCategory $category, array $customerIds, array $sellerIds): void
    {
        ContactCategoryAssignment::query()
            ->where('contact_category_id', $category->id)
            ->delete();

        foreach (array_unique(array_map('intval', $customerIds)) as $customerId) {
            ContactCategoryAssignment::create([
                'contact_category_id' => $category->id,
                'customer_id' => $customerId,
            ]);
        }

        foreach (array_unique(array_map('intval', $sellerIds)) as $sellerId) {
            ContactCategoryAssignment::create([
                'contact_category_id' => $category->id,
                'seller_id' => $sellerId,
            ]);
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'balance_scope' => 'nullable|string|in:full,period',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $filterCurrency = $request->filled('currency')
                ? $this->ledger->normalizeCurrency($request->currency)
                : null;

            $query = $this->ledger->baseQuery($request->customer_id, $request->seller_id);
            $this->ledger->applyDateFilter($query, $request->start_date, $request->end_date);

            if ($filterCurrency) {
                $query->where('currency', $filterCurrency);
            }

            $transactions = (clone $query)
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get();

            $displayCurrency = $filterCurrency ?? 'شيكل';
            $balanceStartDate = $request->balance_scope === 'period'
                ? $request->start_date
                : null;
            $balanceEndDate = $request->balance_scope === 'period'
                ? $request->end_date
                : null;

            $totals = $this->ledger->calculateTotals(
                $request->customer_id,
                $request->seller_id,
                $balanceStartDate,
                $balanceEndDate,
                $displayCurrency
            );

            $balancesByCurrency = [];
            foreach (DebtLedgerService::CURRENCIES as $currencyCode) {
                $currencyTotals = $this->ledger->calculateTotals(
                    $request->customer_id,
                    $request->seller_id,
                    $balanceStartDate,
                    $balanceEndDate,
                    $currencyCode
                );
                $balancesByCurrency[$currencyCode] = [
                    'total_taken' => $currencyTotals['total_taken'],
                    'total_given' => $currencyTotals['total_given'],
                    'balance' => $currencyTotals['balance'],
                ];
            }

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'currency' => $displayCurrency,
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'balances' => $balancesByCurrency,
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
                'receipt_images.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp,heic,heif,mp4,mov,webm,3gp,m4v,avi,mkv',
                    'max:51200',
                ],
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
                'receipt_images' => 'nullable|array',
                'receipt_images.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp,heic,heif,mp4,mov,webm,3gp,m4v,avi,mkv',
                    'max:51200',
                ],
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
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'period' => 'nullable|in:all,today,yesterday,current_week,last_week,current_month,last_month,custom',
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'report_detail_level' => 'nullable|in:summary,detailed,detailed_with_images',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            [$startDate, $endDate] = $this->ledger->resolvePeriodDates(
                $request->input('period', 'all'),
                $request->start_date,
                $request->end_date
            );

            if ($request->input('period') === 'custom' && (! $startDate || ! $endDate)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                ], 200);
            }

            $token = Str::uuid()->toString();
            Cache::put('debt_ledger_share:' . $token, [
                'customer_id' => $request->customer_id,
                'seller_id' => $request->seller_id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'currency' => $this->ledger->normalizeCurrency($request->currency),
                'report_detail_level' => $request->input('report_detail_level', 'summary'),
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $filterCurrency = $request->filled('currency')
                ? $this->ledger->normalizeCurrency($request->currency)
                : null;

            $query = $this->ledger->archivedQuery($request->customer_id, $request->seller_id);
            if ($filterCurrency) {
                $query->where('currency', $filterCurrency);
            }

            $transactions = (clone $query)
                ->orderByDesc('archived_at')
                ->orderByDesc('id')
                ->get();

            $displayCurrency = $filterCurrency ?? 'شيكل';
            $totals = $this->ledger->calculateArchivedTotals(
                $request->customer_id,
                $request->seller_id,
                $displayCurrency
            );

            $balancesByCurrency = $this->ledger->calculateArchivedBalancesByCurrency(
                $request->customer_id,
                $request->seller_id
            );

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'currency' => $displayCurrency,
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'balances' => $balancesByCurrency,
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $filterCurrency = $request->filled('currency')
                ? $this->ledger->normalizeCurrency($request->currency)
                : null;

            $query = $this->ledger->deletedQuery($request->customer_id, $request->seller_id);
            if ($filterCurrency) {
                $query->where('currency', $filterCurrency);
            }

            $transactions = (clone $query)
                ->orderByDesc('deleted_at')
                ->orderByDesc('id')
                ->get();

            $displayCurrency = $filterCurrency ?? 'شيكل';
            $totals = $this->ledger->calculateDeletedTotals(
                $request->customer_id,
                $request->seller_id,
                $displayCurrency
            );

            $balancesByCurrency = $this->ledger->calculateDeletedBalancesByCurrency(
                $request->customer_id,
                $request->seller_id
            );

            return response()->json([
                'status' => 'success',
                'person' => $this->ledger->getPersonInfo($request->customer_id, $request->seller_id),
                'currency' => $displayCurrency,
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'balances' => $balancesByCurrency,
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
                'report_detail_level' => 'nullable|in:summary,detailed,detailed_with_images',
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
            $displayCurrency = $request->filled('currency')
                ? $this->ledger->normalizeCurrency($request->currency)
                : 'شيكل';
            $detailLevel = $request->input('report_detail_level', 'summary');

            $query = $this->ledger->baseQuery($request->customer_id, $request->seller_id);
            $this->ledger->applyDateFilter($query, $startDate, $endDate);
            $query->where('currency', $displayCurrency);

            $transactions = $query
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            $totals = $this->ledger->calculateTotals(
                $request->customer_id,
                $request->seller_id,
                $startDate,
                $endDate,
                $displayCurrency
            );

            $periodLabel = $this->formatPeriodLabel($request->period, $startDate, $endDate);
            $periodLabel = $periodLabel.' — '.$displayCurrency;

            $reportHtml = view('pdf.debt-ledger-report', [
                'person' => $person,
                'transactions' => $transactions,
                'total_taken' => $totals['total_taken'],
                'total_given' => $totals['total_given'],
                'balance' => $totals['balance'],
                'period_label' => $periodLabel,
                'currency' => $displayCurrency,
                'detail_level' => $detailLevel,
                'source_details' => $this->reportSourceDetails($transactions, $detailLevel),
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
                        'detail_level' => $detailLevel,
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

    private function reportSourceDetails($transactions, string $detailLevel): array
    {
        if ($detailLevel === 'summary') {
            return [];
        }

        $withImages = $detailLevel === 'detailed_with_images';
        $details = [];

        foreach ($transactions as $transaction) {
            $source = (string) ($transaction->source ?? '');
            $sourceId = (int) ($transaction->source_id ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            $detail = match ($source) {
                'sales_order' => $this->salesOrderReportDetail($sourceId, $withImages),
                'instant_sale' => $this->instantSaleReportDetail($sourceId, $withImages),
                'profit_sale' => $this->profitSaleReportDetail($sourceId, $withImages),
                'bill' => $this->billReportDetail($sourceId, $withImages),
                default => null,
            };

            if ($detail !== null) {
                $details[(int) $transaction->id] = $detail;
            }
        }

        return $details;
    }

    private function salesOrderReportDetail(int $orderId, bool $withImages): ?array
    {
        $order = SalesOrder::query()
            ->with([
                'items.product.normalImages',
                'items.size',
                'items.sizeColor',
            ])
            ->find($orderId);

        if (! $order) {
            return null;
        }

        return [
            'title' => 'تفاصيل طلبية البيع '.($order->serial_number ?: '#'.$order->id),
            'meta' => array_filter([
                'رقم الطلبية' => $order->serial_number ?: '#'.$order->id,
                'طريقة الدفع' => $order->payment_type,
                'الإجمالي' => $order->total,
                'الخصم' => $order->discount,
            ], fn ($value) => $value !== null && $value !== ''),
            'items' => $order->items
                ->where('is_hidden', false)
                ->map(fn ($item) => $this->formatReportItem(
                    $item->product_name ?: $item->product?->nameAr,
                    (float) $item->quantity,
                    (float) $item->unit_price,
                    (float) $item->line_total,
                    $withImages ? $this->productImagePath($item->product) : null
                ))
                ->values()
                ->all(),
        ];
    }

    private function instantSaleReportDetail(int $saleId, bool $withImages): ?array
    {
        $sale = InstantSale::query()
            ->with(['product.normalImages', 'size', 'sizeColor', 'subProducts.product.normalImages'])
            ->find($saleId);

        if (! $sale) {
            return null;
        }

        $rows = $sale->subProducts->isNotEmpty() ? $sale->subProducts : collect([$sale]);

        return [
            'title' => 'تفاصيل البيع الفوري #'.$sale->id,
            'meta' => array_filter([
                'الإجمالي' => $sale->total_cost,
                'المدفوع' => $sale->payment_box_value,
            ], fn ($value) => $value !== null && $value !== ''),
            'items' => $rows
                ->map(fn ($item) => $this->formatReportItem(
                    $item->product?->nameAr,
                    (float) ($item->quantity ?? 1),
                    (float) ($item->cost ?? 0),
                    (float) (($item->quantity ?? 1) * ($item->cost ?? 0)),
                    $withImages ? $this->productImagePath($item->product) : null
                ))
                ->values()
                ->all(),
        ];
    }

    private function profitSaleReportDetail(int $saleId, bool $withImages): ?array
    {
        $sale = ProfitSale::query()->find($saleId);

        if (! $sale) {
            return null;
        }

        return [
            'title' => 'تفاصيل البيع الربحي #'.$sale->id,
            'meta' => array_filter([
                'الإجمالي' => $sale->total_cost,
                'المدفوع' => $sale->payment_box_value,
                'ملاحظات' => $sale->notes,
            ], fn ($value) => $value !== null && $value !== ''),
            'items' => [],
        ];
    }

    private function billReportDetail(int $billId, bool $withImages): ?array
    {
        $bill = Bill::query()
            ->with(['seller', 'items.product.normalImages'])
            ->find($billId);

        if (! $bill) {
            return null;
        }

        return [
            'title' => 'تفاصيل فاتورة شراء #'.$bill->id,
            'meta' => array_filter([
                'المورد' => $bill->seller?->name,
                'الإجمالي' => $bill->total,
                'الخصم' => $bill->discount,
                'الحالة' => $bill->status,
            ], fn ($value) => $value !== null && $value !== ''),
            'items' => $bill->items
                ->map(fn ($item) => $this->formatReportItem(
                    $item->product?->nameAr,
                    (float) $item->quantity,
                    (float) $item->price,
                    (float) ($item->quantity * $item->price),
                    $withImages ? $this->productImagePath($item->product) : null
                ))
                ->values()
                ->all(),
        ];
    }

    private function formatReportItem(?string $name, float $quantity, float $unitPrice, float $lineTotal, ?string $imagePath = null): array
    {
        return [
            'name' => $name ?: 'منتج',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'image_path' => $imagePath,
        ];
    }

    private function productImagePath($product): ?string
    {
        $raw = $product?->normalImages?->first()?->imageUrl;
        if (! $raw || $raw === 'no image') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        $relative = ltrim(str_replace('\\', '/', $raw), '/');
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, 7);
        }

        foreach ([$relative, 'images/'.$relative, 'storage/'.$relative] as $candidate) {
            $path = public_path($candidate);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
                'currency' => 'nullable|string|in:شيكل,دولار,دينار',
            ]);

            if ($error = $this->ledger->validatePerson($request->customer_id, $request->seller_id)) {
                return response()->json(['status' => 'error', 'message' => $error], 200);
            }

            $currency = $request->filled('currency')
                ? $this->ledger->normalizeCurrency($request->currency)
                : null;

            return response()->json([
                'status' => 'success',
                'currency' => $currency ?? 'شيكل',
                'activity' => $this->ledger->getPersonActivity(
                    $request->customer_id,
                    $request->seller_id,
                    $currency
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
