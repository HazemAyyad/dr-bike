<?php

namespace App\Http\Controllers;

use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\ViewImageProduct;
use App\Services\ProductFormService;
use App\Services\StoreManageItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * نموذج تجريبي يشبه لوحة إدارة المتجر — تعديل محلي ثم مزامنة ManageItem.
 */
class ProductEditTestController extends Controller
{
    /**
     * صور Laravel (قرص public) أو روابط قديمة فيها localhost: تُحوَّل لـ URL الحالي عبر APP_URL.
     * مسارات نسبية لمتجر .NET: تُسبَق بـ STORE_DOMAIN.
     */
    public static function resolveMediaUrlForProductEdit(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $trimmed = ltrim($url);
        if (str_starts_with($trimmed, '/storage/')) {
            return url($trimmed);
        }
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/storage/')) {
                return url($path);
            }

            return $trimmed;
        }
        $base = rtrim((string) config('store.domain'), '/');
        if ($base === '') {
            return $trimmed;
        }

        return $base.'/'.ltrim($trimmed, '/');
    }

    public function create(): View
    {
        $empty = new Product([
            'stock' => 0,
            'discount' => 0,
            'rate' => 4,
            'min_stock' => 0,
            'is_sold_with_paper' => 1,
            'normailPrice' => 0,
        ]);
        $empty->setRelation('sizes', collect());
        $empty->setRelation('normalImages', collect());
        $empty->setRelation('viewImages', collect());
        $empty->setRelation('image3d', collect());
        $empty->setRelation('subCategories', collect());

        $sizeOptions = $this->buildSizeOptions($empty);

        $subCategoriesList = SubCategory::query()
            ->with('category:id,nameAr')
            ->orderBy('nameAr')
            ->get(['id', 'nameAr', 'mainCategoryId']);

        $nextIdHint = (int) (Product::query()->max('id') ?? 0) + 1;

        return view('product-edit-test', [
            'isCreate' => true,
            'nextIdHint' => $nextIdHint,
            'prefill' => $empty,
            'sizeOptions' => $sizeOptions,
            'subCategoriesList' => $subCategoriesList,
            'selectedSubCategoryIds' => [],
            'productsForPicker' => collect(),
            'resolveMediaUrl' => [self::class, 'resolveMediaUrlForProductEdit'],
            'steps' => session('steps'),
            'result' => session('result'),
            'product' => session('product_model'),
            'store_item_exists' => null,
        ]);
    }

    /**
     * إنشاء منتج: المتجر يُنشئ المعرف عبر ManageItem بـ Id=0؛ نحفظ محلياً بنفس الرقم ثم نرفع الصور.
     */
    public function createRun(Request $request, ProductFormService $productFormService): RedirectResponse
    {
        $steps = [];
        $pageLog = function (string $msg, array $ctx = []) use (&$steps): void {
            $steps[] = [
                'time' => now()->format('H:i:s.v'),
                'message' => $msg,
                'context' => $ctx,
            ];
            Log::info('[product-create-test page] '.$msg, $ctx);
        };

        try {
            $out = $productFormService->create($request, $pageLog);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()
                ->route('test.product-create')
                ->withInput()
                ->withErrors($e->errors());
        }

        if (empty($out['success'])) {
            if (($out['reason'] ?? '') === 'id_conflict') {
                return redirect()
                    ->route('test.product-create')
                    ->withInput()
                    ->withErrors(['nameAr' => $out['message'] ?? 'تعارض في رقم المنتج، أعد المحاولة.']);
            }
            if (($out['reason'] ?? '') === 'local_duplicate') {
                return redirect()
                    ->route('test.product-create')
                    ->withInput()
                    ->withErrors(['nameAr' => $out['message'] ?? 'المنتج موجود مسبقاً محلياً.']);
            }
            if (($out['reason'] ?? '') === 'store_sync_failed' || ($out['reason'] ?? '') === 'no_store_id') {
                return redirect()
                    ->route('test.product-create')
                    ->withInput()
                    ->with('steps', $steps)
                    ->with('result', $out['sync_result'] ?? ['ok' => false]);
            }

            return redirect()
                ->route('test.product-create')
                ->withInput()
                ->with('steps', $steps)
                ->with('result', ['ok' => false, 'error' => $out['message'] ?? 'فشل الإنشاء']);
        }

        /** @var Product $product */
        $product = $out['product'];
        $mergedResult = $out['sync_result'] ?? ['ok' => true];

        return redirect()
            ->route('test.product-edit', ['product_id' => $product->id])
            ->with('steps', $steps)
            ->with('result', $mergedResult)
            ->with('product_model', $product);
    }

    public function show(Request $request, StoreManageItemService $storeManageItemService): View|\Illuminate\Http\RedirectResponse
    {
        $pid = $request->query('product_id');
        if ($pid === null || $pid === '') {
            return redirect()->route('test.products-list');
        }

        $prefill = Product::with([
            'subCategories',
            'sizes.colorSizes',
            'normalImages',
            'viewImages',
            'image3d',
        ])->find($pid);

        if (! $prefill) {
            return redirect()
                ->route('test.products-list')
                ->with('warning', 'المنتج المطلوب غير موجود.');
        }

        $storeItemExists = null;
        try {
            $storeItemExists = $storeManageItemService->itemExistsInStore((int) $prefill->id);
        } catch (\Throwable $e) {
            Log::warning('[product-edit-test] تعذر التحقق من وجود الصنف في المتجر', ['id' => $prefill->id, 'error' => $e->getMessage()]);
        }

        $sizeOptions = $this->buildSizeOptions($prefill);

        $subCategoriesList = SubCategory::query()
            ->with('category:id,nameAr')
            ->orderBy('nameAr')
            ->get(['id', 'nameAr', 'mainCategoryId']);

        $selectedSubCategoryIds = $prefill->subCategories->pluck('sub_category_id')->all();

        $limit = (int) config('store.product_picker_limit', 8000);
        $productsForPicker = Product::query()
            ->orderBy('nameAr')
            ->limit($limit)
            ->get(['id', 'nameAr', 'stock']);

        return view('product-edit-test', [
            'isCreate' => false,
            'nextIdHint' => null,
            'prefill' => $prefill,
            'sizeOptions' => $sizeOptions,
            'subCategoriesList' => $subCategoriesList,
            'selectedSubCategoryIds' => $selectedSubCategoryIds,
            'productsForPicker' => $productsForPicker,
            'resolveMediaUrl' => [self::class, 'resolveMediaUrlForProductEdit'],
            'steps' => session('steps'),
            'result' => session('result'),
            'product' => session('product_model'),
            'store_item_exists' => $storeItemExists,
        ]);
    }

    /**
     * جدول كل المنتجات (DataTables + Bootstrap).
     */
    /**
     * حذف صورة مخزنة (متجر + Laravel) — Ajax من صفحة الاختبار.
     */
    public function deleteImage(Request $request, StoreManageItemService $storeManageItemService): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'image_id' => ['required'],
            'kind' => ['required', 'in:normal,view,three_d'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        $model = match ($data['kind']) {
            'normal' => NormalImageProduct::query()
                ->where('id', $data['image_id'])
                ->where('itemId', $product->getKey())
                ->first(),
            'view' => ViewImageProduct::query()
                ->where('id', $data['image_id'])
                ->where('itemId', $product->getKey())
                ->first(),
            'three_d' => Image3dProduct::query()
                ->where('id', $data['image_id'])
                ->where('itemId', $product->getKey())
                ->first(),
        };

        if ($model === null) {
            return response()->json(['ok' => false, 'message' => 'الصورة غير موجودة أو لا تخص هذا المنتج'], 404);
        }

        $remote = $storeManageItemService->deleteImageFromStore((int) $product->id, $model->id, $data['kind']);
        if (! ($remote['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $remote['error'] ?? 'فشل حذف الصورة من المتجر',
            ], 422);
        }

        $model->delete();

        return response()->json(['ok' => true]);
    }

    public function productsList(): View
    {
        return view('products-test-list');
    }

    /**
     * بيانات DataTables (خادم) للبحث والترقيم.
     */
    public function productsData(Request $request, StoreManageItemService $storeManageItemService): JsonResponse
    {
        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(max((int) $request->input('length', 25), 10), 100);
        $search = trim((string) data_get($request->input('search'), 'value', ''));

        $query = Product::query()->select(['id', 'nameAr', 'stock', 'normailPrice', 'isShow']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nameAr', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $q->orWhere('id', $search);
                }
            });
        }

        $recordsFiltered = (clone $query)->count();
        $recordsTotal = Product::count();

        $rows = (clone $query)
            ->orderByDesc('id')
            ->skip($start)
            ->take($length)
            ->get();

        $storeMap = [];
        if (config('store.check_product_in_store_on_list', true) && $rows->isNotEmpty()) {
            try {
                $storeMap = $storeManageItemService->batchItemsExistInStore($rows->pluck('id')->all());
            } catch (\Throwable $e) {
                Log::warning('[products-list] batchItemsExistInStore failed', ['error' => $e->getMessage()]);
            }
        }

        $data = $rows->map(function (Product $p) use ($storeMap) {
            $sid = (int) $p->id;
            $inStore = $storeMap[$sid] ?? null;

            return [
                'id' => $p->id,
                'nameAr' => $p->nameAr,
                'stock' => $p->stock,
                'normailPrice' => $p->normailPrice,
                'isShow' => $p->isShow,
                'in_store' => $inStore,
                'edit_url' => route('test.product-edit', ['product_id' => $p->id]),
            ];
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * حذف منتج (أرشفة soft، أو حذف نهائي محلي، أو متجر + محلي) — Ajax.
     */
    public function deleteProduct(Request $request, StoreManageItemService $storeManageItemService): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'mode' => ['required', 'in:soft,laravel_hard,store_and_laravel'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        try {
            if ($data['mode'] === 'soft') {
                $product->delete();

                return response()->json(['ok' => true, 'message' => 'تم أرشفة المنتج (يمكن استعادته لاحقاً من قاعدة البيانات).']);
            }

            if ($data['mode'] === 'laravel_hard') {
                $this->hardDeleteProductRecords($product);

                return response()->json(['ok' => true, 'message' => 'تم حذف المنتج من Laravel فقط.']);
            }

            $remote = $storeManageItemService->deleteItemFromStore((int) $product->id);
            if (! ($remote['ok'] ?? false)) {
                return response()->json([
                    'ok' => false,
                    'message' => $remote['error'] ?? 'فشل حذف الصنف من المتجر',
                ], 422);
            }

            $this->hardDeleteProductRecords($product);

            return response()->json(['ok' => true, 'message' => 'تم الحذف من المتجر ومن Laravel.']);
        } catch (\Throwable $e) {
            Log::error('[product-delete-test] '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'ok' => false,
                'message' => 'فشل الحذف: '.$e->getMessage(),
            ], 500);
        }
    }

    private function hardDeleteProductRecords(Product $product): void
    {
        $id = $product->id;

        DB::transaction(function () use ($product, $id) {
            NormalImageProduct::query()->where('itemId', $id)->delete();
            ViewImageProduct::query()->where('itemId', $id)->delete();
            Image3dProduct::query()->where('itemId', $id)->delete();
            SubCategoryProduct::query()->where('product_id', $id)->delete();

            $sizeIds = Size::query()->where('itemId', $id)->pluck('id');
            if ($sizeIds->isNotEmpty()) {
                SizeColor::query()->whereIn('sizeId', $sizeIds)->delete();
            }
            Size::query()->where('itemId', $id)->delete();

            $product->forceDelete();
        });
    }

    /**
     * دمج: إعدادات config + قيم مميزة من جدول sizes + أحجام المنتج الحالي.
     * لا يوجد في Laravel ربط رسمي بين الحجم والتصنيف (الحقل نص حر كما في .NET).
     */
    private function buildSizeOptions(Product $prefill): Collection
    {
        $fromConfig = collect(config('store.size_options', []))->filter(fn ($s) => $s !== null && $s !== '');

        $fromDb = Size::query()
            ->whereNotNull('size')
            ->where('size', '!=', '')
            ->distinct()
            ->orderBy('size')
            ->pluck('size');

        $merged = $fromConfig->merge($fromDb)->unique()->sort()->values();

        foreach ($prefill->sizes as $s) {
            if ($s->size && ! $merged->contains($s->size)) {
                $merged->push($s->size);
            }
        }

        return $merged->unique()->sort()->values();
    }

    public function run(Request $request, ProductFormService $productFormService): RedirectResponse
    {
        $steps = [];
        $pageLog = function (string $msg, array $ctx = []) use (&$steps): void {
            $steps[] = [
                'time' => now()->format('H:i:s.v'),
                'message' => $msg,
                'context' => $ctx,
            ];
            Log::info('[product-edit-test page] '.$msg, $ctx);
        };

        try {
            $out = $productFormService->update($request, $pageLog);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()
                ->route('test.product-edit', ['product_id' => $request->input('product_id')])
                ->withInput()
                ->withErrors($e->errors());
        }

        /** @var Product $product */
        $product = $out['product'];
        $result = $out['sync_result'];

        return redirect()
            ->route('test.product-edit', ['product_id' => $product->id])
            ->with('steps', $steps)
            ->with('result', $result)
            ->with('product_model', $product);
    }
}
