<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingJournalLine;
use App\Models\AssetLog;
use App\Models\Box;
use App\Models\Expense;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\ProjectExpense;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialAccountingReportService
{
    public function __construct(private FinancialAccountingReportBuilder $builder) {}

    public function report(string $type, array $filters): array
    {
        $filters = array_merge(['currency' => 'شيكل', 'category_key' => '', 'box_id' => '', 'include_opening' => false], $filters);
        $filters['include_opening'] = $type === 'expenses' && filter_var($filters['include_opening'], FILTER_VALIDATE_BOOLEAN);
        if ($type === 'income') {
            $filters['category_key'] = '';
            $filters['box_id'] = '';
        }
        $hasLedger = $this->hasLedger();
        $covered = $hasLedger ? AccountingJournalEntry::query()->whereIn('status', ['posted', 'reversed'])
            ->get(['source_type', 'source_id'])->mapWithKeys(fn ($e) => [$e->source_type.':'.$e->source_id => true])->all() : [];
        $boxes = Box::query()->get(['id', 'name'])->keyBy('id');
        $expenseModels = Expense::query()->with('box')->get()->keyBy('id');
        $ledger = $hasLedger ? $this->ledgerEvents($filters, $type, $expenseModels, $boxes) : [];
        $legacy = $this->legacyExpenses($expenseModels, $filters['to_date']);
        if ($type === 'income') {
            $legacy = array_merge($legacy, $this->legacyTrading($filters, $covered));
        }
        $events = $this->builder->merge($ledger, $legacy, $covered);
        $events = array_filter($events, fn ($row) => $row['date'] <= $filters['to_date']);
        $options = collect($events)->where('section', 'expense')->where('currency', $filters['currency']);
        $categories = $options->unique('category_key')->map(fn ($r) => ['key' => $r['category_key'], 'name' => $r['category_name']])->sortBy('name')->values();
        $boxOptions = $options->filter(fn ($r) => ! empty($r['box_id']))->unique('box_id')->map(fn ($r) => ['key' => (string) $r['box_id'], 'name' => $r['box_name']])->sortBy('name')->values();
        $payload = $type === 'expenses' ? $this->builder->expenses($events, $filters) : $this->builder->income($events, $filters);
        $notes = [
            'تُقرأ القيود المحاسبية المعتمدة مع قيود عكسها حسب تاريخ الحركة. لا تُضاف نسخة المصروف الأصلية إذا كان مرحّلًا للدفتر.',
            'يشمل الكشف المصاريف والرواتب ومصاريف المشاريع والإهلاك والقيود اليدوية؛ تكلفة البضاعة المباعة تظهر مستقلة في قائمة الدخل.',
            'الرصيد المدور يخص ما قبل بداية الفترة بنفس العملة والمصروف والصندوق، ولا يدخل في إجمالي مصروف الفترة أو قائمة الدخل.',
            'الفلاتر لا تجمع العملات. المصروف غير الموزع على صندوق يبقى ضمن الكل ولا يُنسب إلى صندوق محدد.',
        ];
        if (! $hasLedger) {
            $notes[] = 'دفتر القيود غير متاح؛ النتائج من السجلات التشغيلية المتوفرة ولا تشمل قيودًا يدوية غير مسجلة.';
        }
        if (($payload['summary']['missing_cost_count'] ?? 0) > 0) {
            $notes[] = 'تكلفة بعض العمليات التاريخية غير محفوظة؛ تظهر التكلفة الكاملة والأرباح — بدل إعطاء ربح غير موثوق.';
        }

        return array_merge($payload, [
            'title' => $type === 'expenses' ? 'كشف حركات المصاريف' : 'قائمة الدخل المفصّلة',
            'type' => $type, 'filters' => $filters, 'currency' => $filters['currency'],
            'categories' => $categories, 'boxes' => $boxOptions, 'notes' => $notes,
            'quality' => ['ledger_available' => $hasLedger, 'cost_complete' => ($payload['summary']['missing_cost_count'] ?? 0) === 0],
        ]);
    }

    private function hasLedger(): bool
    {
        return Schema::hasTable('accounting_journal_entries') && Schema::hasTable('accounting_journal_lines') && Schema::hasTable('accounting_accounts');
    }

    private function ledgerEvents(array $filters, string $type, $expenses, $boxes): array
    {
        $accounts = AccountingAccount::query()->get(['id', 'parent_id', 'system_key'])->keyBy('id')->toArray();

        return AccountingJournalLine::query()->with(['account', 'entry.lines'])
            ->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])
                ->whereDate('entry_date', '<=', $filters['to_date'])
                ->when(! $filters['include_opening'], fn ($q) => $q->whereDate('entry_date', '>=', $filters['from_date'])))
            ->whereHas('account', fn ($q) => $q->whereIn('type', ['revenue', 'contra_revenue', 'expense']))
            ->when($type === 'expenses', fn ($q) => $q->whereHas('account', fn ($q) => $q->where('type', 'expense')
                ->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'cost_of_goods_sold'))))
            ->get()->map(function ($line) use ($expenses, $boxes, $accounts) {
                $entry = $line->entry;
                $expense = $entry->source_type === 'expense' ? $expenses->get($entry->source_id) : null;
                $name = $expense && $expense->expense_type !== 'salary' ? $expense->name : $line->account->name_ar;
                if ($line->account->system_key === 'salary_expense') {
                    $name = 'الرواتب والأجور';
                }
                $boxIds = $entry->lines->pluck('box_id')->filter()->unique();
                $boxId = $line->box_id ?: ($boxIds->count() === 1 ? $boxIds->first() : null);
                $section = $this->builder->sectionForAccount($line->account->type, (int) $line->account_id, $accounts);

                return $this->event('journal:'.$line->id, $entry->entry_date, $section, $name, (float) $line->debit, (float) $line->credit, $entry->currency, [
                    'order' => $entry->id, 'detail_key' => 'journal:'.$line->id, 'entry_number' => $entry->entry_number,
                    'description' => $line->description ?: $entry->description,
                    'notes' => $expense?->notes ?? '', 'box_id' => $boxId,
                    'box_name' => $boxId ? ($boxes->get($boxId)?->name ?? 'صندوق #'.$boxId) : 'غير موزع على صندوق',
                    'source_type' => $entry->source_type ?: 'manual', 'source_id' => $entry->source_id,
                ]);
            })->all();
    }

    private function legacyExpenses($expenses, string $to): array
    {
        $rows = [];
        foreach ($expenses as $expense) {
            $date = $expense->expense_date ?: $expense->created_at;
            if (Carbon::parse($date)->toDateString() > $to) {
                continue;
            }
            $coveredBy = ['expense:'.$expense->id];
            if ($expense->payment_method === 'carrier_withholding' && Schema::hasTable('sales_order_settlements') && Schema::hasColumn('sales_order_settlements', 'carrier_fee_expense_id')) {
                foreach (DB::table('sales_order_settlements')->where('carrier_fee_expense_id', $expense->id)->pluck('id') as $id) {
                    $coveredBy[] = 'sales_order_settlement:'.$id;
                }
            }
            $rows[] = $this->event('expense:'.$expense->id, $date, 'expense', $expense->expense_type === 'salary' ? 'الرواتب والأجور' : $expense->name,
                (float) $expense->price, 0, $expense->box?->currency ?: 'شيكل', [
                    'covered_by' => $coveredBy, 'order' => $expense->id, 'detail_key' => 'expense:'.$expense->id,
                    'entry_number' => 'EXP-'.$expense->id, 'description' => $expense->name, 'notes' => $expense->notes,
                    'box_id' => $expense->box_id, 'box_name' => $expense->box?->name ?: 'غير مرتبط بصندوق',
                ]);
        }
        if (Schema::hasTable('project_expenses')) {
            foreach (ProjectExpense::with('box')->get() as $expense) {
                $rows[] = $this->event('project:'.$expense->id, $expense->expense_date ?: $expense->created_at, 'expense', 'مصاريف المشاريع', (float) $expense->expenses, 0,
                    $expense->currency ?: $expense->box?->currency ?: 'شيكل', [
                        'covered_by' => ['project_expense:'.$expense->id], 'order' => $expense->id, 'detail_key' => 'project:'.$expense->id,
                        'entry_number' => 'PRJ-'.$expense->id, 'description' => 'مصروف مشروع #'.$expense->project_id, 'notes' => $expense->notes,
                        'box_id' => $expense->box_id, 'box_name' => $expense->box?->name ?: 'غير مرتبط بصندوق',
                    ]);
            }
        }
        if (Schema::hasTable('asset_logs') && Schema::hasColumn('asset_logs', 'depreciation_period')) {
            foreach (AssetLog::with('asset')->where('type', 'depreciate')->whereNotNull('depreciation_period')->get() as $log) {
                $rows[] = $this->event('depreciation:'.$log->id, $log->depreciation_period.'-01', 'expense', 'إهلاك الأصول', (float) $log->depreciation_amount, 0,
                    $log->asset?->currency ?: 'شيكل', ['covered_by' => ['asset_depreciation:'.$log->id], 'detail_key' => 'depreciation:'.$log->id, 'entry_number' => 'DEP-'.$log->id, 'description' => 'إهلاك أصل #'.$log->asset_id]);
            }
        }

        return $rows;
    }

    private function legacyTrading(array $filters, array $covered): array
    {
        $from = Carbon::parse($filters['from_date'])->startOfDay();
        $to = Carbon::parse($filters['to_date'])->endOfDay();
        $rows = [];
        foreach (InstantSale::with(['paymentBox', 'subProducts'])->whereNull('parent_id')->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))->whereBetween('created_at', [$from, $to])->get() as $sale) {
            $sources = ['instant_sale:'.$sale->id];
            if ($sale->sales_order_id) {
                $sources[] = 'sales_order:'.$sale->sales_order_id;
            }
            if (collect($sources)->contains(fn ($key) => isset($covered[$key]))) {
                continue;
            }
            $currency = $sale->paymentBox?->currency ?: 'شيكل';
            $rows[] = $this->event('sale:'.$sale->id, $sale->created_at, 'revenue', $sale->maintenance_id ? 'إيرادات الصيانة' : 'المبيعات بعد الخصم', 0, (float) $sale->total_cost, $currency);
            $lines = collect([$sale])->concat($sale->subProducts);
            $complete = $lines->every(fn ($line) => ! $line->product_id || $line->inventory_total_cost !== null);
            $rows[] = $this->event('sale-cost:'.$sale->id, $sale->created_at, 'cost', 'تكلفة المبيعات', (float) $lines->sum('inventory_total_cost'), 0, $currency, ['cost_complete' => $complete]);
        }
        foreach (SalesOrder::query()->whereNotNull('financial_posted_at')->where('is_debt_collection', false)
            ->where('status', '!=', 'canceled')->whereBetween('financial_posted_at', [$from, $to])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('instant_sales')->whereColumn('instant_sales.sales_order_id', 'sales_orders.id')->whereNull('instant_sales.parent_id'))
            ->get() as $order) {
            $extra = ['covered_by' => ['sales_order:'.$order->id]];
            $rows[] = $this->event('order:'.$order->id, $order->financial_posted_at, 'revenue', 'إيرادات الطلبيات', 0, (float) $order->total, 'شيكل', $extra);
            // There is no immutable cost on the order itself; never use its sale price as cost.
            $rows[] = $this->event('order-cost:'.$order->id, $order->financial_posted_at, 'cost', 'تكلفة المبيعات', 0, 0, 'شيكل', array_merge($extra, ['cost_complete' => false]));
        }
        if (Schema::hasTable('profit_sales')) {
            foreach (ProfitSale::with('paymentBox')->whereNull('cancelled_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))->whereBetween('created_at', [$from, $to])->get() as $sale) {
                $rows[] = $this->event('profit:'.$sale->id, $sale->created_at, 'revenue', 'البيع الربحي', 0, (float) $sale->total_cost, $sale->paymentBox?->currency ?: 'شيكل', ['covered_by' => ['profit_sale:'.$sale->id]]);
            }
        }
        foreach (SalesReturn::with('items')->where('status', 'completed')->whereNull('cancelled_at')->whereIn('return_type', ['direct', 'partial'])->whereBetween('completed_at', [$from, $to])->get() as $return) {
            $extra = ['covered_by' => ['sales_return:'.$return->id]];
            $rows[] = $this->event('return:'.$return->id, $return->completed_at, 'sales_returns', 'مردودات المبيعات', (float) $return->total_amount, 0, $return->currency ?: 'شيكل', $extra);
            $rows[] = $this->event('return-cost:'.$return->id, $return->completed_at, 'cost', 'تكلفة المبيعات', 0, (float) $return->items->sum('inventory_total_cost'), $return->currency ?: 'شيكل', array_merge($extra, [
                'cost_complete' => $return->items->isNotEmpty() && $return->items->every(fn ($item) => $item->inventory_total_cost !== null),
            ]));
        }

        return $rows;
    }

    private function event(string $key, $date, string $section, ?string $name, float $debit, float $credit, ?string $currency, array $extra = []): array
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name)) ?: 'مصروف غير مصنف';
        $currency = match (strtoupper(trim((string) $currency))) {
            'USD', 'DOLLAR', 'دولار' => 'دولار', 'JOD', 'DINAR', 'دينار' => 'دينار',
            'ILS', 'NIS', 'SHEKEL', 'شيكل', '' => 'شيكل', default => (string) $currency,
        };

        return array_merge([
            'key' => $key, 'date' => Carbon::parse($date)->toDateString(), 'section' => $section,
            'category_key' => $section.':'.sha1($name), 'category_name' => $name,
            'debit' => round($debit, 4), 'credit' => round($credit, 4), 'currency' => $currency,
            'order' => 0, 'detail_key' => null, 'entry_number' => '', 'description' => $name,
            'notes' => '', 'box_id' => null, 'box_name' => 'غير مرتبط بصندوق',
        ], $extra);
    }

    public function detail(string $kind, int $id): array
    {
        if ($kind === 'journal') {
            abort_unless($this->hasLedger(), 404);
            $line = AccountingJournalLine::with(['account', 'entry.lines.account'])->findOrFail($id);
            $entry = $line->entry;
            $boxes = Box::whereIn('id', $entry->lines->pluck('box_id')->filter())->pluck('name', 'id');

            return [
                'title' => 'تفاصيل القيد '.$entry->entry_number,
                'fields' => [
                    ['label' => 'التاريخ', 'value' => $entry->entry_date->toDateString()],
                    ['label' => 'الحالة', 'value' => $entry->status === 'reversed' ? 'تم عكسه بقيد مستقل' : 'معتمد'],
                    ['label' => 'البيان', 'value' => $entry->description],
                    ['label' => 'العملة', 'value' => $entry->currency],
                    ['label' => 'المصدر', 'value' => (match ($entry->source_type) {
                        'expense' => 'مصروف', 'project_expense' => 'مصروف مشروع',
                        'salary_payment' => 'دفعة راتب', 'asset_depreciation' => 'إهلاك أصل',
                        default => 'قيد محاسبي',
                    }).($entry->source_id ? ' #'.$entry->source_id : '')],
                ],
                'lines' => $entry->lines->map(fn ($l) => ['account' => $l->account?->name_ar ?: 'حساب محذوف', 'box' => $boxes[$l->box_id] ?? '—', 'debit' => $l->debit, 'credit' => $l->credit, 'description' => $l->description])->values(),
            ];
        }
        $model = match ($kind) {
            'expense' => Expense::with('box')->findOrFail($id),
            'project' => ProjectExpense::with('box')->findOrFail($id),
            'depreciation' => AssetLog::with('asset')->findOrFail($id),
            default => abort(404),
        };

        $attributes = $model->getAttributes();
        $box = $kind === 'depreciation' ? null : $model->box;

        return ['title' => 'تفاصيل الحركة #'.$id, 'fields' => [
            ['label' => 'البيان', 'value' => $attributes['name'] ?? ($kind === 'project' ? 'مصروف مشروع #'.$model->project_id : 'إهلاك أصل #'.$model->asset_id)],
            ['label' => 'التاريخ', 'value' => Carbon::parse($attributes['expense_date'] ?? ($kind === 'depreciation' ? $model->depreciation_period.'-01' : $model->created_at))->toDateString()],
            ['label' => 'الصندوق', 'value' => $box?->name ?: 'غير مرتبط بصندوق'],
            ['label' => 'المبلغ', 'value' => round((float) ($attributes['price'] ?? $attributes['expenses'] ?? $attributes['depreciation_amount'] ?? 0), 2)],
            ['label' => 'العملة', 'value' => $attributes['currency'] ?? $box?->currency ?? 'شيكل'],
            ['label' => 'ملاحظات', 'value' => $attributes['notes'] ?? '—'],
        ], 'lines' => []];
    }
}
