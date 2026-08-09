<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\BulkSyncMetaCatalogJob;
use App\Jobs\SyncMetaCatalogHierarchyJob;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\MetaCatalogSyncLog;
use App\Models\MetaCatalogProductSync;
use App\Models\MetaCatalogProductSet;
use App\Models\Product;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\WhatsAppAccount;
use App\Services\Meta\MetaCatalogService;
use App\Support\ProductImageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetaCatalogController extends Controller
{
    public function status(MetaCatalogService $service)
    {
        $account = $this->accountFromRequest(request());
        if ($account) {
            $service = $service->forAccount($account);
        }
        $catalogId = $this->catalogIdForAccount($account);
        $error = null;
        $catalogInfo = null;
        try {
            $service->validateConfig();
            $catalogInfo = $service->getCatalogInfo();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $counts = $account ? $this->catalogSyncCounts($catalogId) : DB::query()->fromSub(
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
                'whatsapp_account' => $account ? $this->accountPayload($account) : null,
                'configured' => $error === null,
                'configuration_error' => $error,
                'catalog_id' => $this->mask((string) $catalogId),
                'catalog_name' => $catalogInfo['name'] ?? null,
                'meta_product_count' => isset($catalogInfo['product_count'])
                    ? (int) $catalogInfo['product_count']
                    : null,
                'total_local_products' => Product::query()->count(),
                'synced_products' => (int) ($counts->synced ?? 0),
                'failed_products' => (int) ($counts->failed ?? 0),
                'pending_products' => (int) ($counts->pending ?? 0),
                'disabled_products' => (int) ($counts->disabled ?? 0),
                'total_product_sets' => $this->productSetQuery($account)->count(),
                'synced_product_sets' => $this->productSetQuery($account)->where('sync_status', 'synced')->count(),
                'failed_product_sets' => $this->productSetQuery($account)->where('sync_status', 'failed')->count(),
                'last_synced_at' => MetaCatalogSyncLog::query()
                    ->when($account, fn ($q) => $q->where('catalog_id', $catalogId))
                    ->where('status', 'success')
                    ->max('created_at'),
                ...$this->settingsPayload(),
            ],
        ]);
    }

    public function accounts()
    {
        return response()->json([
            'status' => 'success',
            'accounts' => WhatsAppAccount::query()
                ->with('catalogRules')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (WhatsAppAccount $account) => ($account->waba_id ?: 'waba-'.$account->id).'|'.($account->catalog_id ?: 'no-catalog'))
                ->map(function ($accounts) {
                    $representative = $accounts->first();
                    return $this->accountPayload($representative, $accounts);
                })
                ->values(),
        ]);
    }

    public function syncSources()
    {
        return response()->json([
            'status' => 'success',
            'categories' => Category::query()
                ->withCount(['products' => fn ($query) => $query->where('isShow', true)])
                ->orderBy('sortOrder')
                ->orderBy('id')
                ->get(['id', 'nameAr', 'nameEng', 'isShow', 'sortOrder'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->nameAr ?: $category->nameEng ?: ('تصنيف #'.$category->id),
                    'is_show' => (bool) $category->isShow,
                    'products_count' => (int) ($category->products_count ?? 0),
                ])
                ->values(),
            'sub_categories' => SubCategory::query()
                ->with('category:id,nameAr,nameEng')
                ->withCount(['products as products_count'])
                ->orderBy('mainCategoryId')
                ->orderBy('sortOrder')
                ->orderBy('id')
                ->get(['id', 'nameAr', 'nameEng', 'mainCategoryId', 'isShow', 'sortOrder'])
                ->map(fn (SubCategory $subCategory) => [
                    'id' => $subCategory->id,
                    'name' => $subCategory->nameAr ?: $subCategory->nameEng ?: ('تصنيف فرعي #'.$subCategory->id),
                    'parent_id' => $subCategory->mainCategoryId,
                    'parent_name' => $subCategory->category?->nameAr ?: $subCategory->category?->nameEng,
                    'is_show' => (bool) $subCategory->isShow,
                    'products_count' => (int) ($subCategory->products_count ?? 0),
                ])
                ->values(),
        ]);
    }

    public function products(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:synced,failed,pending,disabled',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $account = $this->accountFromRequest($request);
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
        $page->getCollection()->transform(fn (Product $product) => $this->productPayload($product, $account));

        return response()->json(['status' => 'success', 'products' => $page]);
    }

    public function syncLog(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|in:success,failed,queued',
            'action' => 'nullable|in:create,update,delete,disable,bulk_sync,test',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $account = $this->accountFromRequest($request);
        $query = MetaCatalogSyncLog::query()->with(['product:id,nameAr,nameEng', 'variant:id,colorAr,colorEn', 'whatsappAccount:id,name']);
        if ($account) $query->where('catalog_id', $this->catalogIdForAccount($account));
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['action'])) $query->where('action', $data['action']);
        return response()->json([
            'status' => 'success',
            'logs' => $query->latest()->paginate((int) ($data['per_page'] ?? 30)),
        ]);
    }

    public function productSets(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|in:synced,failed,pending,disabled',
            'type' => 'nullable|in:category,sub_category',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $account = $this->accountFromRequest($request);
        $query = $this->productSetQuery($account);
        if (! empty($data['status'])) $query->where('sync_status', $data['status']);
        if (! empty($data['type'])) $query->where('source_type', $data['type']);
        if (! empty($data['search'])) $query->where('name', 'like', '%'.trim($data['search']).'%');

        return response()->json([
            'status' => 'success',
            'product_sets' => $query->orderBy('source_type')->orderBy('name')
                ->paginate((int) ($data['per_page'] ?? 50)),
        ]);
    }

    public function syncHierarchy(Request $request)
    {
        $account = $this->accountFromRequest($request);
        SyncMetaCatalogHierarchyJob::dispatch(true, $account?->id)->onConnection('database');
        return response()->json([
            'status' => 'success',
            'message' => 'بدأت مزامنة التصنيفات في الخلفية. ستظهر النتيجة في السجل بعد اكتمالها.',
            'queued' => true,
        ], 202);
    }

    public function queueHierarchySync(Request $request)
    {
        $account = $this->accountFromRequest($request);
        SyncMetaCatalogHierarchyJob::dispatch(true, $account?->id)->onConnection('database');
        return response()->json([
            'status' => 'success',
            'message' => 'تمت إضافة مزامنة التصنيفات والمجموعات إلى قائمة الانتظار.',
        ]);
    }

    public function syncProduct(int $id, MetaCatalogService $service)
    {
        $account = $this->accountFromRequest(request());
        return $this->syncProductTargets(Product::query()->findOrFail($id), $service, '', $account);
    }

    public function resyncProduct(int $id, MetaCatalogService $service)
    {
        $account = $this->accountFromRequest(request());
        return $this->syncProductTargets(Product::query()->findOrFail($id), $service, '', $account);
    }

    public function syncVariant(int $id, MetaCatalogService $service)
    {
        $variant = SizeColor::query()->with('size.product')->findOrFail($id);
        $account = $this->accountFromRequest(request());
        return $this->syncOne($variant->size->product, $variant, $service, '', $account);
    }

    public function disableProduct(int $id, MetaCatalogService $service)
    {
        $account = $this->accountFromRequest(request());
        if ($account) $service = $service->forAccount($account);
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
        $account = $this->accountFromRequest(request());
        if ($account) $service = $service->forAccount($account);
        $variant = SizeColor::query()->with('size.product')->findOrFail($id);
        try {
            $service->disable($variant->size->product, $variant);
            return response()->json(['status' => 'success', 'message' => 'تم تعطيل المتغير من الكتالوج.']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function bulkSync(Request $request)
    {
        $data = $request->validate([
            'whatsapp_account_id' => 'nullable|integer|exists:whatsapp_accounts,id',
            'source_type' => 'nullable|in:all,category,sub_category',
            'source_id' => 'nullable|integer',
        ]);
        $account = isset($data['whatsapp_account_id'])
            ? WhatsAppAccount::query()->findOrFail($data['whatsapp_account_id'])
            : null;
        $sourceType = $data['source_type'] ?? 'all';
        $sourceId = $sourceType === 'all' ? null : ($data['source_id'] ?? null);
        abort_if($sourceType !== 'all' && ! $sourceId, 422, 'source_id is required when syncing a category or sub category.');

        MetaCatalogSyncLog::query()->create([
            'action' => 'bulk_sync',
            'status' => 'queued',
            'whatsapp_account_id' => $account?->id,
            'catalog_id' => $this->catalogIdForAccount($account),
            'request_payload' => compact('sourceType', 'sourceId'),
        ]);
        BulkSyncMetaCatalogJob::dispatch(false, true, $account?->id, $sourceType, $sourceId)->onConnection('database');
        return response()->json(['status' => 'success', 'message' => 'تمت إضافة مزامنة المنتجات والتصنيفات إلى قائمة الانتظار.']);
    }

    public function testProduct(Request $request, MetaCatalogService $service)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $account = $this->accountFromRequest($request);
        return $this->syncProductTargets(Product::query()->findOrFail($data['product_id']), $service, 'test', $account);
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

    private function syncProductTargets(Product $product, MetaCatalogService $service, string $action = '', ?WhatsAppAccount $account = null) {
        if ($account) $service = $service->forAccount($account);
        $product->load(['sizes.colorSizes.size']);
        $targets = $product->sizes->flatMap->colorSizes;
        if ($targets->isEmpty()) return $this->syncOne($product, null, $service, $action, $account);
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

    private function syncOne(Product $product, ?SizeColor $variant, MetaCatalogService $service, string $action = '', ?WhatsAppAccount $account = null) {
        if ($account) $service = $service->forAccount($account);
        try {
            $result = $service->syncProduct($product, $variant, $action);
            return response()->json(['status' => 'success', 'message' => 'تمت المزامنة بنجاح.', ...$result]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function productPayload(Product $product, ?WhatsAppAccount $account = null): array
    {
        if ($account) {
            return $this->accountProductPayload($product, $account);
        }
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

    private function accountProductPayload(Product $product, WhatsAppAccount $account): array
    {
        $catalogId = $this->catalogIdForAccount($account);
        $syncs = MetaCatalogProductSync::query()
            ->where('catalog_id', $catalogId)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy(fn (MetaCatalogProductSync $sync) => $sync->variant_id ?: 0);
        $variants = $product->sizes->flatMap->colorSizes;
        $rootSync = $syncs->get(0);
        $statuses = $variants->isEmpty()
            ? collect([$rootSync?->sync_status ?: 'pending'])
            : $variants->map(fn ($variant) => $syncs->get($variant->id)?->sync_status ?: 'pending');

        $syncStatus = $statuses->contains('failed')
            ? 'failed'
            : ($statuses->contains('pending')
                ? 'pending'
                : ($statuses->every(fn ($status) => $status === 'disabled') ? 'disabled' : 'synced'));

        return [
            'id' => $product->id,
            'name' => $product->nameAr ?: $product->nameEng,
            'image' => ProductImageResolver::preferredUrl($product),
            'price' => (float) ($product->normailPrice ?: $product->price ?: 0),
            'quantity' => $variants->isEmpty() ? (int) $product->stock : $variants->sum('stock'),
            'category' => $product->category?->nameAr ?: $product->category?->nameEng,
            'meta_catalog_sync_status' => $syncStatus,
            'meta_catalog_last_synced_at' => $variants->isEmpty()
                ? $rootSync?->last_synced_at
                : $variants->map(fn ($variant) => $syncs->get($variant->id)?->last_synced_at)->filter()->max(),
            'meta_catalog_last_error' => $variants->isEmpty()
                ? $rootSync?->last_error
                : $variants->map(fn ($variant) => $syncs->get($variant->id)?->last_error)->filter()->first(),
            'meta_catalog_item_id' => $rootSync?->meta_catalog_item_id,
            'meta_catalog_retailer_id' => $rootSync?->meta_catalog_retailer_id,
            'variants' => $variants->map(function ($variant) use ($syncs) {
                $sync = $syncs->get($variant->id);
                return [
                    'id' => $variant->id,
                    'name' => trim(($variant->size?->size ?? '').' '.($variant->colorAr ?: $variant->colorEn)),
                    'price' => (float) $variant->normailPrice,
                    'quantity' => (int) $variant->stock,
                    'meta_catalog_sync_status' => $sync?->sync_status ?: 'pending',
                    'meta_catalog_last_synced_at' => $sync?->last_synced_at,
                    'meta_catalog_last_error' => $sync?->last_error,
                    'meta_catalog_item_id' => $sync?->meta_catalog_item_id,
                    'meta_catalog_retailer_id' => $sync?->meta_catalog_retailer_id,
                ];
            })->values(),
        ];
    }

    private function accountFromRequest(Request $request): ?WhatsAppAccount
    {
        $id = $request->input('whatsapp_account_id');
        return $id ? WhatsAppAccount::query()->findOrFail((int) $id) : null;
    }

    private function accountPayload(WhatsAppAccount $account, $accounts = null): array
    {
        $accounts ??= collect([$account]);
        $numbers = $accounts
            ->pluck('display_phone_number')
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $account->id,
            'name' => $accounts->count() > 1 ? $this->businessDisplayName($accounts) : $account->name,
            'display_phone_number' => implode(', ', $numbers),
            'phone_number_id' => $this->mask($account->phone_number_id),
            'waba_id' => $account->waba_id,
            'catalog_id' => $this->mask((string) $account->catalog_id),
            'is_active' => $accounts->contains(fn (WhatsAppAccount $item) => $item->is_active),
            'is_verified' => $accounts->contains(fn (WhatsAppAccount $item) => $item->is_verified),
            'numbers' => $numbers,
            'rules' => $account->catalogRules->map(fn ($rule) => [
                'id' => $rule->id,
                'source_type' => $rule->source_type,
                'source_id' => $rule->source_id,
                'is_active' => $rule->is_active,
            ])->values(),
        ];
    }

    private function productSetQuery(?WhatsAppAccount $account)
    {
        if ($account) {
            return MetaCatalogProductSet::query()
                ->where('catalog_id', $this->catalogIdForAccount($account));
        }

        return MetaCatalogProductSet::query()
            ->whereNull('whatsapp_account_id');
    }

    private function catalogSyncCounts(?string $catalogId)
    {
        return MetaCatalogProductSync::query()
            ->where('catalog_id', $catalogId)
            ->selectRaw("SUM(sync_status = 'synced') as synced")
            ->selectRaw("SUM(sync_status = 'failed') as failed")
            ->selectRaw("SUM(sync_status = 'pending' OR sync_status IS NULL) as pending")
            ->selectRaw("SUM(sync_status = 'disabled') as disabled")
            ->first();
    }

    private function catalogIdForAccount(?WhatsAppAccount $account): ?string
    {
        return $account?->catalog_id ?: config('meta_commerce.catalog_id');
    }

    private function businessDisplayName($accounts): string
    {
        $wabaId = $accounts->first()?->waba_id;
        if ($wabaId === '1021382140304311') {
            return 'Dr Bike - Main';
        }

        return ($accounts->first()?->name ?: 'WhatsApp Business').' ('.$accounts->count().' أرقام)';
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
