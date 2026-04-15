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
            'resolveMediaUrl' => static function (?string $url): ?string {
                if ($url === null || $url === '') {
                    return null;
                }
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    return $url;
                }
                $base = rtrim((string) config('store.domain'), '/');
                if ($base === '') {
                    return $url;
                }

                return $base.'/'.ltrim($url, '/');
            },
            'steps' => session('steps'),
            'result' => session('result'),
            'product' => session('product_model'),
            'store_item_exists' => null,
        ]);
    }

    /**
     * إنشاء منتج: المتجر يُنشئ المعرف عبر ManageItem بـ Id=0؛ نحفظ محلياً بنفس الرقم ثم نرفع الصور.
     */
    public function createRun(Request $request, StoreManageItemService $storeManageItemService): RedirectResponse
    {
        $validated = $this->validateProductFormRequest($request, false);

        $steps = [];
        $pageLog = function (string $msg, array $ctx = []) use (&$steps): void {
            $steps[] = [
                'time' => now()->format('H:i:s.v'),
                'message' => $msg,
                'context' => $ctx,
            ];
            Log::info('[product-create-test page] '.$msg, $ctx);
        };

        $pageLog('طلب إنشاء منتج (مزامنة المتجر أولاً بـ Id=0)');

        $nameAr = $validated['nameAr'];
        $nameEng = $validated['nameEng'] !== null && $validated['nameEng'] !== '' ? $validated['nameEng'] : $nameAr;
        $nameAbree = $validated['nameAbree'] !== null && $validated['nameAbree'] !== '' ? $validated['nameAbree'] : $nameAr;

        $insert = [
            'nameAr' => $nameAr,
            'nameEng' => $nameEng,
            'nameAbree' => $nameAbree,
            'descriptionAr' => $validated['descriptionAr'],
            'descriptionEng' => $validated['descriptionEng'] ?? $validated['descriptionAr'],
            'descriptionAbree' => $validated['descriptionAbree'] ?? $validated['descriptionAr'],
            'min_stock' => $validated['min_stock'],
            'normailPrice' => $validated['normailPrice'],
            'wholesalePrice' => $validated['wholesalePrice'] ?? 0,
            'discount' => $validated['discount'],
            'is_sold_with_paper' => (int) $validated['is_sold_with_paper'],
            'manufactureYear' => $validated['manufactureYear'] ?? 0,
            'rate' => $validated['rate'] ?? 4,
            'isShow' => $request->boolean('isShow'),
            'isNewItem' => $request->boolean('isNewItem'),
            'isMoreSales' => $request->boolean('isMoreSales'),
            'model' => $validated['model'] ?? '',
            'stock' => 0,
        ];

        if ($request->filled('stock')) {
            $insert['stock'] = (int) $validated['stock'];
        }
        if ($request->filled('min_sale_price')) {
            $insert['min_sale_price'] = $validated['min_sale_price'];
        }
        if ($request->filled('rotation_date')) {
            $insert['rotation_date'] = $validated['rotation_date'];
        }
        if ($request->filled('price')) {
            $insert['price'] = $validated['price'];
        }

        $virtual = new Product($insert);
        $virtual->id = 0;

        $subIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('sub_categories', [])),
            fn (int $id) => $id > 0
        )));
        $virtual->setRelation('subCategories', collect($subIds)->map(
            fn (int $sid) => new SubCategoryProduct([
                'product_id' => 0,
                'sub_category_id' => $sid,
            ])
        ));

        $this->attachUnsavedSizesFromRequest($virtual, $request);

        $result = $storeManageItemService->syncNewProductToStore($virtual, $pageLog, $request);
        $pageLog('نتيجة ManageItem (إنشاء)', $result);

        if (empty($result['ok'])) {
            return redirect()
                ->route('test.product-create')
                ->withInput()
                ->with('steps', $steps)
                ->with('result', $result);
        }

        if (! empty($result['skipped'])) {
            $newId = (int) (Product::query()->max('id') ?? 0) + 1;
            $pageLog('مزامنة المتجر متخطاة — تخصيص محلي', ['allocated_id' => $newId]);
        } else {
            $newId = (int) ($result['store_new_id'] ?? 0);
        }

        if ($newId <= 0) {
            return redirect()
                ->route('test.product-create')
                ->withInput()
                ->with('steps', $steps)
                ->with('result', [
                    'ok' => false,
                    'error' => 'لم يُستخرج رقم المنتج من المتجر.',
                ]);
        }

        if (Product::query()->where('id', $newId)->exists()) {
            return redirect()
                ->route('test.product-create')
                ->withInput()
                ->withErrors(['nameAr' => 'المنتج #'.$newId.' موجود مسبقاً محلياً.']);
        }

        Product::query()->create(array_merge($insert, ['id' => $newId]));
        $pageLog('تم إنشاء المنتج محلياً', ['product_id' => $newId]);

        foreach ($subIds as $sid) {
            SubCategoryProduct::create([
                'product_id' => $newId,
                'sub_category_id' => $sid,
            ]);
        }
        $pageLog('فئات فرعية', ['ids' => $subIds]);

        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));
        $this->replaceSizesFromTestForm($sizesInput, $newId);
        $pageLog('تم حفظ المقاسات/الألوان محلياً', ['count' => count($sizesInput)]);

        $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($newId);

        $imgResult = $storeManageItemService->pushStandaloneImagesToStore($product, $pageLog, $request);
        $pageLog('رفع الصور (AddImgToItem)', $imgResult);

        $mergedResult = $result;
        if (empty($imgResult['ok'] ?? true) && empty($imgResult['skipped'] ?? false)) {
            $mergedResult = array_merge($result, ['image_error' => $imgResult['error'] ?? 'فشل رفع الصور']);
        }

        return redirect()
            ->route('test.product-edit', ['product_id' => $product->id])
            ->with('steps', $steps)
            ->with('result', $mergedResult)
            ->with('product_model', $product->fresh(['subCategories', 'sizes.colorSizes']));
    }

    /**
     * مقاسات/ألوان من الطلب كعلاقات غير محفوظة (للدمج مع المتجر قبل وجود صف في Laravel).
     */
    private function attachUnsavedSizesFromRequest(Product $product, Request $request): void
    {
        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));

        if ($sizesInput === []) {
            $product->setRelation('sizes', collect());

            return;
        }

        $sizes = collect();
        foreach ($sizesInput as $sizeData) {
            if (! is_array($sizeData)) {
                continue;
            }

            $size = new Size([
                'size' => $sizeData['size'] ?? '',
                'itemId' => 0,
                'discount' => 0,
                'description' => '',
            ]);
            $size->id = 0;

            $colors = collect();
            foreach ($sizeData['color_sizes'] ?? [] as $colorData) {
                if (! is_array($colorData)) {
                    continue;
                }
                $color = new SizeColor([
                    'colorAr' => $colorData['colorAr'] ?? '',
                    'colorEn' => $colorData['colorEn'] ?? '',
                    'colorAbbr' => $colorData['colorAbbr'] ?? '',
                    'normailPrice' => $colorData['normailPrice'] ?? 0,
                    'wholesalePrice' => 0,
                    'discount' => 0,
                    'stock' => (int) ($colorData['stock'] ?? 0),
                    'sizeId' => 0,
                ]);
                $color->id = 0;
                $colors->push($color);
            }

            $size->setRelation('colorSizes', $colors);
            $sizes->push($size);
        }

        $product->setRelation('sizes', $sizes);
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
            'resolveMediaUrl' => static function (?string $url): ?string {
                if ($url === null || $url === '') {
                    return null;
                }
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    return $url;
                }
                $base = rtrim((string) config('store.domain'), '/');
                if ($base === '') {
                    return $url;
                }

                return $base.'/'.ltrim($url, '/');
            },
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

    public function run(Request $request, StoreManageItemService $storeManageItemService)
    {
        $validated = $this->validateProductFormRequest($request, true);

        $steps = [];
        $pageLog = function (string $msg, array $ctx = []) use (&$steps): void {
            $steps[] = [
                'time' => now()->format('H:i:s.v'),
                'message' => $msg,
                'context' => $ctx,
            ];
            Log::info('[product-edit-test page] '.$msg, $ctx);
        };

        $pageLog('طلب التعديل', ['product_id' => $validated['product_id']]);

        $product = Product::findOrFail($validated['product_id']);

        $nameAr = $validated['nameAr'];
        $nameEng = $validated['nameEng'] !== null && $validated['nameEng'] !== '' ? $validated['nameEng'] : $nameAr;
        $nameAbree = $validated['nameAbree'] !== null && $validated['nameAbree'] !== '' ? $validated['nameAbree'] : $nameAr;

        $update = [
            'nameAr' => $nameAr,
            'nameEng' => $nameEng,
            'nameAbree' => $nameAbree,
            'descriptionAr' => $validated['descriptionAr'],
            'descriptionEng' => $validated['descriptionEng'] ?? $validated['descriptionAr'],
            'descriptionAbree' => $validated['descriptionAbree'] ?? $validated['descriptionAr'],
            'min_stock' => $validated['min_stock'],
            'normailPrice' => $validated['normailPrice'],
            'wholesalePrice' => $validated['wholesalePrice'] ?? 0,
            'discount' => $validated['discount'],
            'is_sold_with_paper' => (int) $validated['is_sold_with_paper'],
            'manufactureYear' => $validated['manufactureYear'] ?? 0,
            'rate' => $validated['rate'] ?? 4,
            // Unchecked HTML checkboxes are omitted from the request; default must be false.
            'isShow' => $request->boolean('isShow'),
            'isNewItem' => $request->boolean('isNewItem'),
            'isMoreSales' => $request->boolean('isMoreSales'),
            'model' => $validated['model'] ?? '',
        ];

        if ($request->filled('stock')) {
            $update['stock'] = (int) $validated['stock'];
        }
        if ($request->filled('min_sale_price')) {
            $update['min_sale_price'] = $validated['min_sale_price'];
        }
        if ($request->filled('rotation_date')) {
            $update['rotation_date'] = $validated['rotation_date'];
        }
        if ($request->filled('price')) {
            $update['price'] = $validated['price'];
        }

        $product->update($update);
        $pageLog('تم تحديث المنتج محلياً', ['product_id' => $product->id]);

        $subIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('sub_categories', [])),
            fn (int $id) => $id > 0
        )));
        SubCategoryProduct::where('product_id', $product->id)->delete();
        foreach ($subIds as $sid) {
            SubCategoryProduct::create([
                'product_id' => $product->id,
                'sub_category_id' => $sid,
            ]);
        }
        $pageLog('فئات فرعية', ['ids' => $subIds]);

        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));
        $this->replaceSizesFromTestForm($sizesInput, $product->id);
        $pageLog('تم تحديث المقاسات/الألوان محلياً', ['count' => count($sizesInput)]);

        $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($product->id);

        $result = $storeManageItemService->syncProductEditToStore($product, $pageLog, $request);
        $pageLog('انتهاء المزامنة', $result);

        return redirect()
            ->route('test.product-edit', ['product_id' => $product->id])
            ->with('steps', $steps)
            ->with('result', $result)
            ->with('product_model', $product->fresh(['subCategories', 'sizes.colorSizes']));
    }

    /**
     * قواعد مشتركة بين إنشاء منتج وتعديله.
     *
     * @return array<string, mixed>
     */
    private function validateProductFormRequest(Request $request, bool $forEdit): array
    {
        $rules = [
            'nameAr' => ['required', 'string', 'max:500'],
            'nameEng' => ['nullable', 'string', 'max:500'],
            'nameAbree' => ['nullable', 'string', 'max:500'],
            'descriptionAr' => ['required', 'string'],
            'descriptionEng' => ['nullable', 'string'],
            'descriptionAbree' => ['nullable', 'string'],
            'manufactureYear' => ['nullable', 'integer', 'min:0', 'max:2100'],
            'discount' => ['required', 'numeric', 'min:0'],
            'normailPrice' => ['required', 'numeric', 'min:0'],
            'wholesalePrice' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'isShow' => ['nullable', 'boolean'],
            'isNewItem' => ['nullable', 'boolean'],
            'isMoreSales' => ['nullable', 'boolean'],
            'model' => ['nullable', 'string', 'max:255'],
            'min_stock' => ['required', 'numeric', 'min:0'],
            'is_sold_with_paper' => ['required', 'in:0,1'],
            'min_sale_price' => ['nullable', 'numeric', 'min:0'],
            'rotation_date' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'sub_categories' => ['nullable', 'array'],
            'sub_categories.*' => ['integer', 'exists:sub_categories,id'],
            'sizes' => ['nullable', 'array'],
            'sizes.*.id' => ['nullable', 'integer', 'exists:sizes,id'],
            'sizes.*.size' => ['nullable', 'string', 'max:50'],
            'sizes.*.color_sizes' => ['nullable', 'array'],
            'sizes.*.color_sizes.*.id' => ['nullable', 'integer', 'exists:size_colors,id'],
            'sizes.*.color_sizes.*.colorAr' => ['nullable', 'string', 'max:100'],
            'sizes.*.color_sizes.*.colorEn' => ['nullable', 'string', 'max:100'],
            'sizes.*.color_sizes.*.colorAbbr' => ['nullable', 'string', 'max:100'],
            'sizes.*.color_sizes.*.normailPrice' => ['nullable', 'numeric', 'min:0'],
            'sizes.*.color_sizes.*.stock' => ['nullable', 'integer', 'min:0'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi', 'max:51200'],
            'normal_images.*' => ['nullable', 'image', 'max:10240'],
            'three_d_images.*' => ['nullable', 'image', 'max:10240'],
            'view_images.*' => ['nullable', 'image', 'max:10240'],
        ];

        if ($forEdit) {
            $rules['product_id'] = ['required', 'exists:products,id'];
        }

        return $request->validate($rules);
    }

    /**
     * نفس منطق Stocks::replaceSizes لطلب الاختبار.
     *
     * @param  array<int, mixed>  $newSizes
     */
    /**
     * @param  int|string  $productId
     */
    private function replaceSizesFromTestForm(array $newSizes, $productId): void
    {
        if ($newSizes === []) {
            Size::where('itemId', $productId)->delete();

            return;
        }

        $existingSizeIds = Size::where('itemId', $productId)->pluck('id')->toArray();
        $newSizeIds = collect($newSizes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        $sizesToDelete = array_diff($existingSizeIds, $newSizeIds);

        if (! empty($sizesToDelete)) {
            Size::whereIn('id', $sizesToDelete)->delete();
        }

        foreach ($newSizes as $sizeData) {
            if (! is_array($sizeData)) {
                continue;
            }

            if (! empty($sizeData['id']) && in_array((int) $sizeData['id'], $existingSizeIds, true)) {
                $size = Size::find($sizeData['id']);
                if ($size) {
                    $size->update([
                        'size' => $sizeData['size'] ?? $size->size,
                        'itemId' => $productId,
                    ]);
                } else {
                    continue;
                }
            } else {
                $size = Size::create([
                    'size' => $sizeData['size'] ?? '',
                    'itemId' => $productId,
                ]);
            }

            $existingColorIds = $size->colorSizes()->pluck('id')->toArray();
            $newColors = $sizeData['color_sizes'] ?? [];
            $newColorIds = collect($newColors)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $colorsToDelete = array_diff($existingColorIds, $newColorIds);

            if (! empty($colorsToDelete)) {
                SizeColor::whereIn('id', $colorsToDelete)->delete();
            }

            foreach ($newColors as $colorData) {
                if (! is_array($colorData)) {
                    continue;
                }
                if (! empty($colorData['id']) && in_array((int) $colorData['id'], $existingColorIds, true)) {
                    $color = SizeColor::find($colorData['id']);
                    if ($color) {
                        $color->update([
                            'colorAr' => $colorData['colorAr'] ?? $color->colorAr,
                            'colorEn' => $colorData['colorEn'] ?? $color->colorEn,
                            'colorAbbr' => $colorData['colorAbbr'] ?? $color->colorAbbr,
                            'normailPrice' => $colorData['normailPrice'] ?? $color->normailPrice,
                            'stock' => $colorData['stock'] ?? $color->stock,
                        ]);
                    }
                } else {
                    SizeColor::create([
                        'sizeId' => $size->id,
                        'colorAr' => $colorData['colorAr'] ?? '',
                        'colorEn' => $colorData['colorEn'] ?? '',
                        'colorAbbr' => $colorData['colorAbbr'] ?? '',
                        'normailPrice' => $colorData['normailPrice'] ?? 0,
                        'stock' => $colorData['stock'] ?? 0,
                    ]);
                }
            }
        }
    }
}
