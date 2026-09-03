<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Http\Controllers\API\Logs;
use App\Models\Box;
use App\Models\Customer;
use App\Models\InstantSale;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(
        private ProductStockService $stock,
        private DebtLedgerService $ledger,
        private SalesDailySessionService $sessions,
        private DocumentSerialService $serials,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function people(?string $search = null): array
    {
        $term = trim((string) $search);
        $customers = Customer::query()
            ->where('is_canceled', false)
            ->where(function ($query) {
                $query->whereHas('instantSales', fn ($sales) => $this->activeInstantSales($sales))
                    ->orWhereHas('salesOrders', fn ($orders) => $orders->whereIn('status', $this->returnableOrderStatuses()));
            })
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'phone'])
            ->map(fn (Customer $person) => $this->personRow('customer', $person))
            ->values();

        $sellers = Seller::query()
            ->where('is_canceled', false)
            ->whereHas('instantSales', fn ($sales) => $this->activeInstantSales($sales))
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'phone'])
            ->map(fn (Seller $person) => $this->personRow('seller', $person))
            ->values();

        return $customers->concat($sellers)->sortBy('name')->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function availableItems(string $personType, int $personId): array
    {
        $this->assertPerson($personType, $personId);

        $instantRows = InstantSale::query()
            ->with(['product.normalImages', 'size:id,size', 'sizeColor:id,colorAr,sizeId', 'parentSale.subProducts'])
            ->whereNotNull('product_id')
            ->where($personType === 'customer' ? 'buyer_id' : 'seller_id', $personId)
            ->where($personType === 'customer' ? 'seller_id' : 'buyer_id', null)
            ->where(fn ($query) => $this->activeInstantSales($query))
            ->orderByDesc('created_at')
            ->get()
            ->map(function (InstantSale $line) {
                $sold = (int) round((float) $line->quantity);
                $returned = (int) SalesReturnItem::query()->where('instant_sale_id', $line->id)->sum('quantity');
                $available = max(0, $sold - $returned);
                $root = $line->parent_id ? $line->parentSale : $line;

                return $this->availableRow(
                    'instant_sale',
                    (int) $line->id,
                    (int) $root->id,
                    (string) ($root->serial_number ?: '#'.$root->id),
                    $line,
                    $sold,
                    $returned,
                    $available,
                    $this->instantEffectiveUnitPrice($line),
                    $root->created_at?->toDateTimeString()
                );
            });

        $orderRows = collect();
        if ($personType === 'customer') {
            $orderRows = SalesOrderItem::query()
                ->with(['product.normalImages', 'size:id,size', 'sizeColor:id,colorAr,sizeId', 'salesOrder:id,serial_number,customer_id,status,subtotal,discount,created_at'])
                ->whereHas('salesOrder', fn ($query) => $query
                    ->where('customer_id', $personId)
                    ->whereIn('status', $this->returnableOrderStatuses()))
                ->orderByDesc('id')
                ->get()
                ->map(function (SalesOrderItem $line) {
                    $sold = (int) ($line->delivered_qty > 0 ? $line->delivered_qty : $line->quantity);
                    $recorded = (int) SalesReturnItem::query()->where('sales_order_item_id', $line->id)->sum('quantity');
                    $returned = max((int) $line->returned_qty, $recorded);
                    $available = max(0, $sold - $returned);
                    $order = $line->salesOrder;
                    $factor = (float) $order->subtotal > 0
                        ? max(0, ((float) $order->subtotal - (float) $order->discount) / (float) $order->subtotal)
                        : 1;

                    return $this->availableRow(
                        'sales_order',
                        (int) $line->id,
                        (int) $order->id,
                        (string) ($order->serial_number ?: '#'.$order->id),
                        $line,
                        $sold,
                        $returned,
                        $available,
                        round((float) $line->unit_price * $factor, 2),
                        $order->created_at?->toDateTimeString()
                    );
                });
        }

        return $instantRows->concat($orderRows)
            ->filter(fn (array $row) => $row['available_quantity'] > 0)
            ->sortByDesc('invoice_date')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): SalesReturn
    {
        return DB::transaction(function () use ($actor, $data) {
            $personType = $data['person_type'];
            $personId = (int) $data['person_id'];
            $this->assertPerson($personType, $personId);

            $cash = round((float) ($data['cash_refund_amount'] ?? 0), 2);
            $keys = collect($data['items'])->map(fn (array $row) => $row['source_type'].':'.$row['source_item_id']);
            if ($keys->unique()->count() !== $keys->count()) {
                throw ValidationException::withMessages(['items' => ['لا يمكن تكرار نفس سطر البيع في فاتورة المرتجع.']]);
            }
            $prepared = collect($data['items'])->map(
                fn (array $row) => $this->prepareLine($personType, $personId, $row)
            );
            $total = round((float) $prepared->sum('line_total'), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['items' => ['يجب أن تكون قيمة المرتجع أكبر من صفر.']]);
            }
            if ($cash > $total + 0.001) {
                throw ValidationException::withMessages(['cash_refund_amount' => ['المبلغ النقدي أكبر من إجمالي المرتجع.']]);
            }

            $credit = round($total - $cash, 2);
            $session = $cash > 0 ? $this->sessions->assertCanCreateSale($actor) : null;
            $box = $cash > 0 ? $this->resolveRefundBox($session, $data['refund_box_id'] ?? null) : null;
            if ($box && (float) $box->total + 0.001 < $cash) {
                throw ValidationException::withMessages([
                    'cash_refund_amount' => ['رصيد صندوق المبيعات غير كافٍ. الرصيد الحالي '.round((float) $box->total, 2).' شيكل.'],
                ]);
            }

            $rootInstantIds = $prepared->pluck('instant_sale_root_id')->filter()->unique();
            $orderIds = $prepared->pluck('sales_order_id')->filter()->unique();
            $return = SalesReturn::create([
                'sales_order_id' => $orderIds->count() === 1 && $rootInstantIds->isEmpty() ? $orderIds->first() : null,
                'instant_sale_id' => $rootInstantIds->count() === 1 && $orderIds->isEmpty() ? $rootInstantIds->first() : null,
                'return_type' => 'direct',
                'customer_id' => $personType === 'customer' ? $personId : null,
                'seller_id' => $personType === 'seller' ? $personId : null,
                'status' => 'completed',
                'total_amount' => $total,
                'currency' => 'شيكل',
                'cash_refund_amount' => $cash,
                'credit_amount' => $credit,
                'refund_box_id' => $box?->id,
                'sales_daily_session_id' => $session?->id,
                'note' => $data['note'] ?? null,
                'completed_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->serials->assignPrefixedToModel($return, DocumentSerialService::TYPE_SALES_RETURN, 'SRT-', 'serial_number');

            foreach ($prepared as $row) {
                SalesReturnItem::create([
                    'sales_return_id' => $return->id,
                    'sales_order_item_id' => $row['sales_order_item_id'],
                    'instant_sale_id' => $row['instant_sale_id'],
                    'product_id' => $row['product_id'],
                    'size_id' => $row['size_id'],
                    'size_color_id' => $row['size_color_id'],
                    'product_name' => $row['product_name'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'original_unit_price' => $row['original_unit_price'],
                    'inventory_unit_cost' => $row['inventory_unit_cost'],
                    'inventory_total_cost' => $row['inventory_total_cost'],
                    'line_total' => $row['line_total'],
                    'price_override_reason' => $row['price_override_reason'],
                ]);

                if ($row['sales_order_item_id']) {
                    SalesOrderItem::query()->whereKey($row['sales_order_item_id'])->increment('returned_qty', $row['quantity']);
                }
                $product = Product::withTrashed()->findOrFail($row['product_id']);
                $this->stock->restoreForSale(
                    $product,
                    $row['quantity'],
                    $row['size_color_id'],
                    $row['size_id'],
                    'sales_return',
                    (int) $return->id,
                    'مرتجع مبيعات '.$return->serial_number.' — '.$row['product_name'],
                    (int) $actor->id,
                );
            }

            if ($box && $cash > 0) {
                $box->decrement('total', $cash);
                BoxLogs::createBoxLog(
                    $box->fresh(),
                    'سحب — مرتجع مبيعات '.$return->serial_number,
                    'minus',
                    -$cash,
                    $data['note'] ?? 'رد نقدي للزبون/التاجر'
                );
            }

            if ($credit > 0) {
                $transaction = $this->ledger->createTransaction([
                    'customer_id' => $personType === 'customer' ? $personId : null,
                    'seller_id' => $personType === 'seller' ? $personId : null,
                    'type' => 'taken',
                    'amount' => $credit,
                    'currency' => 'شيكل',
                    'transaction_date' => now()->toDateString(),
                    'source' => 'sales_return',
                    'source_id' => $return->id,
                    'note' => 'رصيد مرتجع مبيعات '.$return->serial_number,
                ], (int) $actor->id, false);
                $return->update(['debt_transaction_id' => $transaction->id]);
            }

            Logs::createLog(
                'إنشاء فاتورة مرتجع مبيعات',
                'تم إنشاء '.$return->serial_number.' بقيمة '.$total.' شيكل، نقدي '.$cash.' ورصيد '.$credit,
                'sales_returns'
            );

            return $return->fresh($this->relations());
        });
    }

    public function show(int $id): SalesReturn
    {
        return SalesReturn::query()->with($this->relations())->findOrFail($id);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['items.instantSale', 'items.salesOrderItem', 'customer:id,name,phone', 'seller:id,name,phone', 'refundBox:id,name,currency', 'debtTransaction'];
    }

    private function activeInstantSales($query)
    {
        return $query->whereNull('cancelled_at')
            ->where(fn ($status) => $status->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->where(fn ($kind) => $kind->whereNull('sale_kind')->orWhere('sale_kind', 'regular'));
    }

    /** @return array<int, string> */
    private function returnableOrderStatuses(): array
    {
        return ['delivered', 'archived', 'partial_delivered', 'partial_return'];
    }

    private function assertPerson(string $type, int $id): void
    {
        $exists = $type === 'customer' ? Customer::query()->whereKey($id)->exists() : Seller::query()->whereKey($id)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['person_id' => ['الزبون أو التاجر المحدد غير موجود.']]);
        }
    }

    /** @return array<string, mixed> */
    private function personRow(string $type, Customer|Seller $person): array
    {
        return ['id' => (int) $person->id, 'type' => $type, 'name' => $person->name, 'phone' => $person->phone, 'type_label' => $type === 'customer' ? 'زبون' : 'تاجر/مورد'];
    }

    /** @return array<string, mixed> */
    private function availableRow(string $sourceType, int $sourceItemId, int $invoiceId, string $serial, object $line, int $sold, int $returned, int $available, float $price, ?string $date): array
    {
        $product = $line->product;
        $image = $product?->normalImages?->first();

        return [
            'source_type' => $sourceType,
            'source_item_id' => $sourceItemId,
            'invoice_id' => $invoiceId,
            'invoice_serial' => $serial,
            'invoice_date' => $date,
            'product_id' => (int) $line->product_id,
            'product_code' => $product?->product_code,
            'product_name' => $product?->nameAr ?: $line->product_name,
            'image' => $image?->imageUrl,
            'size_id' => $line->size_id ? (int) $line->size_id : null,
            'size_label' => $line->size?->size,
            'size_color_id' => $line->size_color_id ? (int) $line->size_color_id : null,
            'color_label' => $line->sizeColor?->colorAr,
            'sold_quantity' => $sold,
            'returned_quantity' => $returned,
            'available_quantity' => $available,
            'unit_price' => $price,
        ];
    }

    private function instantEffectiveUnitPrice(InstantSale $line): float
    {
        $root = $line->parent_id ? $line->parentSale : $line;
        $root->loadMissing('subProducts');
        $gross = (float) $root->cost * (float) $root->quantity;
        foreach ($root->subProducts as $sub) {
            $gross += (float) $sub->cost * (float) $sub->quantity;
        }
        $factor = $gross > 0 ? min(1, max(0, (float) $root->total_cost / $gross)) : 1;

        return round((float) $line->cost * $factor, 2);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function prepareLine(string $personType, int $personId, array $row): array
    {
        $sourceType = $row['source_type'];
        $sourceId = (int) $row['source_item_id'];
        $quantity = (int) $row['quantity'];

        if ($sourceType === 'instant_sale') {
            $line = InstantSale::query()->with(['product', 'parentSale.subProducts'])->lockForUpdate()->findOrFail($sourceId);
            $belongs = (int) ($personType === 'customer' ? $line->buyer_id : $line->seller_id) === $personId;
            if (! $belongs || $line->isCancelled() || $line->sale_kind === 'adjustment' || ! $line->product_id) {
                throw ValidationException::withMessages(['items' => ['أحد أصناف البيع لا يخص الطرف المحدد أو لم يعد صالحًا للإرجاع.']]);
            }
            $sold = (int) round((float) $line->quantity);
            $returned = (int) SalesReturnItem::query()
                ->where('instant_sale_id', $line->id)
                ->lockForUpdate()
                ->sum('quantity');
            $original = $this->instantEffectiveUnitPrice($line);
            $inventoryUnitCost = $line->inventory_unit_cost !== null
                ? (float) $line->inventory_unit_cost
                : $this->fallbackUnitCost((int) $line->product_id);
            $salesOrderItemId = null;
            $instantSaleId = (int) $line->id;
            $rootId = (int) ($line->parent_id ?: $line->id);
            $orderId = null;
        } else {
            if ($personType !== 'customer') {
                throw ValidationException::withMessages(['items' => ['الطلبيات مرتبطة بالزبائن فقط.']]);
            }
            $line = SalesOrderItem::query()->with(['product', 'salesOrder'])->lockForUpdate()->findOrFail($sourceId);
            if ((int) $line->salesOrder->customer_id !== $personId || ! in_array($line->salesOrder->status, $this->returnableOrderStatuses(), true)) {
                throw ValidationException::withMessages(['items' => ['أحد أصناف الطلبية لا يخص الزبون أو لم يعد صالحًا للإرجاع.']]);
            }
            $sold = (int) ($line->delivered_qty > 0 ? $line->delivered_qty : $line->quantity);
            $recorded = (int) SalesReturnItem::query()
                ->where('sales_order_item_id', $line->id)
                ->lockForUpdate()
                ->sum('quantity');
            $returned = max((int) $line->returned_qty, $recorded);
            $factor = (float) $line->salesOrder->subtotal > 0
                ? max(0, ((float) $line->salesOrder->subtotal - (float) $line->salesOrder->discount) / (float) $line->salesOrder->subtotal)
                : 1;
            $original = round((float) $line->unit_price * $factor, 2);
            $movement = ProductStockMovement::query()
                ->where('reference_type', 'sales_order')
                ->where('reference_id', $line->sales_order_id)
                ->where('product_id', $line->product_id)
                ->when($line->size_color_id, fn ($query) => $query->where('size_color_id', $line->size_color_id))
                ->where('quantity', '<', 0)
                ->orderByDesc('id')
                ->first();
            $inventoryUnitCost = $movement?->unit_cost !== null
                ? (float) $movement->unit_cost
                : $this->fallbackUnitCost((int) $line->product_id);
            $salesOrderItemId = (int) $line->id;
            $instantSaleId = null;
            $rootId = null;
            $orderId = (int) $line->sales_order_id;
        }

        $available = max(0, $sold - $returned);
        if ($quantity <= 0 || $quantity > $available) {
            throw ValidationException::withMessages(['items' => ['الكمية المتاحة للصنف '.($line->product?->nameAr ?: '#'.$line->product_id).' هي '.$available.'.']]);
        }
        $price = round((float) $row['unit_price'], 2);
        $reason = trim((string) ($row['price_override_reason'] ?? ''));
        if (abs($price - $original) > 0.001 && $reason === '') {
            throw ValidationException::withMessages(['items' => ['يجب كتابة سبب تعديل سعر الصنف '.($line->product?->nameAr ?: '#'.$line->product_id).'.']]);
        }

        return [
            'sales_order_item_id' => $salesOrderItemId,
            'sales_order_id' => $orderId,
            'instant_sale_id' => $instantSaleId,
            'instant_sale_root_id' => $rootId,
            'product_id' => (int) $line->product_id,
            'size_id' => $line->size_id ? (int) $line->size_id : null,
            'size_color_id' => $line->size_color_id ? (int) $line->size_color_id : null,
            'product_name' => $line->product?->nameAr ?: ($line->product_name ?: 'منتج #'.$line->product_id),
            'quantity' => $quantity,
            'unit_price' => $price,
            'original_unit_price' => $original,
            'inventory_unit_cost' => $inventoryUnitCost,
            'inventory_total_cost' => round($inventoryUnitCost * $quantity, 4),
            'line_total' => round($quantity * $price, 2),
            'price_override_reason' => $reason !== '' ? $reason : null,
        ];
    }

    private function resolveRefundBox($session, mixed $requestedId): Box
    {
        $box = $this->sessions->dailyBoxForSessionCurrency($session, 'شيكل');
        if (! $box) {
            throw ValidationException::withMessages(['refund_box_id' => ['لم يتم العثور على صندوق شيكل لجلسة المبيعات المفتوحة.']]);
        }
        if ($requestedId && (int) $requestedId !== (int) $box->id) {
            throw ValidationException::withMessages(['refund_box_id' => ['الصندوق المحدد ليس صندوق جلسة المبيعات المفتوحة.']]);
        }

        return Box::query()->lockForUpdate()->findOrFail($box->id);
    }

    private function fallbackUnitCost(int $productId): float
    {
        return (float) (Product::withTrashed()->find($productId)?->purchasePrices()->latest('id')->value('price') ?? 0);
    }
}
