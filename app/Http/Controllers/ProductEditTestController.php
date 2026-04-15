<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategoryProduct;
use App\Services\StoreManageItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * نموذج تجريبي يشبه لوحة إدارة المتجر — تعديل محلي ثم مزامنة ManageItem.
 */
class ProductEditTestController extends Controller
{
    public function show(Request $request)
    {
        $pid = (int) $request->query('product_id', 532);
        $prefill = Product::with(['subCategories', 'sizes.colorSizes'])->find($pid);

        return view('product-edit-test', [
            'prefill' => $prefill,
            'steps' => session('steps'),
            'result' => session('result'),
            'product' => session('product_model'),
        ]);
    }

    public function run(Request $request, StoreManageItemService $storeManageItemService)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
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
            'sub_categories' => ['nullable', 'string'],
            'sizes' => ['nullable', 'array'],
            'sizes.*.id' => ['nullable', 'integer', 'exists:sizes,id'],
            'sizes.*.size' => ['nullable', 'string', 'max:50'],
            'sizes.*.color_sizes' => ['nullable', 'array'],
            'sizes.*.color_sizes.*.id' => ['nullable', 'integer', 'exists:size_colors,id'],
            'sizes.*.color_sizes.*.colorAr' => ['nullable', 'string', 'max:100'],
            'sizes.*.color_sizes.*.normailPrice' => ['nullable', 'numeric', 'min:0'],
            'sizes.*.color_sizes.*.stock' => ['nullable', 'integer', 'min:0'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi', 'max:51200'],
            'normal_images.*' => ['nullable', 'image', 'max:10240'],
            'three_d_images.*' => ['nullable', 'image', 'max:10240'],
            'view_images.*' => ['nullable', 'image', 'max:10240'],
        ]);

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
            'isShow' => $request->boolean('isShow', true),
            'isNewItem' => $request->boolean('isNewItem', true),
            'isMoreSales' => $request->boolean('isMoreSales', true),
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

        if ($request->filled('sub_categories')) {
            $ids = array_filter(array_map('trim', explode(',', $request->input('sub_categories', ''))));
            SubCategoryProduct::where('product_id', $product->id)->delete();
            foreach ($ids as $sid) {
                $sid = (int) $sid;
                if ($sid > 0) {
                    SubCategoryProduct::create([
                        'product_id' => $product->id,
                        'sub_category_id' => $sid,
                    ]);
                }
            }
            $pageLog('فئات فرعية', ['ids' => $ids]);
        }

        $sizesInput = array_values(array_filter(
            $request->input('sizes', []),
            fn ($r) => is_array($r) && (! empty($r['size']) || ! empty($r['id']))
        ));
        if (count($sizesInput) > 0) {
            $this->replaceSizesFromTestForm($sizesInput, (int) $product->id);
            $pageLog('تم تحديث المقاسات/الألوان محلياً', []);
        }

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
     * نفس منطق Stocks::replaceSizes لطلب الاختبار.
     *
     * @param  array<int, mixed>  $newSizes
     */
    private function replaceSizesFromTestForm(array $newSizes, int $productId): void
    {
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
                            'normailPrice' => $colorData['normailPrice'] ?? $color->normailPrice,
                            'stock' => $colorData['stock'] ?? $color->stock,
                        ]);
                    }
                } else {
                    SizeColor::create([
                        'sizeId' => $size->id,
                        'colorAr' => $colorData['colorAr'] ?? '',
                        'normailPrice' => $colorData['normailPrice'] ?? 0,
                        'stock' => $colorData['stock'] ?? 0,
                    ]);
                }
            }
        }
    }
}
