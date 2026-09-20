<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountingAccount;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use App\Services\AccountingReconciliationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingReportService $reports,
        private AccountingPeriodService $periods,
        private AccountingReconciliationService $reconciliation,
    ) {}

    public function accounts()
    {
        return response()->json([
            'status' => 'success',
            'data' => AccountingAccount::query()
                ->with('parent:id,code,name_ar')
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:accounting_accounts,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'contra_asset', 'liability', 'equity', 'revenue', 'contra_revenue', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'parent_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'is_control' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = true;

        return response()->json(['status' => 'success', 'data' => AccountingAccount::create($data)], 201);
    }

    public function updateAccount(Request $request, int $id)
    {
        $account = AccountingAccount::query()->findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:24', Rule::unique('accounting_accounts', 'code')->ignore($account->id)],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:accounting_accounts,id', Rule::notIn([$account->id])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($account->system_key && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['لا يمكن تعطيل حساب نظامي مرتبط بالترحيل التلقائي.'],
            ]);
        }
        $account->update($data);

        return response()->json(['status' => 'success', 'data' => $account->fresh()]);
    }

    public function periods()
    {
        return response()->json([
            'status' => 'success',
            'data' => AccountingPeriod::query()->with('closedBy:id,name')->latest('starts_at')->get(),
        ]);
    }

    public function storePeriod(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->periods->create($data['name'], $data['starts_at'], $data['ends_at']),
        ], 201);
    }

    public function closePeriod(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $period = AccountingPeriod::query()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $this->periods->close($period, $request->user()?->id, $data['note'] ?? null),
        ]);
    }

    public function postManualJournal(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'currency' => ['required', Rule::in(['شيكل', 'دولار', 'دينار', 'NIS', 'ILS', 'USD', 'JOD'])],
            'description' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'lines.*.seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'lines.*.delivery_company_id' => ['nullable', 'integer', 'exists:delivery_companies,id'],
            'lines.*.box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.due_date' => ['nullable', 'date'],
        ]);
        $sourceId = random_int(1_000_000_000, 9_000_000_000);
        $key = 'manual:'.Str::ulid();
        $entry = $this->accounting->post(
            $key,
            'manual',
            $sourceId,
            $data['entry_date'],
            $data['currency'],
            $data['description'],
            $data['lines'],
            ['manual' => true],
            $request->user()?->id,
        );

        return response()->json(['status' => 'success', 'data' => $entry], 201);
    }

    public function reverseJournal(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'entry_date' => ['nullable', 'date'],
        ]);
        $entry = AccountingJournalEntry::query()->findOrFail($id);
        if ($entry->reverses_entry_id) {
            abort(422, 'لا يمكن عكس قيد عكسي مباشرة.');
        }
        $reversal = $this->accounting->reverseEntryById(
            (int) $entry->id,
            $data['entry_date'] ?? now(),
            $data['reason'],
            $request->user()?->id,
        );

        return response()->json(['status' => 'success', 'data' => $reversal]);
    }

    public function journal(Request $request)
    {
        [$from, $to, $currency] = $this->filters($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->reports->journal($from, $to, $currency, (int) $request->input('per_page', 50)),
            'quality' => $this->reports->quality(),
        ]);
    }

    public function trialBalance(Request $request)
    {
        [$from, $to, $currency] = $this->filters($request);

        return response()->json(['status' => 'success', 'data' => $this->reports->trialBalance($from, $to, $currency)]);
    }

    public function generalLedger(Request $request)
    {
        $request->validate(['account_id' => ['required', 'integer', 'exists:accounting_accounts,id']]);
        [$from, $to, $currency] = $this->filters($request);

        return response()->json(['status' => 'success', 'data' => $this->reports->generalLedger((int) $request->account_id, $from, $to, $currency)]);
    }

    public function incomeStatement(Request $request)
    {
        [$from, $to, $currency] = $this->filters($request);

        return response()->json(['status' => 'success', 'data' => $this->reports->incomeStatement($from, $to, $currency)]);
    }

    public function balanceSheet(Request $request)
    {
        [, $to, $currency] = $this->filters($request);

        return response()->json(['status' => 'success', 'data' => $this->reports->balanceSheet($to, $currency)]);
    }

    public function cashFlow(Request $request)
    {
        [$from, $to, $currency] = $this->filters($request);

        return response()->json(['status' => 'success', 'data' => $this->reports->cashFlow($from, $to, $currency)]);
    }

    public function aging(Request $request)
    {
        $request->validate(['kind' => ['nullable', Rule::in(['receivable', 'payable'])]]);
        [, $to, $currency] = $this->filters($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->reports->aging($to, $request->input('kind', 'receivable'), $currency),
        ]);
    }

    public function quality()
    {
        return response()->json(['status' => 'success', 'data' => $this->reports->quality()]);
    }

    public function reconciliation()
    {
        return response()->json(['status' => 'success', 'data' => $this->reconciliation->reconcile()]);
    }

    /** @return array{0:Carbon,1:Carbon,2:?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'currency' => ['nullable', Rule::in(['شيكل', 'دولار', 'دينار', 'NIS', 'ILS', 'USD', 'JOD'])],
        ]);
        $from = Carbon::parse($data['from_date'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to_date'] ?? now())->endOfDay();
        // Financial statements must never combine nominal amounts from
        // different currencies. Default to the shop's primary currency.
        $currency = $this->accounting->normalizeCurrency($data['currency'] ?? 'شيكل');

        return [$from, $to, $currency];
    }
}
