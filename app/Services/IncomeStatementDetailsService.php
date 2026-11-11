<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingCutover;
use App\Models\AccountingJournalEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IncomeStatementDetailsService
{
    public function build(array $income, array $events, array $filters, bool $hasLedger): array
    {
        $revenue = collect($events)->where('section', 'revenue')->where('currency', $filters['currency'])
            ->whereBetween('date', [$filters['from_date'], $filters['to_date']]);
        $sales = $revenue->filter(fn (array $row) => $row['is_sales_revenue'] ?? false);
        $otherRevenue = $revenue->reject(fn (array $row) => $row['is_sales_revenue'] ?? false);
        $netSalesBeforeReturns = $sales->sum('credit') - $sales->sum('debit');
        $discount = $this->salesDiscount($sales);
        $summary = $income['summary'];
        $netSales = $netSalesBeforeReturns - $summary['sales_returns'];
        $inventory = $hasLedger ? $this->inventory($filters) : [];

        $rows = [
            $this->row('sales', 'المبيعات قبل الخصم', $discount === null ? null : $netSalesBeforeReturns + $discount, credit: true),
        ];
        $deductions = array_filter($income['rows'], fn (array $row) => $row['section'] === 'sales_returns');
        if ($deductions === []) {
            $rows[] = $this->row('sales', 'مردودات المبيعات', 0);
        }
        foreach ($deductions as $deduction) {
            $rows[] = array_replace($deduction, ['section' => 'sales']);
        }
        $rows[] = $this->row('sales', 'خصم مسموح به', $discount);
        if ($discount === null) {
            $rows[] = $this->row('sales', 'المبيعات بعد الخصم', $netSalesBeforeReturns, credit: true);
        }
        $rows[] = $this->row('sales', 'صافي المبيعات', $netSales, credit: true, subtotal: true);

        $rows = array_merge($rows, [
            $this->row('cost', 'بضاعة أول المدة', $inventory['opening'] ?? null),
            $this->row('cost', 'المشتريات', $inventory['purchases'] ?? null),
            $this->row('cost', 'مردودات المشتريات', $inventory['returns'] ?? null, credit: true),
            $this->row('cost', 'خصم مكتسب', null, credit: true),
            $this->row('cost', 'بضاعة آخر المدة', $inventory['closing'] ?? null, credit: true),
            $this->row('cost', 'مصاريف مشتريات', null),
        ]);
        if (($inventory['other'] ?? 0) != 0) {
            $rows[] = $this->row('cost', 'تسويات وحركات مخزون أخرى', $inventory['other']);
        }
        $inventoryCost = $inventory['implied_cost'] ?? null;
        if ($inventoryCost !== null && $summary['cost'] !== null) {
            $difference = round($summary['cost'] - $inventoryCost, 2);
            if ($difference != 0) {
                $rows[] = $this->row('cost', 'فرق المطابقة مع تكلفة المبيعات المسجّلة', $difference);
            }
        }
        $rows[] = $this->row('cost', 'مجموع تكلفة المبيعات', $summary['cost'], subtotal: true);

        foreach ($otherRevenue->groupBy('category_name')->sortKeys() as $label => $movements) {
            $rows[] = $this->row('other_revenue', $label, $movements->sum('credit') - $movements->sum('debit'), credit: true);
        }
        $rows[] = $this->row('other_revenue', 'مجموع الإيرادات الأخرى', $otherRevenue->sum('credit') - $otherRevenue->sum('debit'), credit: true, subtotal: true);
        foreach ($income['rows'] as $row) {
            if ($row['section'] === 'expense') {
                $rows[] = $row;
            }
        }
        $rows[] = $this->row('expense', 'مجموع المصاريف', $summary['expenses'], subtotal: true);

        $notes = [
            'بضاعة أول وآخر المدة من رصيد حساب المخزون في دفتر الأستاذ. المشتريات ومردوداتها من قيود الاستلام والردّ المرحّلة للمخزون.',
            'تكلفة البضاعة المباعة تشمل تكلفة البونص المصروف مع فواتير البيع. تسويات المخزون وفرق المطابقة يوضّحان حركة المخزون، وصافي الربح يعتمد على تكلفة المبيعات المسجّلة.',
            '— يعني أن البند غير متاح من السجلات. الخصم المكتسب ومصاريف المشتريات يحتاجان قيودًا مخصصة لعرضهما بصورة مستقلة.',
        ];
        if (($inventory['opening'] ?? null) === null || ($inventory['closing'] ?? null) === null) {
            $notes[] = 'بعض أرصدة المخزون غير متاحة للفترة المختارة حسب تاريخ بدء دفتر الأستاذ وسجلات المخزون.';
        }
        if ($discount === null) {
            $notes[] = 'تعذّر مطابقة خصم بعض الفواتير مع نسخة القيد، لذلك تظهر المبيعات بعد الخصم كما سُجّلت.';
        }

        return ['rows' => $rows, 'notes' => $notes];
    }

    private function salesDiscount(Collection $sales): ?float
    {
        $discount = (float) $sales->sum('sales_discount');
        foreach (['instant_sale' => ['instant_sales', 'total_cost'], 'sales_order' => ['sales_orders', 'total']] as $source => [$table, $totalColumn]) {
            $entries = $sales->where('source_type', $source);
            if ($entries->isEmpty()) {
                continue;
            }
            $ids = $entries->pluck('source_id')->unique()->all();
            $documents = DB::table($table)->whereIn('id', $ids)->get(['id', 'discount', $totalColumn])->keyBy('id');
            $versions = AccountingJournalEntry::query()->where('source_type', $source)->whereIn('source_id', $ids)
                ->whereNull('reverses_entry_id')->groupBy('source_id')->selectRaw('source_id, COUNT(*) AS versions')->pluck('versions', 'source_id');

            foreach ($entries as $entry) {
                $id = $entry['source_id'];
                $document = $documents->get($id);
                $net = round($entry['credit'] - $entry['debit'], 4);
                if (! $document || (int) $versions->get($id) !== 1 || abs(abs($net) - (float) $document->{$totalColumn}) > 0.0001) {
                    return null;
                }
                $discount += $net < 0 ? -(float) $document->discount : (float) $document->discount;
            }
        }

        return $discount;
    }

    private function inventory(array $filters): array
    {
        $accounts = AccountingAccount::query()->get(['id', 'parent_id', 'system_key']);
        $inventoryIds = $this->accountIds($accounts, 'inventory');
        if ($inventoryIds === []) {
            return [];
        }
        $cutover = Schema::hasTable('accounting_cutovers') ? AccountingCutover::active()?->cutover_date?->toDateString() : null;
        $from = $filters['from_date'];
        $to = $filters['to_date'];
        if ($cutover !== null && $to < $cutover) {
            return [];
        }

        $lines = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('lines.account_id', $inventoryIds)->where('entries.currency', $filters['currency'])
            ->whereIn('entries.status', [AccountingJournalEntry::STATUS_POSTED, AccountingJournalEntry::STATUS_REVERSED]);

        $throughClosing = (clone $lines)->where('entries.entry_date', '<=', $to);
        if ($cutover === null && ! (clone $throughClosing)->exists()) {
            return [];
        }
        $closing = (float) $throughClosing->sum(DB::raw('lines.debit - lines.credit'));
        if ($cutover !== null && $from < $cutover) {
            return ['closing' => $closing];
        }

        $opening = (float) (clone $lines)->where(function ($query) use ($from) {
            $query->where('entries.entry_date', '<', $from)->orWhere(fn ($sameDay) => $sameDay
                ->where('entries.entry_date', $from)->where('entries.source_type', 'accounting_cutover'));
        })->sum(DB::raw('lines.debit - lines.credit'));
        $costLine = DB::table('accounting_journal_lines as cost_lines')->selectRaw('1')
            ->whereColumn('cost_lines.journal_entry_id', 'entries.id')
            ->whereIn('cost_lines.account_id', $this->accountIds($accounts, 'cost_of_goods_sold'))->limit(1);
        $movements = (clone $lines)->whereBetween('entries.entry_date', [$from, $to])
            ->select('entries.id', 'entries.source_type')->addSelect(['has_cost' => $costLine])
            ->selectRaw('SUM(lines.debit - lines.credit) AS amount')->groupBy('entries.id', 'entries.source_type')->get();
        $purchases = (float) $movements->where('source_type', 'purchase_receipt')->sum('amount');
        $returns = -(float) $movements->where('source_type', 'purchase_return')->sum('amount');
        $other = (float) $movements->whereNotIn('source_type', ['accounting_cutover', 'purchase_receipt', 'purchase_return'])
            ->whereNull('has_cost')->sum('amount');

        return [
            'opening' => $opening, 'closing' => $closing, 'purchases' => $purchases,
            'returns' => $returns, 'other' => $other,
            'implied_cost' => $opening + $purchases - $returns + $other - $closing,
        ];
    }

    private function accountIds(Collection $accounts, string $systemKey): array
    {
        $ids = $accounts->where('system_key', $systemKey)->pluck('id')->all();
        $parents = $ids;
        while ($parents !== []) {
            $parents = $accounts->whereIn('parent_id', $parents)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $parents);
        }

        return $ids;
    }

    private function row(string $section, string $label, ?float $value, bool $credit = false, bool $subtotal = false): array
    {
        $amount = $value === null ? null : round($value, 2);
        $signedDebit = $credit ? -($amount ?? 0) : ($amount ?? 0);

        return [
            'section' => $section, 'label' => $label, 'subtotal' => $subtotal,
            'debit' => $amount === null ? null : max(0, $signedDebit),
            'credit' => $amount === null ? null : max(0, -$signedDebit),
            'balance' => $amount,
        ];
    }
}
