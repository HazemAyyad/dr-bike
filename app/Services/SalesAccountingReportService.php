<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SalesAccountingReportService
{
    public function __construct(private SalesAccountingReportBuilder $builder) {}

    public function report(array $filters): array
    {
        $from = Carbon::parse($filters['from_date'])->startOfDay();
        $to = Carbon::parse($filters['to_date'])->endOfDay();
        $includeReturns = filter_var($filters['include_returns'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $filters['currency'] = $filters['currency'] ?? 'شيكل';
        $filters['cash_only'] = filter_var($filters['cash_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $filters['include_returns'] = $includeReturns;
        $filters['include_other_currencies'] = filter_var($filters['include_other_currencies'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $filters['sort'] = $filters['sort'] ?? (($filters['group_by'] ?? 'customer') === 'customer' ? 'sales_desc' : 'label');
        // Only roots carry the invoice total. Children must never be summed again.
        $sales = InstantSale::query()
            ->with(['buyerCustomer:id,name', 'seller:id,name', 'paymentBox', 'createdByUser:id,name'])
            ->whereNull('parent_id')->whereNull('maintenance_id')
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereBetween('created_at', [$from, $to])
            ->get()->map(function (InstantSale $sale) {
                return array_merge($this->person(
                    $sale->buyer_id, $sale->seller_id,
                    $sale->buyerCustomer?->name ?: $sale->seller?->name ?: $sale->buyer_name
                ), ['date' => $sale->created_at, 'total' => (float) $sale->total_cost, 'discount' => (float) $sale->discount,
                    'currency' => $sale->paymentBox?->currency ?: 'شيكل', 'representative_id' => $sale->created_by,
                    'representative_name' => $sale->createdByUser?->name,
                    'cash_only' => $sale->payment_box_value !== null && (float) $sale->payment_box_value >= (float) $sale->total_cost && (float) $sale->total_cost > 0,
                ]);
            });

        if (Schema::hasTable('profit_sales')) {
            $sales = $sales->concat(ProfitSale::query()
                ->with(['customer:id,name', 'seller:id,name', 'paymentBox', 'createdByUser:id,name'])
                ->whereNull('cancelled_at')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
                ->whereBetween('created_at', [$from, $to])->get()
                ->map(fn (ProfitSale $sale) => array_merge($this->person(
                    $sale->customer_id, $sale->seller_id,
                    $sale->customer?->name ?: $sale->seller?->name ?: $sale->buyer_name
                ), ['date' => $sale->created_at, 'total' => (float) $sale->total_cost, 'discount' => 0.0,
                    'currency' => $sale->paymentBox?->currency ?: 'شيكل', 'representative_id' => $sale->created_by,
                    'representative_name' => $sale->createdByUser?->name,
                    'cash_only' => $sale->payment_box_value !== null && (float) $sale->payment_box_value >= (float) $sale->total_cost && (float) $sale->total_cost > 0,
                ])));
        }

        $sales = $sales->concat($this->orderSales($from, $to));

        $returns = collect();
        if ($includeReturns) {
            $returns = SalesReturn::query()->with(['customer:id,name', 'seller:id,name', 'items', 'instantSale.createdByUser:id,name', 'instantSale.parentSale.createdByUser:id,name',
                'salesOrder.customer:id,name', 'salesOrder.partnerCustomer:id,name', 'salesOrder.partnerSeller:id,name', 'salesOrder.createdByUser:id,name'])
                ->where('status', 'completed')->whereNull('cancelled_at')
                ->whereIn('return_type', ['direct', 'partial'])
                ->whereBetween('completed_at', [$from, $to])->get()
                ->map(function (SalesReturn $return) {
                    // original_unit_price is a snapshot AFTER the original invoice discount.
                    // This is only the extra discount at refund time, not a second invoice discount.
                    $complete = $return->return_type === 'partial' || ($return->items->isNotEmpty()
                        && $return->items->every(fn ($item) => $item->original_unit_price !== null));
                    $discount = $return->return_type === 'partial' ? 0.0 : (float) $return->items->sum(
                        fn ($item) => max(0, (float) $item->original_unit_price - (float) $item->unit_price) * (float) $item->quantity
                    );

                    $original = $return->instantSale?->parentSale ?: $return->instantSale ?: $return->salesOrder;
                    $person = $return->salesOrder ? $this->orderPerson($return->salesOrder) : $this->person(
                        $return->customer_id, $return->seller_id, $return->customer?->name ?: $return->seller?->name
                    );

                    return array_merge($person, ['date' => $return->completed_at, 'total' => (float) $return->total_amount, 'discount' => $complete ? $discount : null,
                        'currency' => $return->currency ?: 'شيكل', 'representative_id' => $original?->created_by,
                        'representative_name' => $original?->createdByUser?->name,
                        'cash_only' => (float) $return->total_amount > 0 && (float) $return->cash_refund_amount >= (float) $return->total_amount,
                    ]);
                });
        }

        $people = $sales->concat($returns)->unique('person_key')->map(fn ($row) => [
            'key' => $row['person_key'], 'name' => $row['person_name'], 'type' => $row['person_type'],
        ])->sortBy('name')->values();
        $representatives = $sales->concat($returns)->filter(fn ($row) => ! empty($row['representative_id']))
            ->unique('representative_id')->map(fn ($row) => [
                'key' => (string) $row['representative_id'],
                'name' => $row['representative_name'] ?: 'موظف #'.$row['representative_id'],
            ])->sortBy('name')->values();
        if (! empty($filters['person_key'])) {
            $sales = $sales->where('person_key', $filters['person_key']);
            $returns = $returns->where('person_key', $filters['person_key']);
        }

        $currencyReports = $this->builder->byCurrency($sales->all(), $returns->all(), $filters);

        return array_merge($currencyReports[0], [
            'currency_reports' => $currencyReports,
            'filters' => [
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
                'group_by' => $filters['group_by'] ?? 'customer', 'include_returns' => $includeReturns,
                'person_key' => $filters['person_key'] ?? '', 'sort' => $filters['sort'] ?? 'sales_desc',
                'currency' => $filters['currency'], 'min_amount' => $filters['min_amount'] ?? null, 'max_amount' => $filters['max_amount'] ?? null,
                'cash_only' => $filters['cash_only'],
                'include_other_currencies' => $filters['include_other_currencies'],
                'representative_id' => isset($filters['representative_id']) ? (string) $filters['representative_id'] : '',
            ],
            'currency' => $filters['currency'],
            'people' => $people,
            'representatives' => $representatives,
            'notes' => [
                'المبيعات تشمل فواتير البيع والبيع الربحي والطلبيات حسب تاريخ ترحيلها المالي. تُستبعد الصيانة والمبيعات الملغاة وطلبات تحصيل الدين.',
                'نطاق القيمة يطبق على كل عملية بيع ومرتجع قبل التجميع.',
                'خيار نقدي يعرض المبيعات المدفوعة بالكامل والمرتجعات المستردة نقدًا بالكامل؛ يستبعد الديون والدفعات الجزئية.',
                'كل عملة لها كشف وإجماليات ونسب مستقلة؛ لا تُحوّل العملات ولا تُجمع في إجمالي موحّد. نطاق القيمة يطبق بعملة الحركة نفسها.',
                'المندوب هو موظف المبيعات الذي سجّل الفاتورة أو الطلبية الأصلية. الحركات غير المنسوبة لموظف تظهر ضمن الكل فقط.',
                'حصة المبيعات من إجمالي المبيعات بعد الخصم لنفس الفلاتر. نسبة المرتجع من مبيعات الصف نفسه؛ عند عدم وجود مبيعات تظهر —.',
                'المرتجعات حسب تاريخ اعتمادها، وقد تخص مبيعات من فترة سابقة. الصافي = المبيعات بعد الخصم − المرتجعات.',
            ],
        ]);
    }

    private function orderSales(Carbon $from, Carbon $to): Collection
    {
        return SalesOrder::query()
            ->with(['customer:id,name', 'partnerCustomer:id,name', 'partnerSeller:id,name', 'createdByUser:id,name'])
            ->whereBetween('financial_posted_at', [$from, $to])
            ->where('status', '!=', SalesOrderStatus::Canceled->value)
            ->where('is_debt_collection', false)
            // Keep historical orders already represented by their original sale invoice out of this source.
            ->whereNull('instant_sale_id')
            ->whereNotExists(fn ($query) => $query->from('instant_sales')
                ->whereColumn('instant_sales.sales_order_id', 'sales_orders.id')
                ->whereNull('instant_sales.parent_id'))
            ->get()
            ->map(fn (SalesOrder $order) => array_merge($this->orderPerson($order), [
                'date' => $order->financial_posted_at,
                'total' => (float) $order->total,
                'discount' => (float) $order->discount,
                'currency' => 'شيكل',
                'representative_id' => $order->created_by,
                'representative_name' => $order->createdByUser?->name,
                'cash_only' => $order->total > 0 && $order->payment_amount >= $order->total,
            ]));
    }

    private function orderPerson(SalesOrder $order): array
    {
        if ($order->partner_type === 'seller' && $order->partner_id) {
            return $this->person(null, $order->partner_id, $order->partnerSeller?->name ?: $order->customer_name);
        }

        $partnerCustomer = $order->partner_type === 'customer' && $order->partner_id;

        return $this->person(
            $partnerCustomer ? $order->partner_id : $order->customer_id,
            null,
            ($partnerCustomer ? $order->partnerCustomer?->name : $order->customer?->name) ?: $order->customer_name,
        );
    }

    private function person($customerId, $sellerId, ?string $name): array
    {
        $type = $sellerId ? 'seller' : ($customerId ? 'customer' : 'unknown');
        $id = $sellerId ?: $customerId;
        $name = trim((string) $name);

        return [
            'person_key' => $id ? $type.':'.$id : ($name !== '' ? 'named:'.$name : 'cash'),
            'person_name' => $name !== '' ? $name : ($id ? 'حساب #'.$id : 'زبون نقدي'),
            'person_type' => $type,
        ];
    }
}
