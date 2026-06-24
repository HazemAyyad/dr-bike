<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\InstantSale;
use App\Models\Maintenance;
use App\Models\MaintenanceProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MaintenanceDeliveryService
{
    public function __construct(
        protected SalesDailySessionService $sessionService,
        protected ProductStockService $stockService,
        protected DebtLedgerService $debtLedgerService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formatProductsSummary(Maintenance $maintenance): array
    {
        $maintenance->loadMissing(['products.product:id,nameAr,nameEng']);

        $items = $maintenance->products->map(function (MaintenanceProduct $item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->nameAr ?? $item->product?->nameEng ?? '-',
                'size_id' => $item->size_id,
                'size_color_id' => $item->size_color_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => round((float) $item->unit_price, 2),
                'line_total' => round((float) $item->line_total, 2),
            ];
        })->values()->all();

        $partsTotal = round($maintenance->products->sum('line_total'), 2);
        $laborCost = round((float) $maintenance->labor_cost, 2);
        $discount = round((float) $maintenance->discount, 2);
        $invoiceTotal = max(0, round($partsTotal + $laborCost - $discount, 2));

        return [
            'items' => $items,
            'parts_total' => $partsTotal,
            'labor_cost' => $laborCost,
            'discount' => $discount,
            'invoice_total' => $invoiceTotal,
            'paid_amount' => round((float) $maintenance->paid_amount, 2),
            'instant_sale_id' => $maintenance->instant_sale_id,
            'serial_number' => $maintenance->instantSale?->serial_number,
        ];
    }

    public function recalculateTotals(Maintenance $maintenance): Maintenance
    {
        $maintenance->loadMissing('products');
        $partsTotal = round($maintenance->products->sum('line_total'), 2);
        $invoiceTotal = max(
            0,
            round($partsTotal + (float) $maintenance->labor_cost - (float) $maintenance->discount, 2)
        );

        $maintenance->update(['invoice_total' => $invoiceTotal]);

        return $maintenance->fresh(['products.product']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function syncProducts(Maintenance $maintenance, array $items, ?float $laborCost = null, ?float $discount = null): Maintenance
    {
        if ($maintenance->status === 'delivered') {
            throw ValidationException::withMessages([
                'maintenance' => [__('messages.maintenance_already_delivered')],
            ]);
        }

        return DB::transaction(function () use ($maintenance, $items, $laborCost, $discount) {
            $maintenance = Maintenance::lockForUpdate()->findOrFail($maintenance->id);
            $maintenance->products()->delete();

            foreach ($items as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $quantity = max(1, (int) round((float) ($row['quantity'] ?? 1)));
                $unitPrice = max(0, (float) ($row['unit_price'] ?? 0));
                $lineTotal = round($quantity * $unitPrice, 2);

                MaintenanceProduct::create([
                    'maintenance_id' => $maintenance->id,
                    'product_id' => $productId,
                    'size_id' => isset($row['size_id']) ? (int) $row['size_id'] : null,
                    'size_color_id' => isset($row['size_color_id']) ? (int) $row['size_color_id'] : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);
            }

            $updates = [];
            if ($laborCost !== null) {
                $updates['labor_cost'] = max(0, round($laborCost, 2));
            }
            if ($discount !== null) {
                $updates['discount'] = max(0, round($discount, 2));
            }
            if ($updates !== []) {
                $maintenance->update($updates);
            }

            return $this->recalculateTotals($maintenance);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{maintenance: Maintenance, instant_sale: ?InstantSale}
     */
    public function deliver(Maintenance $maintenance, User $user, array $payload = []): array
    {
        return DB::transaction(function () use ($maintenance, $user, $payload) {
            $maintenance = Maintenance::lockForUpdate()
                ->with(['products.product', 'customer', 'seller'])
                ->findOrFail($maintenance->id);

            if ($maintenance->status === 'delivered') {
                throw ValidationException::withMessages([
                    'maintenance' => [__('messages.maintenance_already_delivered')],
                ]);
            }

            if ($maintenance->instant_sale_id) {
                throw ValidationException::withMessages([
                    'maintenance' => [__('messages.maintenance_invoice_already_created')],
                ]);
            }

            if (isset($payload['labor_cost'])) {
                $maintenance->labor_cost = max(0, round((float) $payload['labor_cost'], 2));
            }
            if (isset($payload['discount'])) {
                $maintenance->discount = max(0, round((float) $payload['discount'], 2));
            }

            $maintenance = $this->recalculateTotals($maintenance);
            $invoiceTotal = (float) $maintenance->invoice_total;

            if ($invoiceTotal <= 0 && $maintenance->products->isEmpty()) {
                $maintenance->update([
                    'status' => 'delivered',
                    'paid_amount' => 0,
                ]);

                return [
                    'maintenance' => $maintenance->fresh(['products.product', 'instantSale']),
                    'instant_sale' => null,
                ];
            }

            $paidAmount = isset($payload['payment_amount'])
                ? min((float) $payload['payment_amount'], $invoiceTotal)
                : $invoiceTotal;

            $paymentBox = $this->resolvePaymentBox($user, $paidAmount, $payload);
            $session = $this->sessionService->assertCanCreateSale($user);

            if ($paidAmount > 0 && $paymentBox) {
                $box = Box::lockForUpdate()->findOrFail($paymentBox['id']);
                $box->total = round((float) $box->total + $paidAmount, 2);
                $box->save();

                BoxLogs::createBoxLog(
                    $box,
                    'قبض — صيانة #'.$maintenance->id,
                    'add',
                    $paidAmount,
                    'صيانة #'.$maintenance->id
                );
            }

            $instantSale = $this->createInstantSaleFromMaintenance(
                $maintenance,
                $user,
                $session->id,
                $invoiceTotal,
                $paidAmount,
                $paymentBox
            );

            $maintenance->update([
                'status' => 'delivered',
                'paid_amount' => $paidAmount,
                'payment_box_id' => $paymentBox['id'] ?? null,
                'instant_sale_id' => $instantSale->id,
                'invoice_total' => $invoiceTotal,
            ]);

            return [
                'maintenance' => $maintenance->fresh(['products.product', 'instantSale']),
                'instant_sale' => $instantSale->fresh(['product', 'subProducts.product']),
            ];
        });
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function resolvePaymentBox(User $user, float $paidAmount, array $payload): ?array
    {
        if ($paidAmount <= 0) {
            return null;
        }

        if (! empty($payload['payment_box_id'])) {
            $box = Box::query()->find($payload['payment_box_id']);
            if ($box) {
                return ['id' => (int) $box->id, 'name' => (string) $box->name];
            }
        }

        $dailyBox = $this->sessionService->ensureDailyBoxes($user)->first();

        return $dailyBox
            ? ['id' => (int) $dailyBox->id, 'name' => (string) $dailyBox->name]
            : null;
    }

    /**
     * @param  array{id: int, name: string}|null  $paymentBox
     */
    private function createInstantSaleFromMaintenance(
        Maintenance $maintenance,
        User $user,
        int $sessionId,
        float $invoiceTotal,
        float $paidAmount,
        ?array $paymentBox
    ): InstantSale {
        $buyer = $this->resolveBuyer($maintenance);
        $products = $maintenance->products->values();
        $laborCost = round((float) $maintenance->labor_cost, 2);
        $discount = round((float) $maintenance->discount, 2);
        $notes = 'صيانة #'.$maintenance->id
            .($laborCost > 0 ? ' | أجرة صيانة: '.$laborCost : '');

        $additionalNotes = [];
        if ($laborCost > 0 && $products->isNotEmpty()) {
            $additionalNotes[] = [
                'text' => 'أجرة صيانة',
                'amount' => $laborCost,
            ];
        }

        if ($products->isEmpty()) {
            $main = InstantSale::create($this->sanitizeInstantSaleAttributes([
                'product_id' => null,
                'quantity' => 1,
                'cost' => $laborCost > 0 ? $laborCost : $invoiceTotal,
                'discount' => $discount,
                'total_cost' => $invoiceTotal,
                'notes' => $notes,
                'type' => 'normal',
                'buyer_type' => $buyer['buyer_type'],
                'buyer_id' => $buyer['buyer_id'],
                'buyer_name' => $buyer['buyer_name'],
                'buyer_phone' => $buyer['buyer_phone'],
                'seller_id' => $buyer['seller_id'],
                'payment_box_id' => $paymentBox['id'] ?? null,
                'payment_box_name' => $paymentBox['name'] ?? null,
                'payment_box_value' => $paidAmount,
                'sales_daily_session_id' => $sessionId,
                'created_by' => $user->id,
                'status' => 'active',
                'maintenance_id' => $maintenance->id,
                'additional_notes' => $additionalNotes,
            ]));

            app(DocumentSerialService::class)->assignToModel(
                $main,
                DocumentSerialService::TYPE_INSTANT_SALE_INVOICE,
                'serial_number'
            );

            $this->debtLedgerService->syncInstantSaleToLedger(
                $main->fresh(['product', 'offerPackage', 'paymentBox'])
            );

            return $main;
        }

        $first = $products->first();
        $firstProduct = Product::with('sizes.colorSizes')->findOrFail($first->product_id);
        $firstQty = (int) $first->quantity;
        $firstSizeColorId = $first->size_color_id ? (int) $first->size_color_id : null;

        $stockCheck = $this->stockService->validateSaleStock($firstProduct, $firstQty, $firstSizeColorId);
        if (! ($stockCheck['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'products' => [$stockCheck['message'] ?? __('messages.cant_sale')],
            ]);
        }

        $resolvedSizeColorId = (int) ($stockCheck['size_color_id'] ?? $firstSizeColorId ?: 0);

        foreach ($products->slice(1) as $line) {
            $product = Product::with('sizes.colorSizes')->findOrFail($line->product_id);
            $lineQty = (int) $line->quantity;
            $lineSizeColorId = $line->size_color_id ? (int) $line->size_color_id : null;
            $lineCheck = $this->stockService->validateSaleStock($product, $lineQty, $lineSizeColorId);
            if (! ($lineCheck['ok'] ?? false)) {
                throw ValidationException::withMessages([
                    'products' => [$lineCheck['message'] ?? __('messages.cant_sale')],
                ]);
            }
        }

        $mainAttributes = $this->sanitizeInstantSaleAttributes([
            'product_id' => $first->product_id,
            'size_id' => $first->size_id ?? ($stockCheck['size_id'] ?? null),
            'size_color_id' => $resolvedSizeColorId > 0 ? $resolvedSizeColorId : null,
            'quantity' => $firstQty,
            'cost' => (float) $first->unit_price,
            'discount' => $discount,
            'total_cost' => $invoiceTotal,
            'notes' => $notes,
            'type' => 'normal',
            'buyer_type' => $buyer['buyer_type'],
            'buyer_id' => $buyer['buyer_id'],
            'buyer_name' => $buyer['buyer_name'],
            'buyer_phone' => $buyer['buyer_phone'],
            'seller_id' => $buyer['seller_id'],
            'payment_box_id' => $paymentBox['id'] ?? null,
            'payment_box_name' => $paymentBox['name'] ?? null,
            'payment_box_value' => $paidAmount,
            'sales_daily_session_id' => $sessionId,
            'created_by' => $user->id,
            'status' => 'active',
            'maintenance_id' => $maintenance->id,
            'additional_notes' => $additionalNotes,
        ]);

        $main = InstantSale::create($mainAttributes);

        app(DocumentSerialService::class)->assignToModel(
            $main,
            DocumentSerialService::TYPE_INSTANT_SALE_INVOICE,
            'serial_number'
        );

        $this->stockService->deductForSale(
            product: $firstProduct,
            quantity: $firstQty,
            sizeColorId: $resolvedSizeColorId > 0 ? $resolvedSizeColorId : null,
            sizeId: isset($mainAttributes['size_id']) ? (int) $mainAttributes['size_id'] : null,
            referenceType: 'maintenance',
            referenceId: (int) $maintenance->id,
            userId: $user->id ? (int) $user->id : null,
        );

        foreach ($products->slice(1) as $line) {
            $product = Product::with('sizes.colorSizes')->findOrFail($line->product_id);
            $lineQty = (int) $line->quantity;
            $lineSizeColorId = $line->size_color_id ? (int) $line->size_color_id : null;
            $lineCheck = $this->stockService->validateSaleStock($product, $lineQty, $lineSizeColorId);
            $resolvedLineSizeColorId = (int) ($lineCheck['size_color_id'] ?? $lineSizeColorId ?: 0);

            InstantSale::create($this->sanitizeInstantSaleAttributes([
                'product_id' => $line->product_id,
                'size_id' => $line->size_id ?? ($lineCheck['size_id'] ?? null),
                'size_color_id' => $resolvedLineSizeColorId > 0 ? $resolvedLineSizeColorId : null,
                'quantity' => $lineQty,
                'cost' => (float) $line->unit_price,
                'discount' => 0,
                'total_cost' => (float) $line->line_total,
                'parent_id' => $main->id,
                'type' => 'normal',
                'buyer_type' => $buyer['buyer_type'],
                'buyer_id' => $buyer['buyer_id'],
                'buyer_name' => $buyer['buyer_name'],
                'buyer_phone' => $buyer['buyer_phone'],
                'seller_id' => $buyer['seller_id'],
                'sales_daily_session_id' => $sessionId,
                'created_by' => $user->id,
                'status' => 'active',
                'maintenance_id' => $maintenance->id,
            ]));

            $this->stockService->deductForSale(
                product: $product,
                quantity: $lineQty,
                sizeColorId: $resolvedLineSizeColorId > 0 ? $resolvedLineSizeColorId : null,
                sizeId: $line->size_id ? (int) $line->size_id : null,
                referenceType: 'maintenance',
                referenceId: (int) $maintenance->id,
                userId: $user->id ? (int) $user->id : null,
            );
        }

        $this->debtLedgerService->syncInstantSaleToLedger(
            $main->fresh(['product', 'offerPackage', 'paymentBox'])
        );

        return $main;
    }

    /**
     * @return array{buyer_type: string, buyer_id: ?int, buyer_name: string, buyer_phone: ?string, seller_id: ?int}
     */
    private function resolveBuyer(Maintenance $maintenance): array
    {
        if ($maintenance->customer_id) {
            return [
                'buyer_type' => 'customer',
                'buyer_id' => (int) $maintenance->customer_id,
                'buyer_name' => (string) ($maintenance->customer?->name ?? '-'),
                'buyer_phone' => $maintenance->customer?->phone,
                'seller_id' => null,
            ];
        }

        return [
            'buyer_type' => 'seller',
            'buyer_id' => null,
            'buyer_name' => (string) ($maintenance->seller?->name ?? '-'),
            'buyer_phone' => $maintenance->seller?->phone,
            'seller_id' => $maintenance->seller_id ? (int) $maintenance->seller_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeInstantSaleAttributes(array $data): array
    {
        $fillable = (new InstantSale)->getFillable();
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $fillable, true)) {
                continue;
            }
            if (! Schema::hasColumn('instant_sales', $key)) {
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
