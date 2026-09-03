<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\Project;
use App\Models\SalesDailySession;
use App\Models\SalesOrder;
use App\Models\Seller;
use App\Models\SizeColor;
use App\Services\AdminNotificationService;
use App\Services\CustomerProductPriceHistoryService;
use App\Services\DebtLedgerService;
use App\Services\DocumentSerialService;
use App\Services\EmployeeActivityLogger;
use App\Services\OfferPackageService;
use App\Services\ProductStockService;
use App\Services\SalesDailySessionService;
use App\Services\SalesReturnService;
use App\Support\ApiImageUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isEmpty;

class InstantSales extends Controller
{
    private const SALE_KIND_REGULAR = 'regular';

    private const SALE_KIND_ADJUSTMENT = 'adjustment';

    private function resolveSaleKind(Request $request): string
    {
        return $request->input('sale_kind') === self::SALE_KIND_ADJUSTMENT
            ? self::SALE_KIND_ADJUSTMENT
            : self::SALE_KIND_REGULAR;
    }

    private function isAdjustmentSaleKind(string $saleKind): bool
    {
        return $saleKind === self::SALE_KIND_ADJUSTMENT;
    }

    private function invoiceProductImage(?Product $product, ?SizeColor $sizeColor = null): string
    {
        if ($sizeColor instanceof SizeColor) {
            $variantImage = trim((string) $sizeColor->image_url);
            if ($variantImage !== '' && strtolower($variantImage) !== 'no image') {
                return ApiImageUrl::normalize($variantImage);
            }
        }

        if ($product === null) {
            return 'no image';
        }

        $image = $product->viewImages->first()
            ?? $product->normalImages->first();

        return $image ? ApiImageUrl::normalize($image->imageUrl) : 'no image';
    }

    private function hasRemainingInstantSaleAmount(array $data, Request $request): bool
    {
        $total = round((float) ($data['total_cost'] ?? 0), 2);
        $paid = round((float) $request->input('payment_box_value', 0), 2);

        return max(0, $total - min($paid, $total)) > 0.009;
    }

    private function hasRequiredDebtBuyer(Request $request): bool
    {
        return $request->filled('buyer_id') || $request->filled('seller_id');
    }

    private const TRADER_CUSTOMER_TYPES = [
        'trader', 'merchant', 'wholesale', 'تاجر', 'جملة', 'تاجر جملة',
    ];

    private function buyerTypeLabelAr(string $type): string
    {
        return match ($type) {
            'trader', 'seller' => 'تاجر',
            'customer' => 'زبون',
            default => 'غير محدد',
        };
    }

    private function inferBuyerTypeFromCustomer(Customer $customer): string
    {
        $customerType = strtolower(trim((string) ($customer->type ?? '')));

        return in_array($customerType, self::TRADER_CUSTOMER_TYPES, true)
            ? 'trader'
            : 'customer';
    }

    /**
     * @return array{buyer_type: string, buyer_id: int|null, buyer_name: string, buyer_phone: ?string, buyer_address: ?string}
     */
    private function buyerSnapshotArray(string $type, ?Customer $customer = null): array
    {
        if (! $customer instanceof Customer) {
            return [
                'buyer_type' => $type,
                'buyer_id' => null,
                'buyer_name' => '-',
                'buyer_phone' => null,
                'buyer_address' => null,
            ];
        }

        return [
            'buyer_type' => $type,
            'buyer_id' => $customer->id,
            'buyer_name' => $customer->name ?: '-',
            'buyer_phone' => $customer->phone,
            'buyer_address' => $customer->address,
        ];
    }

    /**
     * Resolve buyer fields to persist on instant_sales (snapshot at sale time).
     *
     * @return array{buyer_type: string, buyer_id: int|null, buyer_name: string, buyer_phone: ?string, buyer_address: ?string}
     */
    private function resolveBuyerForStorage(Request $request, ?int $projectId = null, ?string $saleType = null): array
    {
        $requestedType = $request->input('buyer_type');
        $buyerId = $request->input('buyer_id');
        $sellerId = $request->input('seller_id');

        if ($sellerId) {
            $seller = Seller::find($sellerId);
            if ($seller instanceof Seller) {
                return [
                    'buyer_type' => 'seller',
                    'buyer_id' => null,
                    'seller_id' => $seller->id,
                    'buyer_name' => $seller->name ?: '-',
                    'buyer_phone' => $seller->phone,
                    'buyer_address' => $seller->address,
                ];
            }
        }

        if ($buyerId) {
            $customer = Customer::find($buyerId);
            if ($customer instanceof Customer) {
                $type = in_array($requestedType, ['trader', 'customer'], true)
                    ? $requestedType
                    : $this->inferBuyerTypeFromCustomer($customer);

                return array_merge(
                    $this->buyerSnapshotArray($type, $customer),
                    ['seller_id' => null]
                );
            }
        }

        $manualName = trim((string) $request->input('buyer_name', ''));
        if ($manualName !== '' || $request->filled('buyer_phone') || $request->filled('buyer_address')) {
            $type = in_array($requestedType, ['trader', 'customer', 'unknown', 'seller'], true)
                ? $requestedType
                : 'unknown';

            return [
                'buyer_type' => $type,
                'buyer_id' => null,
                'buyer_name' => $manualName !== '' ? $manualName : '-',
                'buyer_phone' => $request->input('buyer_phone'),
                'buyer_address' => $request->input('buyer_address'),
            ];
        }

        if ($projectId) {
            $project = Project::with('partnership.customer')->find($projectId);
            $customer = $project?->partnership?->customer;
            if ($customer instanceof Customer) {
                $type = ($saleType === 'project')
                    ? 'trader'
                    : (in_array($requestedType, ['trader', 'customer'], true)
                        ? $requestedType
                        : $this->inferBuyerTypeFromCustomer($customer));

                return $this->buyerSnapshotArray($type, $customer);
            }
        }

        if (in_array($requestedType, ['trader', 'customer', 'unknown'], true)) {
            return $this->buyerSnapshotArray($requestedType);
        }

        return $this->buyerSnapshotArray('unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePaymentBoxForStorage(Request $request, bool $allowValueWithoutBox = false): array
    {
        $payload = ['status' => 'active'];
        $hasPaymentBoxId = $request->filled('payment_box_id');

        if ($request->has('payment_box_value') && ($hasPaymentBoxId || $allowValueWithoutBox)) {
            $payload['payment_box_value'] = max(0, (float) $request->input('payment_box_value'));
        }

        if (! $hasPaymentBoxId) {
            return $payload;
        }

        $box = Box::find($request->input('payment_box_id'));
        $name = trim((string) $request->input('payment_box_name', ''));
        if ($name === '' && $box) {
            $name = (string) ($box->name ?? '');
        }

        return array_merge($payload, [
            'payment_box_id' => (int) $request->input('payment_box_id'),
            'payment_box_name' => $name !== '' ? $name : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentBoxInvoiceFields(InstantSale $sale): array
    {
        $boxName = $sale->payment_box_name;
        if (($boxName === null || $boxName === '') && $sale->relationLoaded('paymentBox') && $sale->paymentBox) {
            $boxName = $sale->paymentBox->name;
        }

        return [
            'payment_box_id' => $sale->payment_box_id,
            'payment_box_name' => $boxName,
            'payment_box_value' => $sale->payment_box_value,
            ...$this->instantSalePaymentAmounts($sale),
        ];
    }

    /**
     * @return array{paid_amount: float, remaining_amount: float}
     */
    private function instantSalePaymentAmounts(InstantSale $sale): array
    {
        $total = round((float) $sale->total_cost, 2);
        $paid = round((float) ($sale->payment_box_value ?? 0), 2);
        if ($paid > $total) {
            $paid = $total;
        }

        return [
            'paid_amount' => $paid,
            'remaining_amount' => max(0, round($total - $paid, 2)),
        ];
    }

  /**
     * @return \Illuminate\Support\Collection<int, InstantSale>
     */
    private function stockLinesForSale(InstantSale $mainSale)
    {
        if ($this->isAdjustmentInstantSale($mainSale)) {
            return collect();
        }

        $lines = collect();

        if ($mainSale->offer_package_id === null && $mainSale->product_id) {
            $lines->push($mainSale);
        }

        foreach ($mainSale->subProducts as $sub) {
            if (! $sub->isCancelled()) {
                $lines->push($sub);
            }
        }

        return $lines;
    }

    private function isAdjustmentInstantSale(InstantSale $sale): bool
    {
        return ($sale->sale_kind ?? self::SALE_KIND_REGULAR) === self::SALE_KIND_ADJUSTMENT;
    }

    private function saleLineQuantity(InstantSale $line): int
    {
        return max(0, (int) round((float) ($line->quantity ?? 0)));
    }

  /**
     * Restore stock for one invoice line only once (exact line quantity).
     */
    private function restoreStockForSaleLine(InstantSale $line, bool $force = false): void
    {
        if (! $force && $this->saleLineStockAlreadyRestored($line)) {
            return;
        }

        $quantity = $this->saleLineQuantity($line);
        $productId = (int) $line->product_id;

        if ($productId <= 0 || $quantity <= 0) {
            $this->markSaleLineStockRestored($line);

            return;
        }

        $product = Product::withTrashed()->find($productId);
        if (! $product instanceof Product) {
            $this->markSaleLineStockRestored($line);

            return;
        }

        app(ProductStockService::class)->restoreForSale(
            product: $product,
            quantity: $quantity,
            sizeColorId: $line->size_color_id ? (int) $line->size_color_id : null,
            sizeId: $line->size_id ? (int) $line->size_id : null,
            referenceType: 'instant_sale',
            referenceId: (int) $line->id,
            userId: auth()->id() ? (int) auth()->id() : null,
        );

        $this->markSaleLineStockRestored($line);
    }

    private function saleLineStockAlreadyRestored(InstantSale $line): bool
    {
        if (Schema::hasColumn('instant_sales', 'stock_restored') && $line->stock_restored) {
            return true;
        }

        return false;
    }

    private function markSaleLineStockRestored(InstantSale $line): void
    {
        if (! Schema::hasColumn('instant_sales', 'stock_restored')) {
            return;
        }

        $line->update(['stock_restored' => true]);
    }

    private function formatQtyNumber(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    private function formatMoneyNumber(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array{
     *     size_color_id: int|null,
     *     size_id: int|null,
     *     size: string|null,
     *     color_ar: string|null,
     *     variant_label: string|null,
     *     size_label: string|null,
     *     color_label: string|null
     * }
     */
    private function formatInstantSaleVariantFields(?InstantSale $line): array
    {
        if (! $line instanceof InstantSale) {
            return [
                'size_color_id' => null,
                'size_id' => null,
                'size' => null,
                'color_ar' => null,
                'variant_label' => null,
                'size_label' => null,
                'color_label' => null,
            ];
        }

        $sizeLabel = $line->relationLoaded('size') ? $line->size?->size : null;
        if (($sizeLabel === null || $sizeLabel === '') && $line->relationLoaded('sizeColor')) {
            $sizeLabel = $line->sizeColor?->size?->size;
        }

        if (($sizeLabel === null || $sizeLabel === '') && $line->size_color_id) {
            $line->loadMissing('sizeColor.size');
            $sizeLabel = $line->sizeColor?->size?->size;
        }

        $colorAr = $line->relationLoaded('sizeColor') ? $line->sizeColor?->colorAr : null;
        if (($colorAr === null || $colorAr === '') && $line->size_color_id) {
            $line->loadMissing('sizeColor');
            $colorAr = $line->sizeColor?->colorAr;
        }

        $variantLabel = null;
        $parts = array_values(array_filter([
            is_string($sizeLabel) ? trim($sizeLabel) : null,
            is_string($colorAr) ? trim($colorAr) : null,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($parts !== []) {
            $variantLabel = implode(' / ', $parts);
        }

        return [
            'size_color_id' => $line->size_color_id ? (int) $line->size_color_id : null,
            'size_id' => $line->size_id ? (int) $line->size_id : null,
            'size' => $sizeLabel,
            'color_ar' => $colorAr,
            'variant_label' => $variantLabel,
            'size_label' => $sizeLabel,
            'color_label' => $colorAr,
        ];
    }

    /**
     * Old app builds only render the product name — append size/color for backward compatibility.
     *
     * @param  array{variant_label?: string|null}  $variantFields
     */
    private function appendVariantToDisplayName(?string $name, array $variantFields): string
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '') {
            $name = 'منتج';
        }

        $variant = $variantFields['variant_label'] ?? null;
        if (! is_string($variant) || trim($variant) === '') {
            return $name;
        }

        $variant = trim($variant);
        $suffix = ' — '.$variant;
        if (str_ends_with($name, $suffix) || str_contains($name, $suffix)) {
            return $name;
        }

        return $name.$suffix;
    }

    /**
     * @param  array{variant_label?: string|null}  $variantFields
     * @return array{product: string, product_base: string}
     */
    private function formatInstantSaleProductDisplay(?string $baseName, array $variantFields): array
    {
        $base = trim((string) ($baseName ?? ''));
        if ($base === '') {
            $base = 'منتج';
        }

        return [
            'product_base' => $base,
            'product' => $this->appendVariantToDisplayName($base, $variantFields),
        ];
    }

    /**
     * @param  array{variant_label?: string|null}  $variantFields
     * @return array{product_name: string, product_name_base: string}
     */
    private function formatInstantSaleSubProductDisplay(?string $baseName, array $variantFields): array
    {
        $display = $this->formatInstantSaleProductDisplay($baseName, $variantFields);

        return [
            'product_name_base' => $display['product_base'],
            'product_name' => $display['product'],
        ];
    }

    private function clampBoxLogNote(string $note, int $max = 500): string
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) <= $max) {
            return $note;
        }

        return mb_substr($note, 0, $max - 3).'...';
    }

    private function instantSaleInvoiceLinesSummary(InstantSale $mainSale): string
    {
        $mainSale->loadMissing(['product', 'subProducts.product', 'offerPackage']);
        $parts = [];

        if ($mainSale->offer_package_id) {
            $packageName = $mainSale->offerPackage?->name ?? 'باكيج';
            $parts[] = $packageName.' × '.$this->saleLineQuantity($mainSale);
        } else {
            $mainName = $mainSale->product?->nameAr ?? 'منتج';
            $parts[] = $mainName.' × '.$this->saleLineQuantity($mainSale);
        }

        foreach ($mainSale->subProducts as $sub) {
            if ($sub->isCancelled()) {
                continue;
            }
            $subName = $sub->product?->nameAr ?? 'منتج';
            $parts[] = $subName.' × '.$this->saleLineQuantity($sub);
        }

        if (count($parts) === 0) {
            return 'منتج';
        }

        if (count($parts) > 4) {
            return count($parts).' منتج';
        }

        $summary = implode(' | ', $parts);
        if (mb_strlen($summary) > 220) {
            return count($parts).' منتج';
        }

        return $summary;
    }

    private function instantSaleBoxLogNote(InstantSale $sale, string $action): string
    {
        $root = $sale->parent_id
            ? (InstantSale::with(['product', 'subProducts.product'])->find($sale->parent_id) ?? $sale)
            : $sale;
        $root->loadMissing(['createdByUser', 'salesDailySession.user']);

        $linesSummary = $this->instantSaleInvoiceLinesSummary($root);
        $amount = (float) ($root->payment_box_value ?? 0);
        $amountLabel = $this->formatMoneyNumber(
            $amount > 0 ? $amount : (float) ($root->total_cost ?? 0)
        );

        $note = match ($action) {
            'receive' => sprintf(
                'قبض — بيع فوري #%d | %s | مبلغ: %s',
                $root->id,
                $linesSummary,
                $amountLabel
            ),
            'cancel' => sprintf(
                'عكس قبض — إلغاء بيع فوري #%d | %s | مبلغ: %s',
                $root->id,
                $linesSummary,
                $amountLabel
            ),
            'edit_add' => sprintf('تعديل بيع فوري #%d — زيادة مبلغ في الصندوق', $root->id),
            'edit_minus' => sprintf('تعديل بيع فوري #%d — تخفيض مبلغ من الصندوق', $root->id),
            default => sprintf('بيع فوري #%d | %s', $root->id, $linesSummary),
        };

        if ($root->salesDailySession
            && $root->createdByUser
            && (int) $root->salesDailySession->user_id !== (int) $root->created_by) {
            $note .= sprintf(
                ' | المنفذ: %s | صاحب الصندوق: %s',
                $root->createdByUser->name ?? 'موظف',
                $root->salesDailySession->user?->name ?? 'غير محدد'
            );
        }

        return $this->clampBoxLogNote($note);
    }

    /**
     * After sale is saved, enrich the payment box log created during receive step.
     */
    private function linkPaymentBoxLogToInstantSale(InstantSale $sale): void
    {
        if (! $sale->payment_box_id) {
            return;
        }

        $amount = (float) ($sale->payment_box_value ?? 0);
        if ($amount <= 0) {
            return;
        }

        $note = $this->instantSaleBoxLogNote($sale, 'receive');
        $description = 'قبض — بيع فوري #'.$sale->id;

        $query = BoxLog::query()
            ->where('box_id', $sale->payment_box_id)
            ->where('created_at', '>=', now()->subMinutes(10));

        if (Schema::hasColumn('box_logs', 'type')) {
            $query->where('type', 'add');
        }

        if (Schema::hasColumn('box_logs', 'value')) {
            $query->where(function ($q) use ($amount) {
                $q->where('value', $amount)
                    ->orWhere('value', (string) $amount);
            });
        }

        $log = $query->orderByDesc('id')->first();

        if ($log) {
            $log->update([
                'description' => $description,
                'note' => $note,
            ]);
            return;
        }

        $box = Box::lockForUpdate()->find($sale->payment_box_id);
        if (! $box) {
            return;
        }

        $box->total = round((float) ($box->total ?? 0) + $amount, 2);
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            $description,
            'add',
            $amount,
            $note
        );
    }

    /**
     * Reverse stock/box for an existing instant sale before replacing its lines.
     */
    private function prepareInstantSaleReplacement(int $mainSaleId, bool $reverseBox = true): void
    {
        $existing = InstantSale::query()
            ->whereNull('parent_id')
            ->with('subProducts')
            ->lockForUpdate()
            ->findOrFail($mainSaleId);

        if ($existing->isCancelled()) {
            throw ValidationException::withMessages([
                'instant_sale_id' => [__('messages.instant_sale_already_cancelled')],
            ]);
        }

        if ($reverseBox) {
            $this->reverseBoxForCancelledSale($existing);
        }

        foreach ($this->stockLinesForSale($existing) as $line) {
            $this->restoreStockForSaleLine($line, force: true);
        }

        if ($existing->offer_package_id) {
            $package = OfferPackage::query()->lockForUpdate()->find($existing->offer_package_id);
            if ($package) {
                app(OfferPackageService::class)->restorePackageQuantity(
                    $package,
                    $this->saleLineQuantity($existing)
                );
            }
        }

        $existing->subProducts()->delete();
    }

    private function isClosedDailySessionSale(?InstantSale $sale): bool
    {
        if (! $sale || ! $sale->sales_daily_session_id) {
            return false;
        }

        $session = $sale->relationLoaded('salesDailySession')
            ? $sale->salesDailySession
            : $sale->salesDailySession()->first();

        return $session?->isClosed() ?? false;
    }

    private function closedDayEditMode(Request $request, ?InstantSale $existing): ?string
    {
        if (! $this->isClosedDailySessionSale($existing)) {
            return null;
        }

        $mode = trim((string) $request->input('closed_day_edit_mode', ''));
        if ($mode === '') {
            throw ValidationException::withMessages([
                'closed_day_edit_mode' => [
                    'الفاتورة من صندوق مغلق. اختر نوع التعديل: تصحيح إداري أو تسوية مالية اليوم.',
                ],
            ]);
        }

        if (! in_array($mode, ['administrative_correction', 'today_financial_settlement'], true)) {
            throw ValidationException::withMessages([
                'closed_day_edit_mode' => ['نوع تعديل الفاتورة المغلقة غير صالح.'],
            ]);
        }

        $reason = trim((string) $request->input('closed_day_edit_reason', ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'closed_day_edit_reason' => ['سبب تعديل فاتورة من صندوق مغلق مطلوب.'],
            ]);
        }

        return $mode;
    }

    private function applyClosedDayPaymentDelta(
        Request $request,
        InstantSale $existing,
        InstantSale $updated
    ): void {
        if (! $request->has('payment_box_value')) {
            return;
        }

        $oldPaid = round((float) ($existing->payment_box_value ?? 0), 2);
        $newPaid = round((float) ($updated->payment_box_value ?? 0), 2);
        $delta = round($newPaid - $oldPaid, 2);

        if (abs($delta) <= 0.0001) {
            return;
        }

        $boxId = (int) $request->input('payment_box_id', 0);
        if ($boxId <= 0) {
            throw ValidationException::withMessages([
                'payment_box_id' => ['حدد صندوق اليوم لتسجيل فرق الدفع.'],
            ]);
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        app(SalesDailySessionService::class)->assertDailyBoxOwnedByUser($request->user(), $box);

        $box->total = round((float) $box->total + $delta, 2);
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            $delta >= 0
                ? 'إضافة — تسوية دفع فاتورة مغلقة'
                : 'سحب — تسوية دفع فاتورة مغلقة',
            $delta >= 0 ? 'add' : 'minus',
            abs($delta),
            sprintf(
                'تسوية مالية اليوم لفاتورة بيع فوري #%d — فرق الدفع: %s — السبب: %s',
                $updated->id,
                number_format($delta, 2, '.', ''),
                trim((string) $request->input('closed_day_edit_reason', ''))
            )
        );
    }

    private function reverseBoxForCancelledSale(InstantSale $sale): void
    {
        $boxId = $sale->payment_box_id;
        if (! $boxId && ! empty($sale->payment_box_name)) {
            $boxId = Box::where('name', $sale->payment_box_name)->value('id');
        }

        // Reverse only the amount recorded on the invoice — never total_cost fallback.
        $amount = (float) ($sale->payment_box_value ?? 0);

        if (! $boxId || $amount <= 0) {
            return;
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        $note = $this->instantSaleBoxLogNote($sale, 'cancel');

        $box->total = (float) $box->total - $amount;
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            'سحب — عكس قبض بيع فوري',
            'minus',
            -$amount,
            $note
        );
    }

    private function markInstantSaleCancelled(InstantSale $sale): void
    {
        $payload = ['cancelled_at' => now()];
        if (Schema::hasColumn('instant_sales', 'status')) {
            $payload['status'] = 'cancelled';
        }
        $sale->update($payload);
    }

    /**
     * Keep only attributes that exist on instant_sales (safe if migrations pending).
     */
    /**
     * @return array<string, int>
     */
    private function auditFieldsForCreate(): array
    {
        $userId = auth()->id();
        if (! $userId || ! Schema::hasColumn('instant_sales', 'created_by')) {
            return [];
        }

        return ['created_by' => (int) $userId];
    }

    /**
     * @return array<string, int>
     */
    private function auditFieldsForUpdate(): array
    {
        $userId = auth()->id();
        if (! $userId || ! Schema::hasColumn('instant_sales', 'updated_by')) {
            return [];
        }

        return ['updated_by' => (int) $userId];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInstantSaleAuditFields(InstantSale $sale): array
    {
        if (! Schema::hasColumn('instant_sales', 'created_by')) {
            return [];
        }

        return [
            'created_by' => $sale->created_by,
            'created_by_name' => $sale->createdByUser?->name,
            'updated_by' => $sale->updated_by,
            'updated_by_name' => $sale->updatedByUser?->name,
        ];
    }

    private function sanitizeInstantSaleAttributes(array $data): array
    {
        $fillable = (new InstantSale)->getFillable();
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $fillable, true)) {
                continue;
            }
            if (! Schema::hasColumn('instant_sales', $key)) {
                if ($key === 'cost' && Schema::hasColumn('instant_sales', 'maintenance_cost')) {
                    $sanitized['maintenance_cost'] = $value;
                }
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @return array<int, array{text: string, amount: float}>
     */
    private function normalizeInstantSaleNotes(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $notes = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? $item['note'] ?? $item['description'] ?? ''));
            $amount = max(0, (float) ($item['amount'] ?? $item['price'] ?? $item['value'] ?? 0));
            if ($text === '' && $amount <= 0) {
                continue;
            }

            $notes[] = [
                'text' => $text,
                'amount' => round($amount, 2),
            ];
        }

        return $notes;
    }

    private function instantSaleNotesTotal(array $notes): float
    {
        return round(array_reduce(
            $notes,
            fn (float $sum, array $note) => $sum + (float) ($note['amount'] ?? 0),
            0.0
        ), 2);
    }

    private function instantSaleNotesText(array $notes, ?string $fallback = null): ?string
    {
        $parts = collect($notes)
            ->map(fn (array $note) => trim((string) ($note['text'] ?? '')))
            ->filter()
            ->values()
            ->all();

        if ($parts !== []) {
            return implode("\n", $parts);
        }

        $fallback = trim((string) $fallback);

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * @return array{type: string, type_label_ar: string, name: string, phone: ?string, address: ?string, id: int|null}
     */
    private function resolveInvoiceBuyer(InstantSale $sale): array
    {
        $unknown = [
            'type' => 'unknown',
            'type_label_ar' => 'غير محدد',
            'name' => '-',
            'phone' => null,
            'address' => null,
            'id' => null,
        ];

        // A) Persisted snapshot on instant_sales
        if (! empty($sale->buyer_type)) {
            return [
                'type' => $sale->buyer_type,
                'type_label_ar' => $this->buyerTypeLabelAr($sale->buyer_type),
                'name' => $sale->buyer_name ?: '-',
                'phone' => $sale->buyer_phone,
                'address' => $sale->buyer_address,
                'id' => $sale->buyer_id,
            ];
        }

        // B) Legacy: project -> partnership -> customer
        $customer = $sale->project?->partnership?->customer;

        if ($sale->type === 'project' || $sale->project_id) {
            if ($customer instanceof Customer) {
                return [
                    'type' => 'trader',
                    'type_label_ar' => 'تاجر',
                    'name' => $customer->name ?: '-',
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'id' => $customer->id,
                ];
            }

            if ($sale->project) {
                return [
                    'type' => 'trader',
                    'type_label_ar' => 'تاجر',
                    'name' => $sale->project->name ?: '-',
                    'phone' => null,
                    'address' => null,
                    'id' => $sale->project_id,
                ];
            }
        }

        if ($customer instanceof Customer) {
            $isTrader = $this->inferBuyerTypeFromCustomer($customer) === 'trader';

            return [
                'type' => $isTrader ? 'trader' : 'customer',
                'type_label_ar' => $isTrader ? 'تاجر' : 'زبون',
                'name' => $customer->name ?: '-',
                'phone' => $customer->phone,
                'address' => $customer->address,
                'id' => $customer->id,
            ];
        }

        return $unknown;
    }

    private function resolveSalesDailySessionForStore(Request $request, bool $isAdjustmentSale): ?SalesDailySession
    {
        if ($isAdjustmentSale) {
            return null;
        }

        $overrideId = (int) $request->attributes->get('sales_daily_session_override_id', 0);
        if ($overrideId > 0) {
            $session = SalesDailySession::query()->findOrFail($overrideId);
            if (! $session->isOpen()) {
                throw ValidationException::withMessages([
                    'session' => [__('messages.sales_daily_day_closed')],
                ]);
            }

            return $session;
        }

        return app(SalesDailySessionService::class)->assertCanCreateSale($request->user());
    }

    /**
     * @param  array<string, mixed>  $paymentBoxPayload
     * @return array<string, mixed>
     */
    private function movePaymentBoxPayloadToSession(array $paymentBoxPayload, ?SalesDailySession $session): array
    {
        if (! $session) {
            return $paymentBoxPayload;
        }

        $sessionService = app(SalesDailySessionService::class);
        $boxId = (int) ($paymentBoxPayload['payment_box_id'] ?? 0);
        $currentBox = $boxId > 0 ? Box::query()->find($boxId) : null;

        if (
            $currentBox
            && $currentBox->isDailySalesBox()
            && $sessionService->dailyBoxBelongsToSession($currentBox, $session)
        ) {
            $paymentBoxPayload['payment_box_name'] = $paymentBoxPayload['payment_box_name']
                ?? $currentBox->name;

            return $paymentBoxPayload;
        }

        $currency = $currentBox?->currency ?: 'شيكل';
        $targetBox = $sessionService->dailyBoxForSessionCurrency($session, $currency)
            ?? $sessionService->dailyBoxForSessionCurrency($session, 'شيكل')
            ?? $sessionService->dailyBoxForSessionCurrency($session);

        if (! $targetBox) {
            return $paymentBoxPayload;
        }

        $paymentBoxPayload['payment_box_id'] = (int) $targetBox->id;
        $paymentBoxPayload['payment_box_name'] = $targetBox->name;

        return $paymentBoxPayload;
    }

public function store(Request $request)
 {
    $replaceId = 0;

    try{
    $replaceId = (int) $request->attributes->get('replace_instant_sale_id', 0);
    $existingReplaceSale = $replaceId > 0
        ? InstantSale::query()
            ->whereNull('parent_id')
            ->with('salesDailySession')
            ->findOrFail($replaceId)
        : null;
    $saleKind = $this->resolveSaleKind($request);
    if ($replaceId > 0 && ! $request->has('sale_kind')) {
        $existingKind = $existingReplaceSale?->sale_kind;
        if ($existingKind === self::SALE_KIND_ADJUSTMENT) {
            $saleKind = self::SALE_KIND_ADJUSTMENT;
        }
    }
    $isAdjustmentSale = $this->isAdjustmentSaleKind($saleKind);
    $dailySession = $this->resolveSalesDailySessionForStore($request, $isAdjustmentSale);

    if ($request->input('project_id') === '' || $request->input('project_id') === '0') {
        $request->merge(['project_id' => null]);
    }

    $data = $request->validate([
        'offer_package_id' => 'nullable|integer|exists:offer_packages,id',
        'product_id' => 'required_without:offer_package_id|nullable|exists:products,id',
        'quantity' => 'required|numeric|min:1',
        'cost' => 'required|numeric|min:0',
        'discount' => 'required|numeric|min:0',
        'total_cost' => 'required|numeric|min:0',

        'notes' => 'nullable|string',
        'additional_notes' => 'nullable',
        'additional_notes.*.text' => 'nullable|string|max:500',
        'additional_notes.*.amount' => 'nullable|numeric|min:0',

        'type' => 'required|string|in:normal,project',
        'sale_kind' => 'nullable|string|in:regular,adjustment',
        'project_id' => 'nullable|exists:projects,id',

        'other_products' => 'nullable|array',
        'other_products.*.product_id' => 'required|exists:products,id',
        'other_products.*.size_color_id' => 'nullable|integer|exists:size_colors,id',
        'other_products.*.size_id' => 'nullable|integer|exists:sizes,id',
        'other_products.*.cost' => 'required|numeric|min:0',
        'other_products.*.quantity' => 'required|numeric|min:1',
        'other_products.*.type' => 'required|string|in:normal,project',
        'other_products.*.project_id' => 'nullable|exists:projects,id',

        'size_color_id' => 'nullable|integer|exists:size_colors,id',
        'size_id' => 'nullable|integer|exists:sizes,id',

        'buyer_type' => 'nullable|string|in:trader,customer,unknown,seller',
        'buyer_id' => 'nullable|integer|exists:customers,id',
        'buyer_name' => 'nullable|string|max:255',
        'buyer_phone' => 'nullable|string|max:50',
        'buyer_address' => 'nullable|string|max:500',

        'payment_box_id' => 'nullable|integer|exists:boxes,id',
        'payment_box_name' => 'nullable|string|max:255',
        'payment_box_value' => 'nullable|numeric|min:0',
        'seller_id' => 'nullable|integer|exists:sellers,id',
        'closed_day_edit_mode' => 'nullable|string|in:administrative_correction,today_financial_settlement',
        'closed_day_edit_reason' => 'nullable|string|max:500',

    ]);

        $closedDayEditMode = $this->closedDayEditMode($request, $existingReplaceSale);
        $isClosedDayAdministrativeCorrection = $closedDayEditMode === 'administrative_correction';
        $isClosedDayFinancialSettlement = $closedDayEditMode === 'today_financial_settlement';

        $otherNames = [];


        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $buyerPayload = $this->resolveBuyerForStorage(
            $request,
            $projectId,
            $data['type'] ?? null
        );
        $paymentBoxPayload = $this->resolvePaymentBoxForStorage(
            $request,
            $isClosedDayAdministrativeCorrection || $isClosedDayFinancialSettlement
        );
        if ($isAdjustmentSale) {
            $paymentBoxPayload = [
                'payment_box_id' => null,
                'payment_box_name' => null,
                'payment_box_value' => 0,
                'status' => 'active',
            ];
        } else {
            $paymentBoxPayload = $this->movePaymentBoxPayloadToSession($paymentBoxPayload, $dailySession);
            if (empty($paymentBoxPayload['payment_box_id'])) {
                $dailyBox = app(SalesDailySessionService::class)->dailyBoxForSessionCurrency($dailySession, 'شيكل')
                    ?? app(SalesDailySessionService::class)->dailyBoxForCurrency($request->user(), 'شيكل');
                $paymentBoxPayload['payment_box_id'] = (int) $dailyBox->id;
                $paymentBoxPayload['payment_box_name'] = $dailyBox->name;
                if ($request->has('payment_box_value')) {
                    $paymentBoxPayload['payment_box_value'] = max(
                        0,
                        (float) $request->input('payment_box_value')
                    );
                }
            }
            $this->assertDailySalesPaymentBox($request, $paymentBoxPayload);
        }
        if (($isClosedDayAdministrativeCorrection || $isClosedDayFinancialSettlement) && $existingReplaceSale) {
            $paymentBoxPayload['payment_box_id'] = $existingReplaceSale->payment_box_id;
            $paymentBoxPayload['payment_box_name'] = $existingReplaceSale->payment_box_name;
            if (! array_key_exists('payment_box_value', $paymentBoxPayload)) {
                $paymentBoxPayload['payment_box_value'] = $existingReplaceSale->payment_box_value;
            }
        }
        $additionalNotes = $this->normalizeInstantSaleNotes($request->input('additional_notes', []));
        $additionalNotesTotal = $this->instantSaleNotesTotal($additionalNotes);

        if ($isAdjustmentSale && ! empty($data['offer_package_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'فاتورة التعويض لا تدعم الباكيجات حالياً. اختر المنتجات كسطور تعويض.',
            ], 200);
        }

        if (! empty($data['offer_package_id'])) {
            $data['additional_notes'] = $additionalNotes;
            $data['additional_notes_total'] = $additionalNotesTotal;

            return $this->storeOfferPackageSale(
                $request,
                $data,
                $buyerPayload,
                $paymentBoxPayload,
                $dailySession,
                $existingReplaceSale,
                $closedDayEditMode
            );
        }

        // Save main instant sale
        $auditAndSession = $replaceId > 0
            ? $this->auditFieldsForUpdate()
            : array_merge(
                $this->auditFieldsForCreate(),
                ['sales_daily_session_id' => $dailySession?->id]
            );

        $mainData = $this->sanitizeInstantSaleAttributes(
            collect($data)
                ->except([
                    'other_products',
                    'buyer_type',
                    'buyer_id',
                    'buyer_name',
                    'buyer_phone',
                    'buyer_address',
                    'payment_box_id',
                    'payment_box_name',
                    'payment_box_value',
                    'seller_id',
                    'additional_notes',
                ])
                ->merge($buyerPayload)
                ->merge($paymentBoxPayload)
                ->merge($auditAndSession)
                ->toArray()
        );
        $mainData['sale_kind'] = $saleKind;
        $mainData['additional_notes'] = $additionalNotes;
        $mainData['notes'] = $this->instantSaleNotesText($additionalNotes, $mainData['notes'] ?? null);

        $mainLineTotal = (float) $mainData['cost'] * (float) $mainData['quantity'];
        $otherProductsTotal = 0.0;
        foreach ($data['other_products'] ?? [] as $item) {
            $otherProductsTotal += (float) $item['cost'] * (float) $item['quantity'];
        }
        $mainData['total_cost'] = max(
            0,
            round($mainLineTotal + $otherProductsTotal + $additionalNotesTotal - (float) ($mainData['discount'] ?? 0), 2)
        );

        // The client may restore an auto-saved payment amount that was captured
        // before a discount changed the invoice total. Never post more cash to
        // the box than the final invoice total.
        if (array_key_exists('payment_box_value', $mainData)) {
            $mainData['payment_box_value'] = min(
                $mainData['total_cost'],
                max(0, round((float) $mainData['payment_box_value'], 2))
            );
            $request->merge([
                'payment_box_value' => $mainData['payment_box_value'],
            ]);
        }

        if ($this->hasRemainingInstantSaleAmount($mainData, $request) && ! $this->hasRequiredDebtBuyer($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.',
                'errors' => [
                    'buyer_id' => ['عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.'],
                    'seller_id' => ['عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.'],
                ],
            ], 200);
        }

        $mainProduct = Product::with('sizes.colorSizes')->findOrFail($mainData['product_id']);
        $stockService = app(ProductStockService::class);

        if ($replaceId > 0) {
            DB::beginTransaction();
            $this->prepareInstantSaleReplacement(
                $replaceId,
                reverseBox: ! ($isClosedDayAdministrativeCorrection || $isClosedDayFinancialSettlement)
            );
            $mainProduct = Product::with('sizes.colorSizes')->findOrFail($mainData['product_id']);
        }

        $mainSaleQuantity = (int) round((float) $request->quantity);
        $mainSizeColorId = isset($data['size_color_id']) ? (int) $data['size_color_id'] : null;
        $mainStockCheck = ['ok' => true, 'size_color_id' => $mainSizeColorId, 'size_id' => $data['size_id'] ?? null];
        if (! $isAdjustmentSale) {
            $mainStockCheck = $stockService->validateSaleStock($mainProduct, $mainSaleQuantity, $mainSizeColorId, allowNegative: true);
            if (! ($mainStockCheck['ok'] ?? false)) {
                if ($replaceId > 0) {
                    DB::rollBack();
                }
                return response()->json([
                    'status' => 'error',
                    'message' => $mainStockCheck['message'] ?? __('messages.cant_sale'),
                ], 200);
            }
        }

        $mainSizeColorId = (int) ($mainStockCheck['size_color_id'] ?? $mainSizeColorId ?: 0);
        if ($mainSizeColorId > 0) {
            $mainData['size_color_id'] = $mainSizeColorId;
            $mainData['size_id'] = $mainStockCheck['size_id'] ?? ($data['size_id'] ?? null);
        }

        if ($request->has('other_products')) {

        foreach ($data['other_products'] as $item) {
            $product = Product::with('sizes.colorSizes')->find($item['product_id']);
            $otherNames[] = $product->nameAr ?? 'بدون اسم';
            $lineQty = (int) round((float) $item['quantity']);
            $lineSizeColorId = isset($item['size_color_id']) ? (int) $item['size_color_id'] : null;
            $lineCheck = ['ok' => true, 'size_color_id' => $lineSizeColorId, 'size_id' => $item['size_id'] ?? null];
            if (! $isAdjustmentSale) {
                $lineCheck = $stockService->validateSaleStock($product, $lineQty, $lineSizeColorId, allowNegative: true);
            }
            if (! ($lineCheck['ok'] ?? false)) {
                    if ($replaceId > 0) {
                        DB::rollBack();
                    }
                    return response()->json([
                        'status'=>'error',
                        'message'=> $lineCheck['message'] ?? __('messages.cant_sale'),
                    ],200);
            } 
        }
    }
        $productProjects = $mainProduct->projects;


        if($mainData['type']==='project' && $productProjects->isEmpty()){
            if ($replaceId > 0) {
                DB::rollBack();
            }
            return response()->json([
                'status'=>'error',
                'message'=>__('messages.cant_be_project_type'),
            ],200);
        }

        if ($replaceId > 0) {
            $mainInstantSale = InstantSale::lockForUpdate()->findOrFail($replaceId);
            $mainInstantSale->update(array_merge($mainData, $this->auditFieldsForUpdate()));
            if (Schema::hasColumn('instant_sales', 'stock_restored')) {
                $mainInstantSale->forceFill(['stock_restored' => false])->save();
            }
            $mainInstantSale = $mainInstantSale->fresh();
        } else {
            $mainInstantSale = InstantSale::create($mainData);
            app(DocumentSerialService::class)->assignPrefixedToModel(
                $mainInstantSale,
                DocumentSerialService::TYPE_INSTANT_SALE_INVOICE,
                'SAL-',
                'serial_number'
            );
        }

        if ($isClosedDayFinancialSettlement && $existingReplaceSale) {
            $this->applyClosedDayPaymentDelta($request, $existingReplaceSale, $mainInstantSale->fresh());
        }

        if (! $isClosedDayAdministrativeCorrection && ! $isClosedDayFinancialSettlement) {
            $this->linkPaymentBoxLogToInstantSale(
                $mainInstantSale->fresh(['product', 'subProducts.product'])
            );
        }

        app(DebtLedgerService::class)->syncInstantSaleToLedger(
            $mainInstantSale->fresh(['product', 'offerPackage', 'paymentBox'])
        );

        if (! $isAdjustmentSale) {
            $stockImpact = $stockService->deductForSale(
                product: $mainProduct,
                quantity: $mainSaleQuantity,
                sizeColorId: $mainSizeColorId > 0 ? $mainSizeColorId : null,
                sizeId: isset($mainData['size_id']) ? (int) $mainData['size_id'] : null,
                referenceType: 'instant_sale',
                referenceId: (int) $mainInstantSale->id,
                userId: auth()->id() ? (int) auth()->id() : null,
                allowNegative: true,
            );
            $this->notifyAdminIfNegativeInstantSaleStock($request, $mainProduct, $mainInstantSale, $stockImpact);
        }

        // Save other  if provided
        if ($request->has('other_products')) {
            foreach ($request->other_products as  $product) {
                $subProduct = Product::with('sizes.colorSizes')->findOrFail($product['product_id']);
                $subProductProjects = $subProduct->projects;


                if($product['type']==='project' && $subProductProjects->isEmpty()){
                    if ($replaceId > 0) {
                        DB::rollBack();
                    }
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.cant_be_project_type'),
                    ],200);
                }        
                $subProjectId = isset($product['project_id']) ? (int) $product['project_id'] : null;
                $lineQty = (int) round((float) $product['quantity']);
                $lineSizeColorId = isset($product['size_color_id']) ? (int) $product['size_color_id'] : null;
                $lineCheck = ['ok' => true, 'size_color_id' => $lineSizeColorId, 'size_id' => $product['size_id'] ?? null];
                if (! $isAdjustmentSale) {
                    $lineCheck = $stockService->validateSaleStock($subProduct, $lineQty, $lineSizeColorId, allowNegative: true);
                }
                if (! ($lineCheck['ok'] ?? false)) {
                    if ($replaceId > 0) {
                        DB::rollBack();
                    }
                    return response()->json([
                        'status' => 'error',
                        'message' => $lineCheck['message'] ?? __('messages.cant_sale'),
                    ], 200);
                }

                $lineSizeColorId = (int) ($lineCheck['size_color_id'] ?? $lineSizeColorId ?: 0);

                $subSale = InstantSale::create($this->sanitizeInstantSaleAttributes(array_merge([
                    'product_id' => $product['product_id'],
                    'size_color_id' => $lineSizeColorId > 0 ? $lineSizeColorId : null,
                    'size_id' => $lineCheck['size_id'] ?? ($product['size_id'] ?? null),
                    'cost' => $product['cost'],
                    'quantity' => $lineQty,
                    'discount' => 0,
                    'total_cost' => (float) $product['cost'] * $lineQty,
                    'parent_id' => $mainInstantSale->id,
                    'type' => $product['type'],
                    'sale_kind' => $saleKind,
                    'project_id' => $product['project_id'] ?? null,
                ], $buyerPayload)));

                if (! $isAdjustmentSale) {
                    $stockImpact = $stockService->deductForSale(
                        product: $subProduct,
                        quantity: $lineQty,
                        sizeColorId: $lineSizeColorId > 0 ? $lineSizeColorId : null,
                        sizeId: $lineCheck['size_id'] ?? null,
                        referenceType: 'instant_sale',
                        referenceId: (int) $subSale->id,
                        userId: auth()->id() ? (int) auth()->id() : null,
                        allowNegative: true,
                    );
                    $this->notifyAdminIfNegativeInstantSaleStock($request, $subProduct, $subSale, $stockImpact);
                }
            }
        }
     $logDescription = ($isAdjustmentSale ? "اضافة فاتورة تعويض للمنتج: " : "اضافة بيع فوري جديد للمنتج: ") . ($mainInstantSale->product->nameAr ?? 'بدون اسم');
     if(count($otherNames)>0){
             $logDescription .= " مع منتجات إضافية: " . implode(", ", $otherNames);

     }
     $logDescription .= " بإجمالي تكلفة: " . $mainInstantSale->total_cost??0;

        Logs::createLog(
            $isAdjustmentSale
                ? ($replaceId > 0 ? 'تعديل فاتورة تعويض' : 'اضافة فاتورة تعويض')
                : ($replaceId > 0 ? 'تعديل بيع فوري' : 'اضافة بيع فوري جديد'),
        $logDescription.($closedDayEditMode
            ? ' — نوع تعديل فاتورة مغلقة: '.$closedDayEditMode.' — السبب: '.trim((string) $request->input('closed_day_edit_reason', ''))
            : ''),
        'instant_sales');

        app(EmployeeActivityLogger::class)->log(
            null,
            $request->user(),
            'sales',
            $replaceId > 0 ? 'updated_instant_sale' : 'created_instant_sale',
            $replaceId > 0 ? 'تعديل بيع فوري' : 'إنشاء بيع فوري',
            $logDescription,
            $mainInstantSale,
            (float) ($mainInstantSale->total_cost ?? 0),
            [
                'invoice_number' => $mainInstantSale->serial_number,
                'buyer_name' => $mainInstantSale->buyer_name,
                'payment_box_value' => $mainInstantSale->payment_box_value,
            ]
        );

        if ($replaceId <= 0 && ! $isAdjustmentSale) {
            app(SalesDailySessionService::class)->notifyExternalSaleMovement(
                $request->user(),
                $dailySession,
                'instant',
                (int) $mainInstantSale->id,
                (float) ($mainInstantSale->payment_box_value ?? 0),
                $mainInstantSale->payment_box_id ? (int) $mainInstantSale->payment_box_id : null
            );
        }

        if ($replaceId > 0) {
            DB::commit();
        }

        return response()->json([
                    'status' => 'success',
                    'message' => $replaceId > 0
                        ? __('messages.instant_sale_updated_successfully')
                        : __('messages.instant_sale_created_successfully'),
                    'instant_sale_id' => $mainInstantSale->id,
                ], 200);

            }

        catch (ValidationException $e) {
            if ($replaceId > 0 && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
            catch (QueryException $e) {
            if ($replaceId > 0 && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('InstantSales::store QueryException', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.create_data_error'),
            ], 200);
        }
        catch (\Exception $e) {
            if ($replaceId > 0 && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('InstantSales::store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }

}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $buyerPayload
     * @param  array<string, mixed>  $paymentBoxPayload
     */
    private function storeOfferPackageSale(
        Request $request,
        array $data,
        array $buyerPayload,
        array $paymentBoxPayload,
        ?\App\Models\SalesDailySession $dailySession,
        ?InstantSale $existingReplaceSale = null,
        ?string $closedDayEditMode = null
    ) {
        $offerPackageService = app(OfferPackageService::class);

        return DB::transaction(function () use ($request, $data, $buyerPayload, $paymentBoxPayload, $offerPackageService, $dailySession, $existingReplaceSale, $closedDayEditMode) {
            $replaceId = (int) $request->attributes->get('replace_instant_sale_id', 0);
            $isClosedDayAdministrativeCorrection = $closedDayEditMode === 'administrative_correction';
            $isClosedDayFinancialSettlement = $closedDayEditMode === 'today_financial_settlement';

            if ($replaceId > 0) {
                $this->prepareInstantSaleReplacement(
                    $replaceId,
                    reverseBox: ! ($isClosedDayAdministrativeCorrection || $isClosedDayFinancialSettlement)
                );
            }

            $package = OfferPackage::query()
                ->where('is_active', true)
                ->with(['items.product'])
                ->lockForUpdate()
                ->findOrFail((int) $data['offer_package_id']);

            $packagesSold = max(1, (int) round((float) $data['quantity']));

            $maxSellable = $offerPackageService->maxSellableQuantity($package);
            if ($packagesSold > $maxSellable) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cant_sale'),
                ], 200);
            }

            $stockCheck = $offerPackageService->validateStockForSale($package, $packagesSold);
            if (! $stockCheck['ok']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $stockCheck['message'] ?? __('messages.cant_sale'),
                ], 200);
            }

            $unitPrice = (float) $package->price;
            $otherProductsTotal = 0.0;
            foreach ($data['other_products'] ?? [] as $item) {
                $otherProductsTotal += (float) $item['cost'] * (float) $item['quantity'];
            }
            $auditAndSession = $replaceId > 0
                ? $this->auditFieldsForUpdate()
                : array_merge(
                    $this->auditFieldsForCreate(),
                    ['sales_daily_session_id' => $dailySession->id]
                );

            $mainData = $this->sanitizeInstantSaleAttributes(array_merge([
                'offer_package_id' => $package->id,
                'product_id' => null,
                'quantity' => $packagesSold,
                'cost' => $unitPrice,
                'discount' => (float) ($data['discount'] ?? 0),
                'total_cost' => max(
                    0,
                    round(
                        ($unitPrice * $packagesSold)
                        + $otherProductsTotal
                        + (float) ($data['additional_notes_total'] ?? 0)
                        - (float) ($data['discount'] ?? 0),
                        2
                    )
                ),
                'notes' => $this->instantSaleNotesText($data['additional_notes'] ?? [], $data['notes'] ?? null),
                'additional_notes' => $data['additional_notes'] ?? [],
                'type' => $data['type'],
                'project_id' => $data['project_id'] ?? null,
            ], $buyerPayload, $paymentBoxPayload, $auditAndSession));

            if ($this->hasRemainingInstantSaleAmount($mainData, $request) && ! $this->hasRequiredDebtBuyer($request)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.',
                    'errors' => [
                        'buyer_id' => ['عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.'],
                        'seller_id' => ['عند وجود مبلغ باقي يجب اختيار زبون أو تاجر.'],
                    ],
                ], 200);
            }

            if ($replaceId > 0) {
                $mainInstantSale = InstantSale::lockForUpdate()->findOrFail($replaceId);
                $mainInstantSale->update(array_merge($mainData, $this->auditFieldsForUpdate()));
                $mainInstantSale = $mainInstantSale->fresh();
            } else {
                $mainInstantSale = InstantSale::create($mainData);
                app(DocumentSerialService::class)->assignPrefixedToModel(
                    $mainInstantSale,
                    DocumentSerialService::TYPE_INSTANT_SALE_INVOICE,
                    'SAL-',
                    'serial_number'
                );
            }

            if ($isClosedDayFinancialSettlement && $existingReplaceSale) {
                $this->applyClosedDayPaymentDelta($request, $existingReplaceSale, $mainInstantSale->fresh());
            }

            if (! $isClosedDayAdministrativeCorrection && ! $isClosedDayFinancialSettlement) {
                $this->linkPaymentBoxLogToInstantSale(
                    $mainInstantSale->fresh(['offerPackage', 'subProducts.product'])
                );
            }

            app(DebtLedgerService::class)->syncInstantSaleToLedger(
                $mainInstantSale->fresh(['product', 'offerPackage', 'paymentBox'])
            );

            foreach ($package->items as $item) {
                $lineQty = (int) $item->quantity * $packagesSold;
                $subProduct = Product::findOrFail($item->product_id);

                InstantSale::create($this->sanitizeInstantSaleAttributes(array_merge([
                    'product_id' => $item->product_id,
                    'cost' => 0,
                    'quantity' => $lineQty,
                    'discount' => 0,
                    'total_cost' => 0,
                    'parent_id' => $mainInstantSale->id,
                    'type' => $data['type'],
                    'project_id' => $data['project_id'] ?? null,
                ], $buyerPayload)));

                $subProduct->stock -= $lineQty;
                $subProduct->save();

                if ((float) $subProduct->stock === 0.0) {
                    $closeout = $subProduct->closeout;
                    if ($closeout) {
                        $closeout->status = 'archived';
                        $closeout->save();
                    }
                }
            }

            $offerPackageService->decrementPackageQuantity($package, $packagesSold);

            $extraProductNames = [];
            if (! empty($data['other_products'])) {
                foreach ($data['other_products'] as $item) {
                    $subProduct = Product::find($item['product_id']);
                    if (! $subProduct) {
                        continue;
                    }
                    if (($subProduct->stock <= 0) || ($item['quantity'] > $subProduct->stock)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => __('messages.cant_sale'),
                        ], 200);
                    }
                }

                foreach ($data['other_products'] as $item) {
                    $subProduct = Product::findOrFail($item['product_id']);
                    $subProductProjects = $subProduct->projects;

                    if ($item['type'] === 'project' && $subProductProjects->isEmpty()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => __('messages.cant_be_project_type'),
                        ], 200);
                    }

                    $lineCost = (float) $item['cost'];
                    $lineQty = (float) $item['quantity'];
                    $lineTotal = $lineCost * $lineQty;

                    InstantSale::create($this->sanitizeInstantSaleAttributes(array_merge([
                        'product_id' => $item['product_id'],
                        'cost' => $lineCost,
                        'quantity' => $lineQty,
                        'discount' => 0,
                        'total_cost' => $lineTotal,
                        'parent_id' => $mainInstantSale->id,
                        'type' => $item['type'],
                        'project_id' => $item['project_id'] ?? null,
                    ], $buyerPayload)));

                    $subProduct->stock -= $lineQty;
                    $subProduct->save();

                    if ((float) $subProduct->stock === 0.0) {
                        $closeout = $subProduct->closeout;
                        if ($closeout) {
                            $closeout->status = 'archived';
                            $closeout->save();
                        }
                    }

                    $extraProductNames[] = $subProduct->nameAr ?? 'بدون اسم';
                }
            }

            $logDescription = 'اضافة بيع فوري لباكيج عرض: '.$package->name
                .' (×'.$packagesSold.') بإجمالي تكلفة: '.($mainInstantSale->total_cost ?? 0);
            if (count($extraProductNames) > 0) {
                $logDescription .= ' مع منتجات إضافية: '.implode(', ', $extraProductNames);
            }

            Logs::createLog(
                $replaceId > 0 ? 'تعديل بيع فوري' : 'اضافة بيع فوري جديد',
                $logDescription,
                'instant_sales'
            );

            app(EmployeeActivityLogger::class)->log(
                null,
                $request->user(),
                'sales',
                $replaceId > 0 ? 'updated_instant_sale' : 'created_instant_sale',
                $replaceId > 0 ? 'تعديل بيع فوري' : 'إنشاء بيع فوري',
                $logDescription,
                $mainInstantSale,
                (float) ($mainInstantSale->total_cost ?? 0),
                [
                    'invoice_number' => $mainInstantSale->serial_number,
                    'buyer_name' => $mainInstantSale->buyer_name,
                    'offer_package_id' => $package->id,
                ]
            );

            if ($replaceId <= 0) {
                app(SalesDailySessionService::class)->notifyExternalSaleMovement(
                    $request->user(),
                    $dailySession,
                    'instant',
                    (int) $mainInstantSale->id,
                    (float) ($mainInstantSale->payment_box_value ?? 0),
                    $mainInstantSale->payment_box_id ? (int) $mainInstantSale->payment_box_id : null
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => $replaceId > 0
                    ? __('messages.instant_sale_updated_successfully')
                    : __('messages.instant_sale_created_successfully'),
                'instant_sale_id' => $mainInstantSale->id,
            ], 200);
        });
    }

    // get the projects of a product for chosing that product in the instant sale
    public function getProjectsOfProduct(Request $request){
        try{
            $request->validate(['product_id'=>'required|exists:products,id']);

            $product = Product::findOrFail($request->product_id);
            $productProjects = $product->projects ;
            $projects = $productProjects->map(function($productProject){
                return [
                    'project_id' => $productProject->project->id,
                    'project_name' => $productProject->project->name,
                ];
            });
            return response()->json([
                'status'=>'success',
                'projects' => $projects,
            ]);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    // get sub sales of parent sale
    public function getSubSales(Request $request){
      try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $subSales =  $sale->subProducts->map(function ($sub) {
                    return [
                        'id' => $sub->id,
                        'product_id' => $sub->product->nameAr,
                        'cost' => $sub->cost,
                        'quantity'=> $sub->quantity,

                    ];
                });
         return response()->json([
            'status'=>'success',
            'sub_sales'=> $subSales,
         ],200);
    }
            catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
    public function attachProjectToProductInSale(Request $request){
       try{
            $request->validate([
                'instant_sale_id'=>'required|exists:instant_sales,id',
                'project_id' => 'required|exists:projects,id',
            ]);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $sale->update(['project_id'=> $request->project_id]);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.sale_attached'),
            ]);

    }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
    public function getInstantSales(Request $request)
{
    try {
            $request->validate([
                'search' => 'nullable|string|max:255',
                'date' => 'nullable|date_format:Y-m-d',
                'sort_direction' => 'nullable|string|in:asc,desc',
            ]);

            $search = trim((string) $request->input('search', ''));
            $date = $request->input('date');
            $normalizedInvoiceSearch = strtoupper(str_replace([' ', '_'], '', $search));
            $invoiceSearchDigits = preg_replace('/\D+/', '', $search) ?? '';
            $isZeroPaddedInvoiceSearch = ctype_digit($search)
                && strlen($search) > 1
                && str_starts_with($search, '0');
            $invoiceSearchPrefix = null;
            if (preg_match('/^(SAL|MNT)-?\d+$/i', $normalizedInvoiceSearch, $invoiceMatch) === 1) {
                $invoiceSearchPrefix = strtoupper($invoiceMatch[1]);
            }
            $looksLikeInvoiceSearch = $search !== ''
                && (
                    $invoiceSearchPrefix !== null
                    || (ctype_digit($search) && strlen($search) <= 7)
                );
            $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc'
                ? 'asc'
                : 'desc';

            $query = InstantSale::query()
                ->whereNull('parent_id')
                ->whereNull('maintenance_id')
            ->with([
                'product:id,nameAr',
                    'offerPackage:id,name',
                    'project:id,name',
                'size',
                'sizeColor.size',
                'subProducts.product:id,nameAr',
                'subProducts.size',
                'subProducts.sizeColor.size',
                    'createdByUser:id,name',
                    'updatedByUser:id,name',
                ]);

            if (! empty($date) && ! $looksLikeInvoiceSearch) {
                $query->whereDate('created_at', $date);
            }

            if ($search !== '') {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term, $search, $invoiceSearchDigits, $invoiceSearchPrefix, $isZeroPaddedInvoiceSearch) {
                    $q->where('buyer_name', 'like', $term)
                        ->orWhere('buyer_phone', 'like', $term)
                        ->orWhere('buyer_address', 'like', $term)
                        ->orWhere('notes', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhereRaw("CONCAT('SAL-', LPAD(id, 7, '0')) LIKE ?", [$term])
                        ->orWhereRaw("CONCAT('MNT-', LPAD(maintenance_id, 6, '0')) LIKE ?", [$term])
                        ->orWhereHas('product', function ($productQuery) use ($term) {
                            $productQuery->where('nameAr', 'like', $term);
                        })
                        ->orWhereHas('project', function ($projectQuery) use ($term) {
                            $projectQuery->where('name', 'like', $term);
                        })
                        ->orWhereHas('subProducts.product', function ($subProductQuery) use ($term) {
                            $subProductQuery->where('nameAr', 'like', $term);
                        })
                        ->orWhereHas('offerPackage', function ($packageQuery) use ($term) {
                            $packageQuery->where('name', 'like', $term);
                        });

                    if (ctype_digit($search)) {
                        $q->orWhere('id', (int) $search)
                            ->orWhere('serial_number', 'like', '%'.$search.'%');
                    }

                    if ($invoiceSearchDigits !== '' && ctype_digit($invoiceSearchDigits)) {
                        $q->orWhere('id', (int) ltrim($invoiceSearchDigits, '0'))
                            ->orWhere('serial_number', 'like', '%'.$invoiceSearchDigits.'%');

                        if ($invoiceSearchPrefix !== 'SAL' && ! $isZeroPaddedInvoiceSearch) {
                            $q->orWhere('maintenance_id', (int) ltrim($invoiceSearchDigits, '0'));
                        }
                    }
                });
            }

            $instantSales = $query
                ->orderBy('created_at', $sortDirection)
                ->orderBy('id', $sortDirection)
            ->get();

        $formatted = $instantSales->map(function ($sale) {
                $buyerLabel = $this->buyerTypeLabelAr($sale->buyer_type ?? 'unknown');
                $isPackageSale = $sale->offer_package_id !== null;
                $packageName = $sale->offerPackage?->name;
                $hasAdditionalProducts = $isPackageSale && $sale->subProducts->contains(
                    fn ($sub) => (float) $sub->cost > 0
                );
                $saleComposition = $isPackageSale
                    ? ($hasAdditionalProducts ? 'mixed' : 'package')
                    : 'product';
                $variantFields = $this->formatInstantSaleVariantFields($isPackageSale ? null : $sale);
                $baseProductName = $isPackageSale
                    ? ($packageName ?? 'باكيج محذوف')
                    : (optional($sale->product)->nameAr ?? 'منتج محذوف');
                $productDisplay = $isPackageSale
                    ? ['product_base' => $baseProductName, 'product' => $baseProductName]
                    : $this->formatInstantSaleProductDisplay($baseProductName, $variantFields);

            return [
                'id' => $sale->id,
                'invoice_number' => (string) ($sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT)),
                'serial_number' => $sale->serial_number,
                'maintenance_id' => $sale->maintenance_id,
                'maintenance_invoice_number' => $sale->maintenance_id
                    ? 'MNT-'.str_pad((string) $sale->maintenance_id, 6, '0', STR_PAD_LEFT)
                    : null,
                'sale_kind' => $sale->sale_kind ?? self::SALE_KIND_REGULAR,
                'sale_kind_label_ar' => ($sale->sale_kind ?? self::SALE_KIND_REGULAR) === self::SALE_KIND_ADJUSTMENT
                    ? 'فاتورة تعويض / تعديل'
                    : 'بيع فوري',
                'sale_type' => $isPackageSale ? 'package' : 'product',
                'sale_composition' => $saleComposition,
                'has_additional_products' => $hasAdditionalProducts,
                'is_package_sale' => $isPackageSale,
                'offer_package_id' => $sale->offer_package_id,
                'package_name' => $packageName,
                ...$productDisplay,
                ...$variantFields,
                'cost' => $sale->cost,
                'total_cost' => $sale->total_cost,
                'quantity' => $sale->quantity,
                'notes' => $sale->notes,
                'date' => optional($sale->created_at)->format('Y-m-d'),
                    'created_at' => optional($sale->created_at)->format('Y-m-d H:i:s'),
                    'buyer_type' => $sale->buyer_type,
                    'buyer_type_label_ar' => $buyerLabel,
                    'buyer_id' => $sale->buyer_id,
                    'buyer_name' => $sale->buyer_name,
                    'buyer_phone' => $sale->buyer_phone,
                    'buyer_address' => $sale->buyer_address,
                    'project_name' => $sale->project?->name,
                    'status' => $sale->status ?? 'active',
                    'cancelled_at' => optional($sale->cancelled_at)->format('Y-m-d H:i:s'),
                    'payment_box_id' => $sale->payment_box_id,
                    'payment_box_name' => $sale->payment_box_name,
                    'payment_box_value' => $sale->payment_box_value,
                    ...$this->instantSalePaymentAmounts($sale),
                'sub_products' => $sale->subProducts->map(function ($sub) use ($isPackageSale) {
                    $lineCost = (float) $sub->cost;
                    $subVariant = $this->formatInstantSaleVariantFields($sub);
                    $subBase = optional($sub->product)->nameAr ?? 'منتج محذوف';

                    return [
                        'id' => $sub->id,
                        ...$this->formatInstantSaleSubProductDisplay($subBase, $subVariant),
                        ...$subVariant,
                        'cost' => $sub->cost,
                        'quantity' => $sub->quantity,
                        'is_package_component' => $isPackageSale && $lineCost <= 0,
                        'is_additional_product' => $isPackageSale && $lineCost > 0,
                    ];
                }),
                ...$this->formatInstantSaleAuditFields($sale),
            ];
        });

        return response()->json([
            'status' => 'success',
            'instant_sales' => $formatted,
                'sort_direction' => $sortDirection,
        ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
    } catch (\Throwable $e) {
        \Log::error('getInstantSales error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
            'debug' => config('app.debug') ? $e->getMessage() : null,
        ], 200);
    }
}

    public function showInstantSale(Request $request){
        try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);
            $instantSale = InstantSale::findOrFail($request->instant_sale_id)
            ->with('product:id,nameAr');

            return response()->json([
                'status'=>'success',
                'instant_sale_details' => $instantSale,
            ],200);
    }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}

public function edit(Request $request)
    {
        try {
            if ($request->filled('instant_sale_id')) {
                $requestedSale = InstantSale::query()->findOrFail((int) $request->input('instant_sale_id'));
                $rootSale = $requestedSale->parent_id
                    ? InstantSale::query()->findOrFail((int) $requestedSale->parent_id)
                    : $requestedSale;
                app(SalesReturnService::class)->assertInstantSaleHasNoActiveDirectReturns($rootSale);
            }
            if ($request->filled('product_id') || $request->filled('offer_package_id')) {
                $request->validate([
                    'instant_sale_id' => 'required|integer|exists:instant_sales,id',
                ]);
                $request->attributes->set(
                    'replace_instant_sale_id',
                    (int) $request->input('instant_sale_id')
                );

                return $this->store($request);
            }

            $data = $request->validate([
                'instant_sale_id' => 'required|exists:instant_sales,id',
            'cost' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:1',
            'total_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'additional_notes' => 'nullable',
            'additional_notes.*.text' => 'nullable|string|max:500',
            'additional_notes.*.amount' => 'nullable|numeric|min:0',
            'payment_box_value' => 'nullable|numeric|min:0',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
            'closed_day_edit_mode' => 'nullable|string|in:administrative_correction,today_financial_settlement',
            'closed_day_edit_reason' => 'nullable|string|max:500',
        ]);

            DB::transaction(function () use ($request, $data) {
                $instantSale = InstantSale::query()
                    ->whereNull('parent_id')
                    ->with(['product', 'subProducts.product', 'salesDailySession'])
                    ->lockForUpdate()
                    ->findOrFail($request->instant_sale_id);
                $existingSnapshot = $instantSale->replicate();
                $existingSnapshot->setAttribute('id', $instantSale->id);
                $existingSnapshot->setRelation('salesDailySession', $instantSale->salesDailySession);
                $closedDayEditMode = $this->closedDayEditMode($request, $instantSale);
                $isClosedDayAdministrativeCorrection = $closedDayEditMode === 'administrative_correction';
                $isClosedDayFinancialSettlement = $closedDayEditMode === 'today_financial_settlement';

                if ($instantSale->isCancelled()) {
                    throw ValidationException::withMessages([
                        'instant_sale_id' => [__('messages.instant_sale_already_cancelled')],
                    ]);
                }

                $isAdjustmentSale = $this->isAdjustmentInstantSale($instantSale);
                $oldQuantity = (float) $instantSale->quantity;
                $newQuantity = (float) $data['quantity'];
                $quantityDelta = $newQuantity - $oldQuantity;

                if (! $isAdjustmentSale && $quantityDelta > 0) {
                    $product = $instantSale->product ?? Product::findOrFail($instantSale->product_id);
                    if ($product->stock < $quantityDelta) {
                        throw ValidationException::withMessages([
                            'quantity' => [__('messages.cant_sale')],
                        ]);
                    }
                    $product->stock -= $quantityDelta;
                    $product->save();
                } elseif (! $isAdjustmentSale && $quantityDelta < 0) {
                    $product = $instantSale->product ?? Product::findOrFail($instantSale->product_id);
                    $product->stock += abs($quantityDelta);
                    $product->save();
                }

                $newTotal = (float) $data['total_cost'];
                if ($request->has('additional_notes')) {
                    $additionalNotes = $this->normalizeInstantSaleNotes($request->input('additional_notes', []));
                    $data['additional_notes'] = $additionalNotes;
                    $data['notes'] = $this->instantSaleNotesText($additionalNotes, $data['notes'] ?? $instantSale->notes);
                    $newTotal = max(
                        0,
                        round(
                            ((float) $data['cost'] * (float) $data['quantity'])
                            + $this->instantSaleNotesTotal($additionalNotes)
                            - (float) ($instantSale->discount ?? 0),
                            2
                        )
                    );
                    $data['total_cost'] = $newTotal;
                }
                $oldPaid = (float) ($instantSale->payment_box_value ?? 0);
                $newPaid = $isAdjustmentSale
                    ? 0
                    : ($request->has('payment_box_value')
                    ? max(0, (float) $request->input('payment_box_value'))
                    : $oldPaid);

                if ($newPaid > $newTotal + 0.0001) {
                    throw ValidationException::withMessages([
                        'payment_box_value' => ['المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الفاتورة'],
                    ]);
                }

                $paidDelta = $newPaid - $oldPaid;
                if (! $isAdjustmentSale
                    && ! $isClosedDayAdministrativeCorrection
                    && ! $isClosedDayFinancialSettlement
                    && abs($paidDelta) > 0.0001
                    && $instantSale->payment_box_id) {
                    $box = Box::lockForUpdate()->findOrFail($instantSale->payment_box_id);

                    if ($paidDelta < 0 && (float) $box->total < abs($paidDelta)) {
                        throw ValidationException::withMessages([
                            'payment_box_value' => [__('messages.box_out_of_money')],
                        ]);
                    }

                    $box->total = (float) $box->total + $paidDelta;
                    $box->save();
                    $editAction = $paidDelta >= 0 ? 'edit_add' : 'edit_minus';
                    BoxLogs::createBoxLog(
                        $box,
                        $paidDelta >= 0 ? 'إضافة — تعديل بيع فوري' : 'سحب — تعديل بيع فوري',
                        $paidDelta >= 0 ? 'add' : 'minus',
                        $paidDelta,
                        $this->instantSaleBoxLogNote($instantSale, $editAction)
                    );
                }

                if ($request->has('payment_box_value') || $instantSale->payment_box_id) {
                    $data['payment_box_value'] = $newPaid;
                }

                $excludedUpdateFields = ['instant_sale_id', 'closed_day_edit_mode', 'closed_day_edit_reason'];
                if ($isClosedDayAdministrativeCorrection || $isClosedDayFinancialSettlement) {
                    $excludedUpdateFields[] = 'payment_box_id';
                }

                $instantSale->update(array_merge(
                    collect($data)->except($excludedUpdateFields)->toArray(),
                    $this->auditFieldsForUpdate()
                ));

                if ($isClosedDayFinancialSettlement) {
                    $this->applyClosedDayPaymentDelta($request, $existingSnapshot, $instantSale->fresh());
                }

                if (! $instantSale->parent_id) {
                    app(DebtLedgerService::class)->syncInstantSaleToLedger(
                        $instantSale->fresh(['product', 'offerPackage'])
                    );
                }

                Logs::createLog('تعديل بيع فوري', 'تم تعديل بيع فوري #'.$instantSale->id, 'instant_sales');
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.instant_sale_updated_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::edit error', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * @param  array<string, mixed>  $stockImpact
     */
    private function notifyAdminIfNegativeInstantSaleStock(
        Request $request,
        Product $product,
        InstantSale $sale,
        array $stockImpact
    ): void {
        $after = (int) ($stockImpact['stock_after'] ?? 0);
        if ($after >= 0) {
            return;
        }

        $before = (int) ($stockImpact['stock_before'] ?? 0);
        $user = $request->user();
        $userName = $user?->name ?? 'مستخدم غير معروف';
        $employeeId = $user?->employee?->id ? (int) $user->employee->id : null;
        $variantLabel = null;
        $sizeColorId = (int) ($stockImpact['size_color_id'] ?? 0);
        if ($sizeColorId > 0) {
            $variant = \App\Models\SizeColor::query()
                ->with('size')
                ->find($sizeColorId);
            if ($variant) {
                $size = $variant->size?->size;
                $color = $variant->colorAr;
                $variantLabel = trim(($size ? $size.' / ' : '').($color ?: ''));
            }
        }

        $invoice = $sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT);
        $productLabel = $product->nameAr ?? 'منتج محذوف';
        $body = "تم بيع منتج بمخزون غير كافٍ بواسطة {$userName}. المنتج: {$productLabel}";
        if ($variantLabel) {
            $body .= " ({$variantLabel})";
        }
        $body .= ". المخزون قبل البيع: {$before}، بعد البيع: {$after}. الفاتورة: {$invoice}.";

        app(AdminNotificationService::class)->create(
            AdminNotificationService::TYPE_NEGATIVE_INSTANT_SALE_STOCK,
            'بيع بمخزون سالب',
            $body,
            [
                'instant_sale_id' => (string) $sale->id,
                'invoice_number' => (string) $invoice,
                'product_id' => (string) $product->id,
                'product_name' => (string) $productLabel,
                'size_color_id' => $sizeColorId > 0 ? (string) $sizeColorId : '',
                'variant_label' => (string) ($variantLabel ?? ''),
                'stock_before' => (string) $before,
                'stock_after' => (string) $after,
                'quantity' => (string) ((int) round((float) $sale->quantity)),
                'created_by_user_id' => (string) ($user?->id ?? ''),
                'created_by_name' => (string) $userName,
            ],
            $employeeId,
            InstantSale::class,
            (int) $sale->id
        );
    }

    private function assertDailySalesPaymentBox(Request $request, array $paymentBoxPayload): void
    {
        $boxId = (int) ($paymentBoxPayload['payment_box_id'] ?? 0);
        if ($boxId <= 0) {
            return;
        }

        $box = Box::find($boxId);
        if (! $box || ! $box->isDailySalesBox()) {
            throw ValidationException::withMessages([
                'payment_box_id' => [__('messages.sales_daily_box_required')],
            ]);
        }

        $overrideId = (int) $request->attributes->get('sales_daily_session_override_id', 0);
        if ($overrideId > 0) {
            $session = SalesDailySession::query()->findOrFail($overrideId);
            if (
                $session->isOpen()
                && app(SalesDailySessionService::class)->dailyBoxBelongsToSession($box, $session)
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'box_id' => ['الصندوق المختار ليس صندوق جلسة المبيعات اليومية المفتوحة.'],
            ]);
        }

        app(SalesDailySessionService::class)->assertDailyBoxOwnedByUser($request->user(), $box);
    }

    public function cancel(Request $request)
    {
        try {
            $request->validate([
                'instant_sale_id' => 'required|integer|exists:instant_sales,id',
            ]);

            DB::transaction(function () use ($request) {
                $sale = InstantSale::query()
                    ->whereNull('parent_id')
                    ->with(['product', 'subProducts.product', 'salesDailySession'])
                    ->lockForUpdate()
                    ->findOrFail($request->instant_sale_id);

                app(SalesDailySessionService::class)->assertCanDirectCancelSale($request->user(), $sale);

                if ($sale->isCancelled()) {
                    throw ValidationException::withMessages([
                        'instant_sale_id' => [__('messages.instant_sale_already_cancelled')],
                    ]);
                }

                app(SalesReturnService::class)->assertInstantSaleHasNoActiveDirectReturns($sale);

                foreach ($this->stockLinesForSale($sale) as $line) {
                    $this->restoreStockForSaleLine($line);
                }

                if ($sale->offer_package_id) {
                    $package = OfferPackage::query()
                        ->lockForUpdate()
                        ->find($sale->offer_package_id);
                    if ($package) {
                        app(OfferPackageService::class)->restorePackageQuantity(
                            $package,
                            $this->saleLineQuantity($sale)
                        );
                    }
                }

                foreach ($sale->subProducts as $sub) {
                    if (! $sub->isCancelled()) {
                        $this->markInstantSaleCancelled($sub);
                    }
                }

                $this->reverseBoxForCancelledSale($sale);
                $this->markInstantSaleCancelled($sale);
                app(DebtLedgerService::class)->deleteInstantSaleLedger($sale);

                Logs::createLog(
                    'إلغاء بيع فوري',
                    'تم إلغاء بيع فوري #'.$sale->id.' واسترجاع المخزون',
                    'instant_sales'
                );
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.instant_sale_cancelled_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::cancel error', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function invoiceDetails(Request $request)
    {
        try {
            $request->validate(['instant_sale_id' => 'required|integer|exists:instant_sales,id']);

            $sale = InstantSale::query()
                ->with([
                    'product.viewImages',
                    'product.normalImages',
                    'offerPackage',
                    'size',
                    'sizeColor.size',
                    'subProducts.product.viewImages',
                    'subProducts.product.normalImages',
                    'subProducts.size',
                    'subProducts.sizeColor.size',
                    'project.partnership.customer',
                    'paymentBox:id,name',
                    'salesDailySession:id,business_date,status',
                    'createdByUser:id,name',
                    'updatedByUser:id,name',
                ])
                ->findOrFail($request->instant_sale_id);

            $buyer = $this->resolveInvoiceBuyer($sale);
            $subtotalBeforeDiscount = (float) $sale->cost * (float) $sale->quantity;
            $discount = (float) ($sale->discount ?? 0);
            $totalCost = (float) $sale->total_cost;
            $additionalNotes = $this->normalizeInstantSaleNotes($sale->additional_notes ?? []);
            $additionalNotesTotal = $this->instantSaleNotesTotal($additionalNotes);
            $isPackageSale = $sale->offer_package_id !== null;
            $hasAdditionalProducts = $isPackageSale && $sale->subProducts->contains(
                fn ($sub) => (float) $sub->cost > 0
            );
            $saleComposition = $isPackageSale
                ? ($hasAdditionalProducts ? 'mixed' : 'package')
                : 'product';
            $displayName = $isPackageSale
                ? ($sale->offerPackage?->name ?? 'باكيج محذوف')
                : ($sale->product?->nameAr ?? '-');
            $variantFields = $this->formatInstantSaleVariantFields($isPackageSale ? null : $sale);
            $productDisplay = $isPackageSale
                ? ['product_base' => $displayName, 'product' => $displayName]
                : $this->formatInstantSaleProductDisplay($displayName, $variantFields);

            $linkedSalesOrder = $sale->sales_order_id
                ? SalesOrder::query()
                    ->select(['id', 'serial_number'])
                    ->find($sale->sales_order_id)
                : SalesOrder::query()
                    ->where('instant_sale_id', $sale->id)
                    ->select(['id', 'serial_number'])
                    ->first();

            $formatted = [
                'id' => $sale->id,
                'invoice_number' => (string) ($sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT)),
                'serial_number' => $sale->serial_number,
                'invoice_date' => optional($sale->created_at)->format('Y-m-d H:i:s'),
                'sales_daily_session_id' => $sale->sales_daily_session_id,
                'sales_daily_business_date' => $sale->salesDailySession?->business_date?->toDateString(),
                'sales_daily_session_status' => $sale->salesDailySession?->status,
                'is_sales_daily_session_closed' => $sale->salesDailySession?->isClosed() ?? false,
                'sale_kind' => $sale->sale_kind ?? self::SALE_KIND_REGULAR,
                'sale_kind_label_ar' => ($sale->sale_kind ?? self::SALE_KIND_REGULAR) === self::SALE_KIND_ADJUSTMENT
                    ? 'فاتورة تعويض / تعديل'
                    : 'بيع فوري',
                'sale_type' => $isPackageSale ? 'package' : 'product',
                'sale_composition' => $saleComposition,
                'has_additional_products' => $hasAdditionalProducts,
                'is_package_sale' => $isPackageSale,
                'package_name' => $sale->offerPackage?->name,
                'product_id' => $sale->product_id,
                'product_code' => $sale->product?->product_code,
                'offer_package_id' => $sale->offer_package_id,
                'project_id' => $sale->project_id,
                'type' => $sale->type ?? 'normal',
                'seller_id' => $sale->seller_id,
                ...$productDisplay,
                ...$variantFields,
                'product_image' => $isPackageSale
                    ? app(OfferPackageService::class)->imagePublicPath($sale->offerPackage?->image_path)
                    : $this->invoiceProductImage($sale->product, $sale->sizeColor),
                'cost' => $sale->cost,
                'quantity' => $sale->quantity,
                'subtotal' => $subtotalBeforeDiscount,
                'total_cost' => $totalCost,
                'additional_notes' => $additionalNotes,
                'additional_notes_total' => $additionalNotesTotal,
                'discount' => $discount,
                'tax' => 0,
                ...$this->instantSalePaymentAmounts($sale),
                'sale_status' => $sale->type ?? 'normal',
                'payment_method' => $sale->project?->payment_method,
                'notes' => $sale->notes,
                'sales_order_id' => $linkedSalesOrder?->id,
                'sales_order_serial' => $linkedSalesOrder?->serial_number,
                'maintenance_id' => $sale->maintenance_id,
                'maintenance_invoice_number' => $sale->maintenance_id
                    ? 'MNT-'.str_pad((string) $sale->maintenance_id, 6, '0', STR_PAD_LEFT)
                    : null,
                'buyer' => $buyer,
                'trader_name' => $buyer['type'] === 'trader' ? $buyer['name'] : null,
                'customer_name' => $buyer['type'] === 'customer' ? $buyer['name'] : ($sale->project?->partnership?->customer?->name),
                'phone' => $buyer['phone'],
                'address' => $buyer['address'],
                'project_name' => $sale->project?->name,
                'status' => $sale->status ?? 'active',
                'cancelled_at' => optional($sale->cancelled_at)->format('Y-m-d H:i:s'),
                ...$this->formatInstantSaleAuditFields($sale),
                ...$this->paymentBoxInvoiceFields($sale),
                'sub_products' => $sale->subProducts->map(function ($sub) use ($isPackageSale) {
                    $lineCost = (float) $sub->cost;
                    $lineSubtotal = $lineCost * (float) $sub->quantity;
                    $subVariant = $this->formatInstantSaleVariantFields($sub);
                    $subBase = $sub->product?->nameAr ?? '-';

                    return [
                        'id' => $sub->id,
                        'product_id' => $sub->product_id,
                        'product_code' => $sub->product?->product_code,
                        'size_color_id' => $sub->size_color_id,
                        'size_id' => $sub->size_id,
                        'type' => $sub->type ?? 'normal',
                        'project_id' => $sub->project_id,
                        ...$this->formatInstantSaleSubProductDisplay($subBase, $subVariant),
                        ...$subVariant,
                        'product_image' => $this->invoiceProductImage($sub->product, $sub->sizeColor),
                        'cost' => $sub->cost,
                        'quantity' => $sub->quantity,
                        'subtotal' => $lineSubtotal,
                        'is_package_component' => $isPackageSale && $lineCost <= 0,
                        'is_additional_product' => $isPackageSale && $lineCost > 0,
                    ];
                })->values(),
             ];

             return response()->json([
                'status' => 'success',
                'instant_sale_invoice' => $formatted,
            ], 200);
        }
         catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),

            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
            Log::error('InstantSales::invoiceDetails query', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::invoiceDetails', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }

    public function customerProductPriceHistory(Request $request)
    {
        try {
            $data = $request->validate([
                'person_type' => 'nullable|required_with:person_id|string|in:customer,seller',
                'person_id' => 'nullable|required_with:person_type|integer|min:1',
                'product_id' => 'required|integer|exists:products,id',
                'size_color_id' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:20',
            ]);

            $sizeColorId = isset($data['size_color_id']) ? (int) $data['size_color_id'] : null;
            $limit = isset($data['limit']) ? (int) $data['limit'] : 5;
            $service = app(CustomerProductPriceHistoryService::class);
            $productId = (int) $data['product_id'];

            if (! empty($data['person_type']) && ! empty($data['person_id'])) {
                $personType = (string) $data['person_type'];
                $personId = (int) $data['person_id'];

                if ($personType === 'customer') {
                    Customer::query()->findOrFail($personId);
                } else {
                    Seller::query()->findOrFail($personId);
                }

                $history = $service->getHistory(
                    $personType,
                    $personId,
                    $productId,
                    $sizeColorId,
                    $limit
                );
            } else {
                $history = $service->getGeneralHistory(
                    $productId,
                    $sizeColorId,
                    $limit
                );
            }

            return response()->json([
                'status' => 'success',
                'last_price' => $history['last_price'],
                'entries' => $history['entries'],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::customerProductPriceHistory error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

}
