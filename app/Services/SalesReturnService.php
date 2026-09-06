<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Http\Controllers\API\Logs;
use App\Models\Box;
use App\Models\Customer;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\InstantSale;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Seller;
use App\Models\SizeColor;
use App\Models\User;
use App\Support\ProductImageResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->with(['product.viewImages', 'product.normalImages', 'product.image3d', 'size:id,size', 'sizeColor:id,colorAr,sizeId', 'parentSale.subProducts'])
            ->whereNotNull('product_id')
            ->where($personType === 'customer' ? 'buyer_id' : 'seller_id', $personId)
            ->where($personType === 'customer' ? 'seller_id' : 'buyer_id', null)
            ->where(fn ($query) => $this->activeInstantSales($query))
            ->orderByDesc('created_at')
            ->get()
            ->map(function (InstantSale $line) {
                $sold = (int) round((float) $line->quantity);
                $returned = (int) SalesReturnItem::query()
                    ->where('instant_sale_id', $line->id)
                    ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->sum('quantity');
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
                ->with(['product.viewImages', 'product.normalImages', 'product.image3d', 'size:id,size', 'sizeColor:id,colorAr,sizeId', 'salesOrder:id,serial_number,customer_id,status,subtotal,discount,created_at'])
                ->whereHas('salesOrder', fn ($query) => $query
                    ->where('customer_id', $personId)
                    ->whereIn('status', $this->returnableOrderStatuses()))
                ->orderByDesc('id')
                ->get()
                ->map(function (SalesOrderItem $line) {
                    $sold = (int) ($line->delivered_qty > 0 ? $line->delivered_qty : $line->quantity);
                    $recorded = (int) SalesReturnItem::query()
                        ->where('sales_order_item_id', $line->id)
                        ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
                        ->sum('quantity');
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
                $returnItem = SalesReturnItem::create([
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
                    (float) $row['inventory_unit_cost'],
                    (float) $row['inventory_total_cost'],
                    'sales_return_item',
                    (int) $returnItem->id,
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

    public function cancel(User $actor, int $id, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($actor, $id, $reason) {
            $return = SalesReturn::query()
                ->with(['items.product', 'refundBox', 'salesDailySession'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($return->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'sales_return_id' => ['فاتورة المرتجع ملغاة مسبقًا.'],
                ]);
            }

            $cancellationBox = $this->resolveCancellationBox($return, $actor);
            if ($return->return_type !== 'direct') {
                throw ValidationException::withMessages([
                    'sales_return_id' => ['هذا المرتجع تابع لمسار الطلبيات ولا يمكن إلغاؤه من شاشة المرتجعات المباشرة.'],
                ]);
            }

            foreach ($return->items as $item) {
                $this->reverseReturnedStock($return, $item, $actor);
                if ($item->sales_order_item_id) {
                    DB::table('sales_order_items')
                        ->where('id', $item->sales_order_item_id)
                        ->update([
                            'returned_qty' => DB::raw('GREATEST(0, returned_qty - '.(int) $item->quantity.')'),
                            'updated_at' => now(),
                        ]);
                }
            }

            if ((float) $return->cash_refund_amount > 0) {
                $box = $cancellationBox;
                $box->increment('total', (float) $return->cash_refund_amount);
                BoxLogs::createBoxLog(
                    $box->fresh(),
                    'إضافة — إلغاء مرتجع مبيعات '.$return->serial_number,
                    'add',
                    (float) $return->cash_refund_amount,
                    'عكس الرد النقدي للمرتجع — السبب: '.$reason,
                );
            }

            $this->ledger->deleteSourceLedger('sales_return', (int) $return->id);
            $return->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            Logs::createLog(
                'إلغاء فاتورة مرتجع مبيعات',
                'تم إلغاء '.$return->serial_number.' وعكس المخزون والنقد والرصيد. السبب: '.$reason,
                'sales_returns'
            );

            return $return->fresh($this->relations());
        });
    }

    /** @param array<string, mixed> $data */
    public function replace(User $actor, int $id, array $data, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($actor, $id, $data, $reason) {
            $old = $this->cancel($actor, $id, 'تعديل المرتجع: '.$reason);
            $replacement = $this->create($actor, $data);
            $replacement->update(['replaces_sales_return_id' => $old->id]);
            $old->update(['replacement_sales_return_id' => $replacement->id]);

            Logs::createLog(
                'تعديل فاتورة مرتجع مبيعات',
                'تم استبدال '.$old->serial_number.' بالفاتورة '.$replacement->serial_number.'. السبب: '.$reason,
                'sales_returns'
            );

            return $replacement->fresh($this->relations());
        });
    }

    public function assertInstantSaleHasNoActiveDirectReturns(InstantSale $sale): void
    {
        $lineIds = InstantSale::query()
            ->where('id', $sale->id)
            ->orWhere('parent_id', $sale->id)
            ->pluck('id');
        $exists = SalesReturnItem::query()
            ->whereIn('instant_sale_id', $lineIds)
            ->whereHas('salesReturn', fn ($query) => $query
                ->where('return_type', 'direct')
                ->where('status', '!=', 'cancelled'))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'instant_sale_id' => ['لا يمكن إلغاء أو تعديل فاتورة البيع قبل إلغاء مرتجعاتها الفعالة.'],
            ]);
        }
    }

    public function assertSalesOrderHasNoActiveDirectReturns(int $orderId): void
    {
        $exists = SalesReturnItem::query()
            ->whereHas('salesOrderItem', fn ($query) => $query->where('sales_order_id', $orderId))
            ->whereHas('salesReturn', fn ($query) => $query
                ->where('return_type', 'direct')
                ->where('status', '!=', 'cancelled'))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'order' => ['لا يمكن إلغاء أو تعديل الطلبية قبل إلغاء مرتجعاتها الفعالة.'],
            ]);
        }
    }

    private function resolveCancellationBox(SalesReturn $return, User $actor): ?Box
    {
        if ((float) $return->cash_refund_amount <= 0.0001) {
            return null;
        }

        $originalSession = $return->salesDailySession;
        if ($originalSession?->isClosingRequested()) {
            throw ValidationException::withMessages([
                'session' => ['صندوق المرتجع قيد الإغلاق. اطلب من المسؤول رفض طلب الإغلاق، ثم أعد محاولة إلغاء المرتجع.'],
            ]);
        }

        if ($originalSession?->isOpen()) {
            $box = $return->refund_box_id
                ? Box::query()->lockForUpdate()->find($return->refund_box_id)
                : $this->sessions->dailyBoxForSessionCurrency($originalSession, 'شيكل');
            if (! $box) {
                throw ValidationException::withMessages([
                    'refund_box_id' => ['صندوق المرتجع المفتوح غير موجود. اطلب من المسؤول مراجعة صندوق جلسة المبيعات ثم أعد المحاولة.'],
                ]);
            }

            return $box;
        }

        $currentSession = $this->sessions->assertCanCreateSale($actor);
        $box = $this->sessions->dailyBoxForSessionCurrency($currentSession, 'شيكل');
        if (! $box) {
            throw ValidationException::withMessages([
                'refund_box_id' => ['صندوق المرتجع الأصلي مغلق ولا يوجد صندوق شيكل مفتوح لليوم. افتح صندوق المبيعات ثم أعد المحاولة.'],
            ]);
        }

        return Box::query()->lockForUpdate()->findOrFail($box->id);
    }

    /** @return array<int, string> */
    private function cancellationInventoryIssues(SalesReturn $return): array
    {
        $return->loadMissing('items.product');
        $layers = InventoryCostLayer::query()
            ->where('source_type', 'sales_return_item')
            ->whereIn('source_id', $return->items->pluck('id'))
            ->get()
            ->keyBy('source_id');
        $issues = [];

        foreach ($return->items as $item) {
            $layer = $layers->get($item->id);
            if (! $layer || (float) $layer->remaining_quantity + 0.0001 < (float) $item->quantity) {
                $issues[] = $item->product_name.': الكمية المرتجعة أُعيد بيعها أو طبقة تكلفتها غير متاحة.';

                continue;
            }

            $stock = $item->size_color_id
                ? (int) SizeColor::query()->whereKey($item->size_color_id)->value('stock')
                : (int) Product::withTrashed()->whereKey($item->product_id)->value('stock');
            if ($stock < (int) $item->quantity) {
                $issues[] = $item->product_name.': المتوفر '.$stock.' والمطلوب لعكس المرتجع '.$item->quantity.'.';
            }
        }

        return array_values(array_unique($issues));
    }

    private function reverseReturnedStock(SalesReturn $return, SalesReturnItem $item, User $actor): void
    {
        $layer = InventoryCostLayer::query()
            ->where('source_type', 'sales_return_item')
            ->where('source_id', $item->id)
            ->lockForUpdate()
            ->first();
        if (! $layer || (float) $layer->remaining_quantity + 0.0001 < (float) $item->quantity) {
            throw ValidationException::withMessages([
                'items' => ['لا يمكن إلغاء المرتجع لأن مخزونه أُعيد بيعه أو لا يملك طبقة تكلفة موثقة: '.$item->product_name],
            ]);
        }

        if ($item->size_color_id) {
            $stock = (int) SizeColor::query()->whereKey($item->size_color_id)->lockForUpdate()->value('stock');
        } else {
            $stock = (int) Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->value('stock');
        }
        if ($stock < (int) $item->quantity) {
            throw ValidationException::withMessages([
                'items' => ['كمية المخزون الحالية لا تكفي لعكس المرتجع للصنف '.$item->product_name.'.'],
            ]);
        }

        $layer->decrement('remaining_quantity', (float) $item->quantity);
        $this->stock->adjustStock(
            product: $item->product,
            quantityDelta: -1 * (int) $item->quantity,
            type: 'sales_return_cancel',
            sizeColorId: $item->size_color_id ? (int) $item->size_color_id : null,
            referenceType: 'sales_return_cancel',
            referenceId: (int) $return->id,
            note: 'عكس مخزون مرتجع '.$return->serial_number.' — '.$item->product_name,
            userId: (int) $actor->id,
            unitCost: (float) $item->inventory_unit_cost,
            totalCost: (float) $item->inventory_total_cost,
        );
    }

    /** @return array<string, mixed> */
    public function show(int $id, ?User $actor = null): array
    {
        $return = SalesReturn::query()->with($this->detailRelations())->findOrFail($id);
        $payload = $return->toArray();
        $payload['items'] = $return->items->map(
            fn (SalesReturnItem $item) => $this->detailItemPayload($item)
        )->values()->all();
        $payload['source_invoices'] = collect($payload['items'])
            ->pluck('sale_invoice')
            ->filter()
            ->unique(fn (array $invoice) => $invoice['type'].':'.$invoice['id'])
            ->values()
            ->all();
        $payload['accounting'] = [
            'gross_return' => (float) $return->total_amount,
            'cash_refund' => (float) $return->cash_refund_amount,
            'credit_refund' => (float) $return->credit_amount,
            'inventory_cost_restored' => round((float) $return->items->sum('inventory_total_cost'), 4),
            'margin_reversed' => round((float) $return->total_amount - (float) $return->items->sum('inventory_total_cost'), 4),
            'debt_transaction_id' => $return->debt_transaction_id,
            'refund_box_id' => $return->refund_box_id,
        ];
        if ($actor) {
            $payload['cancellation_preview'] = $this->cancellationPreview($return, $actor);
        }

        return $payload;
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['items.instantSale', 'items.salesOrderItem', 'customer:id,name,phone', 'seller:id,name,phone', 'refundBox:id,name,currency', 'debtTransaction'];
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'items.product.viewImages',
            'items.product.normalImages',
            'items.product.image3d',
            'items.size:id,size',
            'items.sizeColor:id,colorAr,sizeId',
            'items.instantSale.parentSale',
            'items.salesOrderItem.salesOrder',
            'customer:id,name,phone',
            'seller:id,name,phone',
            'refundBox:id,name,currency',
            'salesDailySession:id,user_id,employee_id,business_date,status',
            'debtTransaction',
        ];
    }

    /** @return array<string, mixed> */
    private function cancellationPreview(SalesReturn $return, User $actor): array
    {
        $steps = [];
        $warnings = [];
        $scenario = 'ready';
        $canCancel = true;
        $title = 'يمكن إلغاء المرتجع';
        $summary = 'سيتم عكس المخزون والقيود المالية المرتبطة بالمرتجع.';

        if ($return->status === 'cancelled') {
            return [
                'can_cancel' => false,
                'scenario' => 'already_cancelled',
                'title' => 'المرتجع ملغى مسبقًا',
                'summary' => 'لا يوجد إجراء إضافي مطلوب.',
                'steps' => ['راجع سبب الإلغاء وسجل التدقيق في تفاصيل الفاتورة.'],
                'warnings' => [],
            ];
        }

        if ($return->return_type !== 'direct') {
            return [
                'can_cancel' => false,
                'scenario' => 'order_return',
                'title' => 'الإلغاء من مسار الطلبيات',
                'summary' => 'هذا المرتجع تابع لطلبية ولا يُلغى من شاشة المرتجعات المباشرة.',
                'steps' => ['افتح الطلبية الأصلية ونفّذ الإلغاء من إجراءات الطلبية.'],
                'warnings' => [],
            ];
        }

        $inventoryIssues = $this->cancellationInventoryIssues($return);
        if ($inventoryIssues !== []) {
            return [
                'can_cancel' => false,
                'scenario' => 'returned_stock_used',
                'title' => 'يجب معالجة المخزون أولًا',
                'summary' => 'لا يمكن عكس المرتجع لأن جزءًا من البضاعة المرتجعة خرج من المخزون أو أُعيد بيعه.',
                'steps' => [
                    'افتح الفاتورة اللاحقة التي باعت الكمية المرتجعة وألغها أو أعد الكمية إلى المخزون.',
                    'تأكد أن الكمية المطلوبة موجودة بنفس المقاس واللون.',
                    'ارجع إلى فاتورة المرتجع وأعد محاولة الإلغاء.',
                ],
                'warnings' => $inventoryIssues,
            ];
        }

        $cash = (float) $return->cash_refund_amount;
        $credit = (float) $return->credit_amount;
        if ($cash <= 0.0001) {
            $scenario = 'credit_only';
            $title = 'مرتجع رصيد دون حركة نقدية';
            $summary = 'هذا المرتجع لم يخرج منه نقد؛ سيُعكس المخزون ورصيد الطرف فقط.';
            $steps[] = 'تأكد أن رصيد الزبون أو المورد لم تتم تسويته يدويًا خارج النظام.';
        } else {
            $session = $return->salesDailySession;
            if ($session?->isClosingRequested()) {
                $canCancel = false;
                $scenario = 'closing_requested';
                $title = 'صندوق المرتجع قيد الإغلاق';
                $summary = 'لا يمكن إضافة عكس مالي أثناء مراجعة إغلاق الصندوق.';
                $steps = [
                    'اطلب من المسؤول رفض أو إلغاء طلب إغلاق صندوق يوم '.$session->business_date?->toDateString().'.',
                    'بعد عودة الصندوق إلى حالة مفتوح، أعد محاولة إلغاء المرتجع.',
                ];
            } elseif ($session?->isOpen()) {
                $scenario = 'original_box_open';
                $title = 'الصندوق الأصلي مفتوح';
                $summary = 'صندوق المرتجع الأصلي ما زال مفتوحًا؛ سيُعاد مبلغ '.round($cash, 2).' شيكل إلى نفس الصندوق.';
                $steps[] = 'استلم مبلغ '.round($cash, 2).' شيكل من الزبون أو المورد فعليًا.';
                $steps[] = 'نفّذ الإلغاء ليُسجل المبلغ في صندوق المرتجع المفتوح.';
            } else {
                $scenario = 'original_box_closed';
                try {
                    $currentSession = $this->sessions->assertCanCreateSale($actor);
                    $title = 'الصندوق الأصلي مغلق — التسجيل اليوم';
                    $summary = 'صندوق المرتجع الأصلي مغلق؛ سيُسجل مبلغ '.round($cash, 2).' شيكل كحركة اليوم في الصندوق المفتوح، دون تعديل إغلاق اليوم القديم.';
                    $steps[] = 'استلم مبلغ '.round($cash, 2).' شيكل من الزبون أو المورد فعليًا.';
                    $steps[] = 'سيُضاف المبلغ إلى صندوق المبيعات المفتوح ليوم '.$currentSession->business_date?->toDateString().'.';
                    $warnings[] = 'لا تفتح أو تعدّل الصندوق القديم؛ العكس المالي يُسجل بتاريخ اليوم.';
                } catch (ValidationException $e) {
                    $canCancel = false;
                    $title = 'افتح صندوق مبيعات أولًا';
                    $summary = 'صندوق المرتجع الأصلي مغلق، ولا يوجد صندوق مبيعات مفتوح لتسجيل النقد المسترد اليوم.';
                    $steps = [
                        'افتح صندوق المبيعات لليوم أو أنهِ طلب الإغلاق المعلّق.',
                        'استلم مبلغ '.round($cash, 2).' شيكل من الزبون أو المورد.',
                        'ارجع إلى المرتجع وأعد محاولة الإلغاء.',
                    ];
                    $warnings[] = collect($e->errors())->flatten()->first() ?: 'لا توجد جلسة مبيعات مفتوحة.';
                }
            }
        }

        if ($credit > 0.0001) {
            $steps[] = 'سيتم حذف قيد رصيد المرتجع بقيمة '.round($credit, 2).' شيكل من دفتر الطرف.';
        }
        $steps[] = 'سيتم سحب الكميات المرتجعة من المخزون وعكس طبقات التكلفة.';

        return [
            'can_cancel' => $canCancel,
            'scenario' => $scenario,
            'title' => $title,
            'summary' => $summary,
            'steps' => $steps,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function detailItemPayload(SalesReturnItem $item): array
    {
        $saleInvoice = $this->saleInvoicePayload($item);
        $payload = $item->toArray();
        $images = $item->product
            ? ProductImageResolver::formatForList($item->product)
            : ['product_image' => 'no image', 'product_viewImages' => [], 'product_normalImages' => [], 'product_image3d' => []];
        $payload['product_code'] = $item->product?->product_code;
        $payload['product_image'] = $images['product_image'];
        $payload['product_images'] = collect([
            ...$images['product_viewImages'],
            ...$images['product_normalImages'],
            ...$images['product_image3d'],
        ])->unique()->values()->all();
        $payload['size_label'] = $item->size?->size;
        $payload['color_label'] = $item->sizeColor?->colorAr;
        $payload['sale_invoice'] = $saleInvoice;
        $payload['purchase_sources'] = $this->purchaseSources($item, $saleInvoice);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function saleInvoicePayload(SalesReturnItem $item): ?array
    {
        if ($item->instant_sale_id) {
            $line = $item->instantSale;
            $root = $line?->parent_id ? $line->parentSale : $line;
            if (! $root) {
                return null;
            }

            return [
                'type' => 'instant_sale',
                'id' => (int) $root->id,
                'serial' => (string) ($root->serial_number ?: '#'.$root->id),
                'date' => $root->created_at?->toDateTimeString(),
                'sold_quantity' => (int) round((float) $line->quantity),
                'sold_unit_price' => $this->instantEffectiveUnitPrice($line),
            ];
        }

        $line = $item->salesOrderItem;
        $order = $line?->salesOrder;
        if (! $line || ! $order) {
            return null;
        }

        return [
            'type' => 'sales_order',
            'id' => (int) $order->id,
            'serial' => (string) ($order->serial_number ?: '#'.$order->id),
            'date' => $order->created_at?->toDateTimeString(),
            'sold_quantity' => (int) ($line->delivered_qty > 0 ? $line->delivered_qty : $line->quantity),
            'sold_unit_price' => (float) $item->original_unit_price,
        ];
    }

    /** @param array<string, mixed>|null $saleInvoice @return array<int, array<string, mixed>> */
    private function purchaseSources(SalesReturnItem $item, ?array $saleInvoice): array
    {
        if (! $saleInvoice
            || ! Schema::hasTable('inventory_cost_allocations')
            || ! Schema::hasTable('inventory_cost_layers')
            || ! Schema::hasTable('purchase_receipt_items')) {
            return [];
        }

        $costReferenceId = $item->instant_sale_id
            ? (int) $item->instant_sale_id
            : (int) $saleInvoice['id'];

        $query = InventoryCostAllocation::query()
            ->join('inventory_cost_layers as layers', 'layers.id', '=', 'inventory_cost_allocations.inventory_cost_layer_id')
            ->join('purchase_receipt_items as receipt_items', function ($join) {
                $join->on('receipt_items.id', '=', 'layers.source_id')
                    ->where('layers.source_type', 'purchase_receipt_item');
            })
            ->join('purchase_receipts as receipts', 'receipts.id', '=', 'receipt_items.purchase_receipt_id')
            ->join('bill_items', 'bill_items.id', '=', 'receipt_items.bill_item_id')
            ->join('bills', 'bills.id', '=', 'receipts.bill_id')
            ->where('inventory_cost_allocations.reference_type', $saleInvoice['type'])
            ->where('inventory_cost_allocations.reference_id', $costReferenceId)
            ->where('inventory_cost_allocations.product_id', $item->product_id)
            ->when($item->size_color_id, fn ($q) => $q->where('layers.size_color_id', $item->size_color_id))
            ->when(! $item->size_color_id && $item->size_id, fn ($q) => $q->where('layers.size_id', $item->size_id));

        return $query
            ->selectRaw('bills.id as bill_id, bills.created_at as bill_date, bills.currency, bill_items.id as bill_item_id, bill_items.quantity as invoice_quantity, bill_items.ordered_quantity, bill_items.received_owned_quantity, receipt_items.unit_price, SUM(inventory_cost_allocations.quantity) as allocated_quantity, SUM(inventory_cost_allocations.total_cost) as allocated_total_cost')
            ->groupBy('bills.id', 'bills.created_at', 'bills.currency', 'bill_items.id', 'bill_items.quantity', 'bill_items.ordered_quantity', 'bill_items.received_owned_quantity', 'receipt_items.unit_price')
            ->orderBy('bills.id')
            ->get()
            ->map(fn ($row) => [
                'bill_id' => (int) $row->bill_id,
                'bill_date' => $row->bill_date,
                'bill_item_id' => (int) $row->bill_item_id,
                'invoice_quantity' => (float) $row->invoice_quantity,
                'ordered_quantity' => (float) $row->ordered_quantity,
                'received_quantity' => (float) $row->received_owned_quantity,
                'allocated_to_sale_quantity' => (float) $row->allocated_quantity,
                'unit_cost' => (float) $row->unit_price,
                'allocated_total_cost' => (float) $row->allocated_total_cost,
                'currency' => (string) $row->currency,
                'traceability' => 'inventory_cost_allocation',
            ])
            ->all();
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
        return [
            'source_type' => $sourceType,
            'source_item_id' => $sourceItemId,
            'invoice_id' => $invoiceId,
            'invoice_serial' => $serial,
            'invoice_date' => $date,
            'product_id' => (int) $line->product_id,
            'product_code' => $product?->product_code,
            'product_name' => $product?->nameAr ?: $line->product_name,
            'image' => ProductImageResolver::preferredUrl($product),
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
                ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
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
                ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
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
