<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesProfitByProductReportService
{
    public function report(array $documents, Collection $products, array $filters): array
    {
        $rows = [];
        foreach ($documents as $document) {
            $sign = str_starts_with($document['key'], 'sales_return:') ? -1 : 1;
            foreach ($document['lines'] as $line) {
                $id = $line['product_id'] ?? null;
                $product = $products->get($id);
                if (! $this->selected($product, $filters)) {
                    continue;
                }
                $key = $id ? 'product:'.$id : 'other:'.$line['product'];
                $rows[$key] ??= $this->emptyRow($id, $product, $line['product'], $line['product_code']);
                $row = &$rows[$key];
                // Returns retain positive line quantities in the invoice report.
                // Use the document type, rather than revenue, to sign bonus returns too.
                $row['sold_quantity'] += $id ? $sign * $line['quantity'] : 0;
                $row['bonus_quantity'] += $id ? $sign * $line['bonus'] : 0;
                $row['sales_total'] += $line['sales_total'];
                $row['discount'] += $line['discount'];
                $row['missing_cost_count'] += $line['cost_total'] === null ? 1 : 0;
                $row['cost_total'] += $line['cost_total'] ?? 0;
                unset($row);
            }
        }

        // Use accepted receipt quantities and the receipt date, matching the
        // existing sales/purchases/expenses report. Orders alone are not receipts.
        $receiptDate = 'COALESCE(receipt.received_at, DATE(receipt.created_at))';
        $purchases = DB::table('purchase_receipt_items as item')
            ->join('purchase_receipts as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
            ->join('bills as bill', 'bill.id', '=', 'receipt.bill_id')
            ->whereBetween(DB::raw($receiptDate), [$filters['from_date'], $filters['to_date']])
            ->whereRaw("COALESCE(bill.status, '') <> ?", ['cancelled'])
            ->whereRaw("COALESCE(bill.workflow_status, '') <> ?", ['cancelled'])
            ->where('item.accepted_quantity', '>', 0)
            ->get(['item.product_id', 'item.accepted_quantity', 'item.unit_price', 'bill.currency']);
        $currency = new SalesAccountingReportBuilder;
        foreach ($purchases as $purchase) {
            $product = $products->get($purchase->product_id);
            if ($currency->normalizeCurrency($purchase->currency) !== $filters['currency']
                || ! $this->selected($product, $filters)) {
                continue;
            }
            $key = 'product:'.$purchase->product_id;
            $rows[$key] ??= $this->emptyRow((int) $purchase->product_id, $product);
            $rows[$key]['purchased_quantity'] += (float) $purchase->accepted_quantity;
            $rows[$key]['missing_purchase_cost_count'] += $purchase->unit_price === null ? 1 : 0;
            $rows[$key]['purchase_total'] += (float) $purchase->accepted_quantity * (float) $purchase->unit_price;
        }

        foreach ($rows as &$row) {
            foreach (['sold_quantity', 'bonus_quantity', 'sales_total', 'discount', 'cost_total', 'purchased_quantity', 'purchase_total'] as $field) {
                $row[$field] = round($row[$field], 6);
            }
            if ($row['missing_cost_count'] > 0) {
                $row['cost_total'] = null;
            }
            if ($row['missing_purchase_cost_count'] > 0) {
                $row['purchase_total'] = null;
            }
            $row['profit'] = $row['cost_total'] === null ? null : round($row['sales_total'] - $row['cost_total'], 6);
            $row['profit_percent'] = $this->percentage($row['profit'], $row['cost_total']);
            $quantity = $row['sold_quantity'];
            $row['unit_cost'] = ! $row['is_service'] && $row['cost_total'] !== null && abs($quantity) > 0.000001
                ? round($row['cost_total'] / $quantity, 6) : null;
            $row['price'] = ! $row['is_service'] && abs($quantity) > 0.000001
                ? round($row['sales_total'] / $quantity, 6) : null;
        }
        unset($row);
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['sales_total'], $a['product']] <=> [$a['sales_total'], $b['product']]);
        $summary = $this->totals($rows);
        $summary['product_count'] = count(array_filter($rows, fn ($row) => ! $row['is_service']));
        $summary['missing_cost_count'] = array_sum(array_column($rows, 'missing_cost_count'));
        $summary['purchased_quantity'] = round(array_sum(array_column($rows, 'purchased_quantity')), 6);
        $summary['sold_quantity'] = round(array_sum(array_column($rows, 'sold_quantity')), 6);
        $summary['bonus_quantity'] = round(array_sum(array_column($rows, 'bonus_quantity')), 6);
        $summary['discount'] = round(array_sum(array_column($rows, 'discount')), 6);
        $summary['purchase_total'] = in_array(null, array_column($rows, 'purchase_total'), true)
            ? null : round(array_sum(array_column($rows, 'purchase_total')), 6);

        $top = array_values(array_filter($rows, fn ($row) => ! $row['is_service'] && $row['profit_percent'] !== null && $row['profit'] > 0));
        usort($top, fn ($a, $b) => [$b['profit_percent'], $b['profit'], $a['product']] <=> [$a['profit_percent'], $a['profit'], $b['product']]);
        $comparison = array_slice($rows, 0, 5);
        if (count($rows) > 5) {
            $comparison[] = ['product' => 'بقية الأصناف'] + $this->totals(array_slice($rows, 5));
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'charts' => ['top_products' => array_slice($top, 0, 5), 'comparison' => $comparison],
            'options' => [
                'categories' => DB::table('categories')->orderBy('nameAr')->get(['id', 'nameAr as name'])->all(),
                'store_sections' => DB::table('store_sections')->orderBy('name')->get(['id', 'name'])->all(),
            ],
            'notes' => [
                'الربح = صافي المبيعات بعد الخصم والمرتجعات − تكلفة المباع المسجلة. نسبة الربح على التكلفة = الربح ÷ القيمة المطلقة لتكلفة المباع × 100. عند تكلفة صفر تظهر —.',
                'تُجمع ألوان ومقاسات الصنف في سطر واحد. الكمية المباعة صافية بعد المرتجعات وتشمل البونص؛ تُعرض كمية البونص مستقلة، وتكلفتها ضمن تكلفة المباع.',
                'متوسطا سعر البيع وتكلفة القطعة = صافي المبيعات وتكلفة المباع ÷ صافي الكمية المباعة شامل البونص؛ عند صافي كمية صفر يظهر —. قد تختلف الأسعار والتكاليف بين الفواتير.',
                'المشتريات هي الكميات المقبولة في سندات الاستلام خلال الفترة حسب تاريخ الاستلام، بنفس عملة التقرير، دون طرح مردودات الشراء أو إضافة مصاريف الفاتورة. تظهر الأصناف المستلمة ولو لم تُبع؛ المشتريات مستقلة عن تكلفة المباع ولا تُطرح مرة أخرى من الربح.',
                'فلتر مصدر العملية يخص المبيعات؛ المشتريات حسب الفترة والعملة والمجموعة وقسم المخزن. التصنيف والموقع حسب بيانات الصنف الحالية، والمخزون عام دون أرصدة مستقلة لكل مستودع.',
                'الخدمات والإضافات تظهر مستقلة دون كميات بضاعة. عند اختيار مجموعة أو قسم مخزن تُستبعد البنود غير المرتبطة بصنف. السحوبات غير المفوترة خارج تقرير الربحية حسب الصنف.',
                'الربح قبل المصاريف والرواتب ورسوم التوصيل. — تعني تكلفة غير مكتملة أو نسبة غير قابلة للحساب؛ لا تُستخدم أسعار الشراء الحالية لتعويض تكلفة بيع مفقودة. تكلفة المخزون بالشيكل فقط.',
                'أعلى الأصناف ربحية مرتبة حسب نسبة الربح على التكلفة للأصناف ذات الربح الموجب والتكلفة المكتملة. المقارنة تعرض أكبر خمسة أصناف حسب صافي المبيعات وبقية الأصناف مجمعة.',
            ],
        ];
    }

    private function selected(?object $product, array $filters): bool
    {
        return (empty($filters['category_id']) || (string) ($product->category_id ?? '') === (string) $filters['category_id'])
            && (empty($filters['store_section_id']) || (string) ($product->store_section_id ?? '') === (string) $filters['store_section_id']);
    }

    private function emptyRow(?int $id, ?object $product, string $fallback = '', string $code = ''): array
    {
        return ['product_id' => $id, 'product_code' => $product?->product_code ?: $code,
            'product' => $product?->nameAr ?: ($product?->nameEng ?: ($fallback ?: 'صنف #'.$id)),
            'is_service' => $id === null, 'purchased_quantity' => 0.0, 'purchase_total' => 0.0,
            'sold_quantity' => 0.0, 'bonus_quantity' => 0.0, 'sales_total' => 0.0, 'discount' => 0.0,
            'cost_total' => 0.0, 'missing_cost_count' => 0, 'missing_purchase_cost_count' => 0];
    }

    private function totals(array $rows): array
    {
        $sales = round(array_sum(array_column($rows, 'sales_total')), 6);
        $cost = in_array(null, array_column($rows, 'cost_total'), true) ? null : round(array_sum(array_column($rows, 'cost_total')), 6);
        $profit = $cost === null ? null : round($sales - $cost, 6);

        return ['sales_total' => $sales, 'cost_total' => $cost, 'profit' => $profit,
            'profit_percent' => $this->percentage($profit, $cost)];
    }

    private function percentage(?float $profit, ?float $cost): ?float
    {
        return $profit !== null && $cost !== null && abs($cost) > 0.000001
            ? round($profit / abs($cost) * 100, 2) : null;
    }
}
