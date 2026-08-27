<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesOrder;
use App\Services\DebtLedgerService;
use Illuminate\Support\Facades\Cache;

class DebtLedgerShareWebController extends Controller
{
    public function __construct(private DebtLedgerService $ledger)
    {
    }

    public function show(string $token)
    {
        $payload = Cache::get('debt_ledger_share:' . $token);

        if (! is_array($payload)) {
            abort(404);
        }

        $customerId = $payload['customer_id'] ?? null;
        $sellerId = $payload['seller_id'] ?? null;
        $detailLevel = $payload['report_detail_level'] ?? 'summary';
        $startDate = $payload['start_date'] ?? null;
        $endDate = $payload['end_date'] ?? null;
        $currency = $this->ledger->normalizeCurrency($payload['currency'] ?? null);

        $person = $this->ledger->getPersonInfo($customerId, $sellerId);
        $transactionsQuery = $this->ledger->baseQuery($customerId, $sellerId)
            ->where('currency', $currency);
        $this->ledger->applyDateFilter($transactionsQuery, $startDate, $endDate);
        $transactions = $transactionsQuery
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $totals = $this->ledger->calculateTotals(
            $customerId,
            $sellerId,
            $startDate,
            $endDate,
            $currency
        );

        return view('debt-ledger.public-share', [
            'person' => $person,
            'transactions' => $transactions,
            'total_taken' => $totals['total_taken'],
            'total_given' => $totals['total_given'],
            'balance' => $totals['balance'],
            'currency' => $currency,
            'period_label' => $startDate && $endDate
                ? ($startDate === $endDate ? $startDate : $startDate.' إلى '.$endDate)
                : 'جميع المعاملات',
            'detail_level' => $detailLevel,
            'source_details' => $this->reportSourceDetails($transactions, $detailLevel),
        ]);
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
                'profit_sale' => $this->profitSaleReportDetail($sourceId),
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
            ->with(['items.product.normalImages', 'items.size', 'items.sizeColor'])
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
                    $withImages ? $this->productImageUrl($item->product) : null
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
                    $withImages ? $this->productImageUrl($item->product) : null
                ))
                ->values()
                ->all(),
        ];
    }

    private function profitSaleReportDetail(int $saleId): ?array
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
                    $withImages ? $this->productImageUrl($item->product) : null
                ))
                ->values()
                ->all(),
        ];
    }

    private function formatReportItem(?string $name, float $quantity, float $unitPrice, float $lineTotal, ?string $imageUrl = null): array
    {
        return [
            'name' => $name ?: 'منتج',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'image_url' => $imageUrl,
        ];
    }

    private function productImageUrl($product): ?string
    {
        $raw = $product?->normalImages?->first()?->imageUrl;
        if (! $raw || $raw === 'no image') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        return asset(ltrim(str_replace('\\', '/', $raw), '/'));
    }
}
