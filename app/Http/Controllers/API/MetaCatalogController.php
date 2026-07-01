<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\BulkSyncMetaCatalogJob;
use App\Models\AppSetting;
use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use App\Models\SizeColor;
use App\Services\Meta\MetaCatalogService;
use App\Support\ProductImageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetaCatalogController extends Controller
{
    public function status(MetaCatalogService $service)
    {
        $error = null;
        try {
            $service->validateConfig();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $counts = DB::query()->fromSub(
            Product::query()->whereDoesntHave('sizes.colorSizes')->select('meta_catalog_sync_status')
                ->unionAll(SizeColor::query()->select('meta_catalog_sync_status')),
            'sync_items'
        )->selectRaw("SUM(meta_catalog_sync_status = 'synced') as synced")
            ->selectRaw("SUM(meta_catalog_sync_status = 'failed') as failed")
            ->selectRaw("SUM(meta_catalog_sync_status = 'pending' OR meta_catalog_sync_status IS NULL) as pending")
            ->selectRaw("SUM(meta_catalog_sync_status = 'disabled') as disabled")
            ->first();

        return response()->json([
            'status' => 'success',
            'catalog' => [
                'configured' => $error === null,
                'configuration_error' => $error,
                'catalog_id' => $this->mask((string) config('meta_commerce.catalog_id')),
                'total_local_products' => Product::query()->count(),
                'synced_products' => (int) ($counts->synced ?? 0),
                'failed_products' => (int) ($counts->failed ?? 0),
                'pending_products' => (int) ($counts->pending ?? 0),
                'disabled_products' => (int) ($counts->disabled ?? 0),
                'last_synced_at' => MetaCatalogSyncLog::query()->where('status', 'success')->max('created_at'),
                ...$this->settingsPayload(),
            ],
        ]);
    }

    public function products(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:synced,failed,pending,disabled',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = Product::query()->with(['category', 'normalImages', 'viewImages', 'image3d', 'sizes.colorSizes.size']);
        if (! empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($q) => $q->where('nameAr', 'like', "%{$term}%")
                ->orWhere('nameEng', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%"));
        }
        if (! empty($data['status'])) {
            $status = $data['status'];
            $query->where(function ($q) use ($status) {
                $q->where('meta_catalog_sync_status', $status)
                    ->orWhereHas('sizes.colorSizes', fn ($variants) => $variants->where('meta_catalog_sync_status', $status));
                if ($status === 'pending') $q->orWhereNull('meta_catalog_sync_status');
            });
        }
        $page = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20));
        $page->getCollection()->transform(fn (Product $product) => $this->productPayload($product));

        return response()->json(['status' => 'success', 'products' => $page]);
    }

    public function syncLog(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|in:success,failed,queued',
            'action' => 'nullable|in:create,update,delete,disable,bulk_sync,test',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = MetaCatalogSyncLog::query()->with(['product:id,nameAr,nameEng', 'variant:id,colorAr,colorEn']);
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['action'])) $query->where('action', $data['action']);
        return response()->json([
            'status' => 'success',
            'logs' => $query->latest()->paginate((int) ($data['per_page'] ?? 30)),
        ]);
    }

    public function syncProduct(int $id, MetaCatalogService $service)
    {
        return $this->syncProductTargets(Product::query()->findOrFail($id), $service);
    }

    public function resyncProduct(int $id, MetaCatalogService $service)
    {
        return $this->syncProductTargets(Product::query()->findOrFail($id), $service);
    }

    public function syncVariant(int $id, MetaCatalogService $service)
    {
        $variant = SizeColor::query()->with('size.product')->findOrFail($id);
        return $this->syncOne($variant->size->product, $variant, $service);
    }

    public function disableProduct(int $id, MetaCatalogService $service)
    {
        $product = Product::query()->with('sizes.colorSizes')->findOrFail($id);
        $targets = $product->sizes->flatMap->colorSizes;
        try {
            if ($targets->isEmpty()) $service->disable($product);
            foreach ($targets as $variant) $service->disable($product, $variant);
            return response()->json(['status' => 'success', 'message' => 'تم تعطيل المنتج من كتالوج Meta.']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function disableVariant(int $id, MetaCatalogService $service)
    {
        $variant = SizeColor::query()->with('size.product')->findOrFail($id);
        try {
            $service->disable($variant->size->product, $variant);
            return response()->json(['status' => 'success', 'message' => 'تم تعطيل المتغير من الكتالوج.']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function bulkSync()
    {
        MetaCatalogSyncLog::query()->create(['action' => 'bulk_sync', 'status' => 'queued']);
        BulkSyncMetaCatalogJob::dispatch();
        return response()->json(['status' => 'success', 'message' => 'تمت إضافة مزامنة المنتجات إلى قائمة الانتظار.']);
    }

    public function testProduct(Request $request, MetaCatalogService $service)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        return $this->syncProductTargets(Product::query()->findOrFail($data['product_id']), $service, 'test');
    }

    public function settings()
    {
        return response()->json(['status' => 'success', 'settings' => $this->settingsPayload()]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'auto_sync_meta_catalog' => 'required|boolean',
            'enable_show_quantity_in_catalog' => 'required|boolean',
            'meta_catalog_currency' => 'required|string|size:3',
            'meta_catalog_default_brand' => 'required|string|max:100',
        ]);
        AppSetting::set(AppSetting::KEY_AUTO_SYNC_META_CATALOG, $data['auto_sync_meta_catalog'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_SHOW_QUANTITY_IN_META_CATALOG, $data['enable_show_quantity_in_catalog'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_META_CATALOG_CURRENCY, strtoupper($data['meta_catalog_currency']));
        AppSetting::set(AppSetting::KEY_META_CATALOG_DEFAULT_BRAND, $data['meta_catalog_default_brand']);
        return $this->settings();
    }

    private function syncProductTargets(Product $product, MetaCatalogService $service, string $action = '') {
        $product->load(['sizes.colorSizes.size']);
        $targets = $product->sizes->flatMap->colorSizes;
        if ($targets->isEmpty()) return $this->syncOne($product, null, $service, $action);
        $results = [];
        foreach ($targets as $variant) {
            try {
                $results[] = ['variant_id' => $variant->id, 'status' => 'success', ...$service->syncProduct($product, $variant, $action)];
            } catch (Throwable $e) {
                $results[] = ['variant_id' => $variant->id, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        $failed = collect($results)->where('status', 'error')->count();
        return response()->json([
            'status' => $failed === count($results) ? 'error' : 'success',
            'message' => "تمت مزامنة ".(count($results) - $failed)." من ".count($results)." متغير.",
            'results' => $results,
        ], $failed === count($results) ? 422 : 200);
    }

    private function syncOne(Product $product, ?SizeColor $variant, MetaCatalogService $service, string $action = '') {
        try {
            $result = $service->syncProduct($product, $variant, $action);
            return response()->json(['status' => 'success', 'message' => 'تمت المزامنة بنجاح.', ...$result]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function productPayload(Product $product): array
    {
        $variants = $product->sizes->flatMap->colorSizes;
        $syncStatus = $product->meta_catalog_sync_status ?: 'pending';
        $lastSyncedAt = $product->meta_catalog_last_synced_at;
        $lastError = $product->meta_catalog_last_error;
        if ($variants->isNotEmpty()) {
            $statuses = $variants->map(fn ($variant) => $variant->meta_catalog_sync_status ?: 'pending');
            $syncStatus = $statuses->contains('failed')
                ? 'failed'
                : ($statuses->contains('pending')
                    ? 'pending'
                    : ($statuses->every(fn ($status) => $status === 'disabled') ? 'disabled' : 'synced'));
            $lastSyncedAt = $variants->max('meta_catalog_last_synced_at');
            $lastError = $variants->first(fn ($variant) => filled($variant->meta_catalog_last_error))?->meta_catalog_last_error;
        }
        return [
            'id' => $product->id,
            'name' => $product->nameAr ?: $product->nameEng,
            'image' => ProductImageResolver::preferredUrl($product),
            'price' => (float) ($product->normailPrice ?: $product->price ?: 0),
            'quantity' => $variants->isEmpty() ? (int) $product->stock : $variants->sum('stock'),
            'category' => $product->category?->nameAr ?: $product->category?->nameEng,
            'meta_catalog_sync_status' => $syncStatus,
            'meta_catalog_last_synced_at' => $lastSyncedAt,
            'meta_catalog_last_error' => $lastError,
            'meta_catalog_item_id' => $product->meta_catalog_item_id,
            'meta_catalog_retailer_id' => $product->meta_catalog_retailer_id,
            'variants' => $variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => trim(($variant->size?->size ?? '').' '.($variant->colorAr ?: $variant->colorEn)),
                'price' => (float) $variant->normailPrice,
                'quantity' => (int) $variant->stock,
                'meta_catalog_sync_status' => $variant->meta_catalog_sync_status ?: 'pending',
                'meta_catalog_last_synced_at' => $variant->meta_catalog_last_synced_at,
                'meta_catalog_last_error' => $variant->meta_catalog_last_error,
                'meta_catalog_item_id' => $variant->meta_catalog_item_id,
                'meta_catalog_retailer_id' => $variant->meta_catalog_retailer_id,
            ])->values(),
        ];
    }

    private function settingsPayload(): array
    {
        return [
            'auto_sync_meta_catalog' => AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG),
            'enable_show_quantity_in_catalog' => AppSetting::getBool(AppSetting::KEY_SHOW_QUANTITY_IN_META_CATALOG),
            'meta_catalog_currency' => (string) AppSetting::get(AppSetting::KEY_META_CATALOG_CURRENCY, 'ILS'),
            'meta_catalog_default_brand' => (string) AppSetting::get(AppSetting::KEY_META_CATALOG_DEFAULT_BRAND, 'Dr Bike'),
        ];
    }

    private function mask(string $value): ?string
    {
        if ($value === '') return null;
        return strlen($value) <= 8 ? str_repeat('*', strlen($value)) : substr($value, 0, 4).'****'.substr($value, -4);
    }
}
