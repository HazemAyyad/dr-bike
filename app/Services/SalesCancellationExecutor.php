<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Http\Controllers\API\Logs;
use App\Models\Box;
use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProfitSale;
use App\Models\SalesCancellationRequest;
use App\Models\SalesDailySession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SalesCancellationExecutor
{
    public function __construct(
        protected SalesDailySessionService $salesDailySessionService
    ) {}

    public function approve(SalesCancellationRequest $request, int $reviewerUserId, ?string $reviewNotes = null): SalesCancellationRequest
    {
        return DB::transaction(function () use ($request, $reviewerUserId, $reviewNotes) {
            $request = SalesCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $request->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            if ($request->sale_type === 'instant') {
                $this->cancelInstantSale((int) $request->sale_id, $request->session);
            } else {
                $this->cancelProfitSale((int) $request->sale_id, $request->session);
            }

            $request->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewerUserId,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            return $request->fresh();
        });
    }

    private function cancelInstantSale(int $saleId, ?SalesDailySession $session): void
    {
        $sale = InstantSale::query()
            ->whereNull('parent_id')
            ->with(['product', 'subProducts.product', 'paymentBox'])
            ->lockForUpdate()
            ->findOrFail($saleId);

        if ($sale->isCancelled()) {
            throw ValidationException::withMessages([
                'sale' => [__('messages.instant_sale_already_cancelled')],
            ]);
        }

        foreach ($this->stockLinesForSale($sale) as $line) {
            $this->restoreStockForSaleLine($line);
        }

        if ($sale->offer_package_id) {
            $package = OfferPackage::query()->lockForUpdate()->find($sale->offer_package_id);
            if ($package) {
                app(OfferPackageService::class)->restorePackageQuantity(
                    $package,
                    max(0, (int) round((float) $sale->quantity))
                );
            }
        }

        foreach ($sale->subProducts as $sub) {
            if (! $sub->isCancelled()) {
                $this->markInstantSaleCancelled($sub);
            }
        }

        $this->reversePaymentForClosedDay($sale, $session);
        $this->markInstantSaleCancelled($sale);
        app(DebtLedgerService::class)->deleteInstantSaleLedger($sale);

        Logs::createLog(
            'إلغاء بيع فوري (معتمد)',
            'تم إلغاء بيع فوري #'.$sale->id.' بعد موافقة الإدارة',
            'instant_sales'
        );
    }

    private function cancelProfitSale(int $saleId, ?SalesDailySession $session): void
    {
        $sale = ProfitSale::query()->lockForUpdate()->findOrFail($saleId);

        if ($sale->isCancelled()) {
            throw ValidationException::withMessages([
                'sale' => [__('messages.instant_sale_already_cancelled')],
            ]);
        }

        $this->reverseProfitPaymentForClosedDay($sale, $session);
        $this->markProfitSaleCancelled($sale);
        app(DebtLedgerService::class)->deleteSourceLedger('profit_sale', (int) $sale->id);

        Logs::createLog(
            'إلغاء بيع ربحي (معتمد)',
            'تم إلغاء بيع ربحي #'.$sale->id.' بعد موافقة الإدارة',
            'profit_sales'
        );
    }

    private function reversePaymentForClosedDay(InstantSale $sale, ?SalesDailySession $session): void
    {
        $amount = (float) ($sale->payment_box_value ?? 0);
        if ($amount <= 0) {
            return;
        }

        $currency = $sale->paymentBox?->currency ?? 'شيكل';
        $boxId = $session
            ? $this->salesDailySessionService->reversalBoxForSession($session, $currency)
            : null;

        if (! $boxId) {
            $boxId = $sale->payment_box_id;
        }

        if (! $boxId) {
            return;
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        $box->total = max(0, (float) $box->total - $amount);
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            'سحب — عكس قبض بيع فوري (إلغاء معتمد)',
            'minus',
            -$amount,
            'إلغاء معتمد لبيع فوري #'.$sale->id
        );
    }

    private function reverseProfitPaymentForClosedDay(ProfitSale $sale, ?SalesDailySession $session): void
    {
        $amount = (float) ($sale->payment_box_value ?? 0);
        if ($amount <= 0) {
            return;
        }

        $currency = $sale->paymentBox?->currency ?? 'شيكل';
        $boxId = $session
            ? $this->salesDailySessionService->reversalBoxForSession($session, $currency)
            : null;

        if (! $boxId) {
            $boxId = $sale->payment_box_id;
        }

        if (! $boxId) {
            return;
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        $box->total = max(0, (float) $box->total - $amount);
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            'سحب — عكس قبض بيع ربحي (إلغاء معتمد)',
            'minus',
            -$amount,
            'إلغاء معتمد لبيع ربحي #'.$sale->id
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, InstantSale>
     */
    private function stockLinesForSale(InstantSale $mainSale)
    {
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

    private function restoreStockForSaleLine(InstantSale $line): void
    {
        if (Schema::hasColumn('instant_sales', 'stock_restored') && $line->stock_restored) {
            return;
        }

        $quantity = max(0, (int) round((float) ($line->quantity ?? 0)));
        $productId = (int) $line->product_id;

        if ($productId > 0 && $quantity > 0) {
            $product = Product::withTrashed()->find($productId);
            if ($product instanceof Product) {
                app(ProductStockService::class)->restoreForSale(
                    product: $product,
                    quantity: $quantity,
                    sizeColorId: $line->size_color_id ? (int) $line->size_color_id : null,
                    sizeId: $line->size_id ? (int) $line->size_id : null,
                    referenceType: 'instant_sale',
                    referenceId: (int) $line->id,
                );
            }
        }

        if (Schema::hasColumn('instant_sales', 'stock_restored')) {
            $line->update(['stock_restored' => true]);
        }
    }

    private function markInstantSaleCancelled(InstantSale $sale): void
    {
        $payload = ['cancelled_at' => now()];
        if (Schema::hasColumn('instant_sales', 'status')) {
            $payload['status'] = 'cancelled';
        }
        $sale->update($payload);
    }

    private function markProfitSaleCancelled(ProfitSale $sale): void
    {
        $payload = [];
        if (Schema::hasColumn('profit_sales', 'cancelled_at')) {
            $payload['cancelled_at'] = now();
        }
        if (Schema::hasColumn('profit_sales', 'status')) {
            $payload['status'] = 'cancelled';
        }
        if ($payload !== []) {
            $sale->update($payload);
        }
    }
}
