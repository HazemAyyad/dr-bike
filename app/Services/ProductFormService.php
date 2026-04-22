<?php

namespace App\Services;

use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategoryProduct;
use App\Models\ViewImageProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * منطق مشترك: إنشاء/تعديل منتج (نموذج الاختبار + API).
 */
class ProductFormService
{
    public function __construct(
        private readonly StoreManageItemService $storeManageItemService
    ) {}

    /**
     * قواعد التحقق نفس صفحة الاختبار.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateForm(Request $request, bool $forEdit): array
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
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi,webm', 'max:51200'],
            'normal_images.*' => ['nullable', 'image', 'max:10240'],
            'three_d_images.*' => ['nullable', 'image', 'max:10240'],
            'view_images.*' => ['nullable', 'image', 'max:10240'],
            'save_scope' => ['required', 'in:full,local_only'],
            'delete_normal_image_ids' => ['nullable', 'array'],
            'delete_normal_image_ids.*' => ['integer'],
            'delete_view_image_ids' => ['nullable', 'array'],
            'delete_view_image_ids.*' => ['integer'],
            'delete_three_d_image_ids' => ['nullable', 'array'],
            'delete_three_d_image_ids.*' => ['integer'],
            'delete_video' => ['nullable', 'boolean'],
        ];

        if ($forEdit) {
            $rules['product_id'] = ['required', 'exists:products,id'];
        }

        return $request->validate($rules);
    }

    public function wantsStoreSync(Request $request): bool
    {
        return $request->input('save_scope', 'full') === 'full';
    }

    /**
     * @param  callable|null  $step  function (string $message, array $context = []): void
     * @return array<string, mixed>
     */
    public function create(Request $request, ?callable $step = null): array
    {
        $validated = $this->validateForm($request, false);
        $trace = $this->makeTracer($step);

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

        $localOnly = ! $this->wantsStoreSync($request);
        $trace('طلب إنشاء منتج', ['save_scope' => $validated['save_scope'], 'local_only' => $localOnly]);

        if ($localOnly) {
            $newId = (int) (Product::query()->max('id') ?? 0) + 1;
            if (Product::query()->where('id', $newId)->exists()) {
                return [
                    'success' => false,
                    'reason' => 'id_conflict',
                    'message' => 'تعارض في رقم المنتج، أعد المحاولة.',
                ];
            }

            Product::query()->create(array_merge($insert, ['id' => $newId]));
            $trace('تم إنشاء المنتج محلياً (بدون متجر)', ['product_id' => $newId]);

            foreach ($subIds as $sid) {
                SubCategoryProduct::create([
                    'product_id' => $newId,
                    'sub_category_id' => $sid,
                ]);
            }
            $sizesInput = array_values(array_filter(
                $request->input('sizes', []),
                fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
            ));
            $this->replaceSizesFromTestForm($sizesInput, $newId);
            $trace('مقاسات/ألوان محلية', ['count' => count($sizesInput)]);

            $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($newId);

            $mediaRes = $this->storeManageItemService->saveUploadedMediaToLaravelOnly($request, $product, $step);
            $trace('وسائط محلية (صور/فيديو)', $mediaRes);

            $result = ['ok' => true, 'skipped' => true, 'local_only' => true];
            if (empty($mediaRes['ok'])) {
                $result['ok'] = false;
                $result['media_error'] = $mediaRes['error'] ?? 'فشل حفظ الملفات';
            }

            return [
                'success' => true,
                'product' => $product->fresh([
                    'subCategories',
                    'sizes.colorSizes',
                    'normalImages',
                    'viewImages',
                    'image3d',
                ]),
                'sync_result' => $result,
            ];
        }

        $result = $this->storeManageItemService->syncNewProductToStore($virtual, $trace, $request);
        $trace('نتيجة ManageItem (إنشاء)', $result);

        if (empty($result['ok'])) {
            return [
                'success' => false,
                'sync_result' => $result,
                'reason' => 'store_sync_failed',
            ];
        }

        if (! empty($result['skipped'])) {
            $newId = (int) (Product::query()->max('id') ?? 0) + 1;
            $trace('مزامنة المتجر متخطاة — تخصيص محلي', ['allocated_id' => $newId]);
        } else {
            $newId = (int) ($result['store_new_id'] ?? 0);
        }

        if ($newId <= 0) {
            return [
                'success' => false,
                'sync_result' => array_merge($result, [
                    'ok' => false,
                    'error' => 'لم يُستخرج رقم المنتج من المتجر.',
                ]),
                'reason' => 'no_store_id',
            ];
        }

        if (Product::query()->where('id', $newId)->exists()) {
            return [
                'success' => false,
                'reason' => 'local_duplicate',
                'message' => 'المنتج #'.$newId.' موجود مسبقاً محلياً.',
                'new_id' => $newId,
            ];
        }

        Product::query()->create(array_merge($insert, ['id' => $newId]));
        $trace('تم إنشاء المنتج محلياً', ['product_id' => $newId]);

        foreach ($subIds as $sid) {
            SubCategoryProduct::create([
                'product_id' => $newId,
                'sub_category_id' => $sid,
            ]);
        }
        $trace('فئات فرعية', ['ids' => $subIds]);

        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));
        $this->replaceSizesFromTestForm($sizesInput, $newId);
        $trace('تم حفظ المقاسات/الألوان محلياً', ['count' => count($sizesInput)]);

        $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($newId);

        $imgResult = $this->storeManageItemService->pushStandaloneImagesToStore($product, $trace, $request);
        $trace('رفع الصور (AddImgToItem)', $imgResult);

        $mergedResult = $result;
        if (empty($imgResult['ok'] ?? true) && empty($imgResult['skipped'] ?? false)) {
            $mergedResult = array_merge($result, ['image_error' => $imgResult['error'] ?? 'فشل رفع الصور']);
        }

        return [
            'success' => true,
            'product' => $product->fresh(['subCategories', 'sizes.colorSizes']),
            'sync_result' => $mergedResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Request $request, ?callable $step = null): array
    {
        $validated = $this->validateForm($request, true);
        $trace = $this->makeTracer($step);

        $trace('طلب التعديل', [
            'product_id' => $validated['product_id'],
            'save_scope' => $validated['save_scope'],
            'local_only' => ! $this->wantsStoreSync($request),
        ]);

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
        $trace('تم تحديث المنتج محلياً', ['product_id' => $product->id]);

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
        $trace('فئات فرعية', ['ids' => $subIds]);

        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));
        $this->replaceSizesFromTestForm($sizesInput, $product->id);
        $trace('تم تحديث المقاسات/الألوان محلياً', ['count' => count($sizesInput)]);

        $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($product->id);

        $this->applyMediaDeletions($request, $product, $trace);

        $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($product->id);

        if ($this->wantsStoreSync($request)) {
            $result = $this->storeManageItemService->syncProductEditToStore($product, $trace, $request);
            $trace('انتهاء المزامنة', $result);
        } else {
            $trace('تخطي المتجر — حفظ Laravel فقط');
            $mediaRes = $this->storeManageItemService->saveUploadedMediaToLaravelOnly($request, $product, $trace);
            $trace('وسائط محلية', $mediaRes);
            $result = ['ok' => (bool) ($mediaRes['ok'] ?? true), 'skipped' => true, 'local_only' => true];
            if (empty($mediaRes['ok'])) {
                $result['media_error'] = $mediaRes['error'] ?? 'فشل حفظ الملفات';
            }
        }

        return [
            'success' => true,
            'product' => $product->fresh([
                'subCategories',
                'sizes.colorSizes',
                'normalImages',
                'viewImages',
                'image3d',
            ]),
            'sync_result' => $result,
        ];
    }

    /**
     * حذف صور/فيديو مسجّلة مسبقاً أثناء التعديل (Laravel دائماً؛ المتجر عند save_scope=full).
     *
     * @param  callable(string, array): void  $trace
     */
    private function applyMediaDeletions(Request $request, Product $product, callable $trace): void
    {
        $wantsSync = $this->wantsStoreSync($request);

        foreach ((array) $request->input('delete_normal_image_ids', []) as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $model = NormalImageProduct::query()
                ->where('itemId', $product->id)
                ->where('id', $id)
                ->first();
            if ($model === null) {
                continue;
            }
            if ($wantsSync) {
                $remote = $this->storeManageItemService->deleteImageFromStore((int) $product->id, $model->id, 'normal');
                if (! ($remote['ok'] ?? false)) {
                    throw ValidationException::withMessages([
                        'delete_normal_image_ids' => [$remote['error'] ?? 'فشل حذف الصورة من المتجر'],
                    ]);
                }
            }
            $this->deletePublicDiskFileFromProductUrl($model->imageUrl);
            $model->delete();
            $trace('حذف صورة عادية', ['id' => $id]);
        }

        foreach ((array) $request->input('delete_view_image_ids', []) as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $model = ViewImageProduct::query()
                ->where('itemId', $product->id)
                ->where('id', $id)
                ->first();
            if ($model === null) {
                continue;
            }
            if ($wantsSync) {
                $remote = $this->storeManageItemService->deleteImageFromStore((int) $product->id, $model->id, 'view');
                if (! ($remote['ok'] ?? false)) {
                    throw ValidationException::withMessages([
                        'delete_view_image_ids' => [$remote['error'] ?? 'فشل حذف الصورة من المتجر'],
                    ]);
                }
            }
            $this->deletePublicDiskFileFromProductUrl($model->imageUrl);
            $model->delete();
            $trace('حذف صورة عرض', ['id' => $id]);
        }

        foreach ((array) $request->input('delete_three_d_image_ids', []) as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $model = Image3dProduct::query()
                ->where('itemId', $product->id)
                ->where('id', $id)
                ->first();
            if ($model === null) {
                continue;
            }
            if ($wantsSync) {
                $remote = $this->storeManageItemService->deleteImageFromStore((int) $product->id, $model->id, 'three_d');
                if (! ($remote['ok'] ?? false)) {
                    throw ValidationException::withMessages([
                        'delete_three_d_image_ids' => [$remote['error'] ?? 'فشل حذف الصورة من المتجر'],
                    ]);
                }
            }
            $this->deletePublicDiskFileFromProductUrl($model->imageUrl);
            $model->delete();
            $trace('حذف صورة ثلاثية الأبعاد', ['id' => $id]);
        }

        if ($request->boolean('delete_video')) {
            $url = $product->videoUrl;
            if (is_string($url) && $url !== '') {
                $this->deletePublicDiskFileFromProductUrl($url);
                $product->update(['videoUrl' => null]);
                $trace('حذف فيديو المنتج', []);
            }
        }
    }

    private function deletePublicDiskFileFromProductUrl(?string $imageUrl): void
    {
        if ($imageUrl === null || $imageUrl === '') {
            return;
        }

        $path = ltrim((string) $imageUrl, '/');
        if (str_contains($path, '://')) {
            $parsed = parse_url($path, PHP_URL_PATH);
            if (! is_string($parsed) || $parsed === '') {
                return;
            }
            $path = ltrim($parsed, '/');
        }
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }
        if ($path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * @return callable(string, array): void
     */
    private function makeTracer(?callable $step): callable
    {
        return function (string $message, array $context = []) use ($step): void {
            Log::info('[product-form] '.$message, $context);
            if ($step !== null) {
                $step($message, $context);
            }
        };
    }

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

    /**
     * @param  array<int, mixed>  $newSizes
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
                // Size::$incrementing = false — Eloquent won't fetch lastInsertId automatically.
                // Retrieve it explicitly so colorSizes can reference the correct sizeId.
                if (empty($size->id)) {
                    $size->id = \DB::getPdo()->lastInsertId();
                }
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
                            'wholesalePrice' => $colorData['wholesalePrice'] ?? $color->wholesalePrice,
                            'discount' => $colorData['discount'] ?? $color->discount,
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
                        'wholesalePrice' => $colorData['wholesalePrice'] ?? 0,
                        'discount' => $colorData['discount'] ?? 0,
                        'stock' => $colorData['stock'] ?? 0,
                    ]);
                }
            }
        }
    }
}
