<?php

namespace App\Services;

use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class SalesPurchasesExpensesReportService
{
    public function __construct(
        private SalesAccountingReportService $sales,
        private FinancialAccountingReportService $financial,
        private SalesAccountingReportBuilder $salesBuilder,
    ) {}

    public function report(array $filters): array
    {
        $sales = $this->sales->report($filters + ['group_by' => 'day', 'include_returns' => true]);
        $expenses = $this->financial->report('expenses', $filters);
        $salesByDay = array_column($sales['rows'], 'net_sales', 'key');
        $expensesByDay = [];
        foreach ($expenses['rows'] as $expense) {
            $date = $expense['date'];
            $expensesByDay[$date] = ($expensesByDay[$date] ?? 0) + $expense['debit'] - $expense['credit'];
        }

        $purchasesByDay = $this->purchasesByDay($filters);
        $rows = [];
        foreach (CarbonPeriod::create($filters['from_date'], $filters['to_date']) as $day) {
            $date = $day->toDateString();
            $rows[] = [
                'date' => $date,
                'sales' => round($salesByDay[$date] ?? 0, 2),
                'purchases' => round($purchasesByDay[$date] ?? 0, 2),
                'expenses' => round($expensesByDay[$date] ?? 0, 2),
            ];
        }

        $notes = [
            'المبيعات بعد الخصم ومردودات البيع المعتمدة، وتشمل الطلبيات بتاريخ ترحيلها المالي.',
            'المشتريات هي قيمة الكميات المقبولة في سندات الاستلام حسب تاريخ كل استلام، بما فيها الاستلامات الجزئية.',
            'المصاريف حسب كشف حركات المصاريف وتشمل الرواتب والمشاريع والإهلاك وقيود العكس.',
        ];
        if (! $expenses['quality']['ledger_available']) {
            $notes[] = 'المصاريف من السجلات التشغيلية المتوفرة لأن دفتر القيود غير متاح.';
        }

        return [
            'filters' => $filters,
            'rows' => $rows,
            'summary' => [
                'sales' => round(array_sum(array_column($rows, 'sales')), 2),
                'purchases' => round(array_sum(array_column($rows, 'purchases')), 2),
                'expenses' => round(array_sum(array_column($rows, 'expenses')), 2),
            ],
            'notes' => $notes,
        ];
    }

    private function purchasesByDay(array $filters): array
    {
        $receiptDate = 'COALESCE(receipt.received_at, DATE(receipt.created_at))';
        $receipts = DB::table('purchase_receipts as receipt')
            ->join('purchase_receipt_items as item', 'item.purchase_receipt_id', '=', 'receipt.id')
            ->join('bills as bill', 'bill.id', '=', 'receipt.bill_id')
            ->whereBetween(DB::raw($receiptDate), [$filters['from_date'], $filters['to_date']])
            ->whereRaw("COALESCE(bill.status, '') <> ?", ['cancelled'])
            ->whereRaw("COALESCE(bill.workflow_status, '') <> ?", ['cancelled'])
            ->where('item.accepted_quantity', '>', 0)
            ->selectRaw("{$receiptDate} AS receipt_date, bill.currency, ROUND(SUM(item.accepted_quantity * item.unit_price), 4) AS amount")
            ->groupBy('receipt.id', 'bill.currency')->groupByRaw($receiptDate)
            ->get();

        $totals = [];
        foreach ($receipts as $receipt) {
            if ($this->salesBuilder->normalizeCurrency($receipt->currency) === $filters['currency']) {
                $date = $receipt->receipt_date;
                $totals[$date] = ($totals[$date] ?? 0) + (float) $receipt->amount;
            }
        }

        return $totals;
    }
}
