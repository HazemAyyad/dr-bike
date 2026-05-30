<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Closeout;
use App\Models\Combination;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseProduct;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\WholesaleProduct;
use App\Services\ProductFormService;
use App\Services\ProductTagService;
use App\Services\StoreManageItemService;
use App\Support\ApiImageUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Stocks extends Controller
{
    private const PRODUCT_IMPORT_COLUMNS = [
        'product_id' => ['product_id', 'id', 'معرف المنتج', 'رقم المنتج'],
        'product_name' => ['product_name', 'name', 'اسم المنتج', 'اسم المنتح'],
        'retail_price' => ['retail_price', 'normail_price', 'normal_price', 'سعر المفرق', 'سعر المفرف'],
        'wholesale_price' => ['wholesale_price', 'سعر الجملة'],
        'cost_price' => ['cost_price', 'purchase_price', 'سعر التكلفة'],
        'quantity' => ['quantity', 'stock', 'العدد', 'المخزون'],
    ];

    /**
     * Return a path relative to the Laravel public root (e.g. Images/Items/...).
     * Clients prepend their own API image base — avoids cross-origin CORS from legacy STORE_DOMAIN hosts.
     */
    private function publicImagePath(?string $imageUrl): string
    {
        return ApiImageUrl::normalize($imageUrl);
    }

    public function allProducts(Request $request)
    {
        try {
            ini_set('max_execution_time', 2000);

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

            $sortColumn = match ($sortBy) {
                'name' => 'nameAr',
                'updated_at' => 'updated_at',
                default => 'created_at',
            };

            $query = Product::query()
                ->with(['viewImages', 'normalImages', 'tags' => function ($q) {
                    $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                }])
                ->select('id', 'nameAr', 'stock', 'product_code', 'category_id', 'created_at', 'updated_at');

            if ($request->filled('search')) {
                $term = '%'.$request->string('search').'%';
                $query->where(function ($q) use ($term) {
                    $q->where('nameAr', 'like', $term)
                        ->orWhere('product_code', 'like', $term);
                });
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->input('category_id'));
            }

            $subCategoryId = $request->input('sub_category_id', $request->input('subcategory_id'));
            if ($subCategoryId !== null && $subCategoryId !== '') {
                $query->whereHas('subCategories', function ($q) use ($subCategoryId) {
                    $q->where('sub_category_id', (int) $subCategoryId);
                });
            }

            if ($request->filled('tag_id')) {
                $query->whereHas('tags', function ($q) use ($request) {
                    $q->where('product_tags.id', (int) $request->input('tag_id'));
                });
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date('date_to'));
            }

            $products = $query
                ->orderBy($sortColumn, $sortDirection)
                ->paginate($perPage);

            $formatted = $products->getCollection()->map(fn ($product) => $this->formatProductListItem($product));

            return response()->json([
                'status' => 'success',
                'products' => $formatted->values(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'next_page_url' => $products->nextPageUrl(),
                    'prev_page_url' => $products->previousPageUrl(),
                ],
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function exportProductsCsv(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $fileName = 'doctor-bike-products-'.now()->format('Y-m-d-H-i').'.csv';

        return response()->streamDownload(function () {
            echo "\xEF\xBB\xBF";
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'معرف المنتج',
                'اسم المنتج',
                'سعر المفرق',
                'سعر الجملة',
                'سعر التكلفة',
                'العدد',
            ]);

            Product::query()
                ->with(['purchasePrices' => fn ($q) => $q->latest('id')])
                ->select('id', 'nameAr', 'normailPrice', 'wholesalePrice', 'stock')
                ->orderBy('id')
                ->chunk(500, function ($products) use ($handle) {
                    foreach ($products as $product) {
                        fputcsv($handle, [
                            $product->id,
                            $product->nameAr,
                            $product->normailPrice,
                            $product->wholesalePrice,
                            optional($product->purchasePrices->first())->price ?? 0,
                            $product->stock,
                        ]);
                    }
                });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function previewProductsCsvImport(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $result = $this->readProductsCsvImport($request, false);

        return response()->json([
            'status' => 'success',
            'message' => 'تمت قراءة الملف',
            'changes_count' => count($result['changes']),
            'changes' => $result['changes'],
            'errors' => $result['errors'],
        ]);
    }

    public function importProductsCsv(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $result = $this->readProductsCsvImport($request, true);

        return response()->json([
            'status' => 'success',
            'message' => "تم استيراد {$result['updated']} منتج",
            'updated' => $result['updated'],
            'errors' => $result['errors'],
        ]);
    }

    private function readProductsCsvImport(Request $request, bool $apply): array
    {
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('messages.something_wrong')]);
        }

        $headerLine = fgets($handle);
        $delimiter = $this->detectCsvDelimiter((string) $headerLine);
        $header = str_getcsv((string) $headerLine, $delimiter);
        if ($headerLine === false || ! is_array($header) || $this->isEmptyCsvRow($header)) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'ملف المنتجات فارغ']);
        }

        $columns = $this->resolveProductImportColumns($header);
        if (! array_key_exists('product_id', $columns)) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'عمود معرف المنتج مطلوب للاستيراد']);
        }

        $updated = 0;
        $errors = [];
        $changes = [];
        $rowNumber = 1;

        while (($line = fgets($handle)) !== false) {
            $rowNumber++;
            $row = str_getcsv($line, $delimiter);

            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $productId = trim((string) ($row[$columns['product_id']] ?? ''));
            if ($productId === '' || ! ctype_digit($productId)) {
                $errors[] = "السطر {$rowNumber}: معرف المنتج غير صحيح";
                continue;
            }

            $product = Product::with(['purchasePrices' => fn ($q) => $q->latest('id')])
                ->find($productId);

            if (! $product) {
                $errors[] = "السطر {$rowNumber}: المنتج غير موجود";
                continue;
            }

            $updates = [];
            $rowChanges = [
                'row' => $rowNumber,
                'product_id' => $product->id,
                'product_name' => $product->nameAr,
                'fields' => [],
            ];

            $name = $this->csvValue($row, $columns, 'product_name');
            if ($name !== null && $name !== '') {
                $updates['nameAr'] = $name;
                $this->addProductImportChange($rowChanges, 'اسم المنتج', $product->nameAr, $name);
            }

            foreach ([
                'retail_price' => ['field' => 'normailPrice', 'label' => 'سعر المفرق'],
                'wholesale_price' => ['field' => 'wholesalePrice', 'label' => 'سعر الجملة'],
            ] as $csvKey => $config) {
                $value = $this->csvValue($row, $columns, $csvKey);
                if ($value === null || $value === '') {
                    continue;
                }
                if (! is_numeric($value) || (float) $value < 0) {
                    $errors[] = "السطر {$rowNumber}: قيمة {$csvKey} غير صحيحة";
                    continue 2;
                }
                $field = $config['field'];
                $updates[$field] = $value;
                $this->addProductImportChange($rowChanges, $config['label'], $product->{$field}, $value);
            }

            $quantity = $this->csvValue($row, $columns, 'quantity');
            if ($quantity !== null && $quantity !== '') {
                if (! is_numeric($quantity) || (int) $quantity < 0) {
                    $errors[] = "السطر {$rowNumber}: العدد غير صحيح";
                    continue;
                }
                $updates['stock'] = (int) $quantity;
                $this->addProductImportChange($rowChanges, 'العدد', $product->stock, (int) $quantity);
            }

            if ($apply && $updates !== []) {
                $product->update($updates);
            }

            $cost = $this->csvValue($row, $columns, 'cost_price');
            if ($cost !== null && $cost !== '') {
                if (! is_numeric($cost) || (float) $cost < 0) {
                    $errors[] = "السطر {$rowNumber}: سعر التكلفة غير صحيح";
                    continue;
                }

                $purchasePrice = $product->purchasePrices->first();
                $oldCost = $purchasePrice?->price ?? 0;
                $this->addProductImportChange($rowChanges, 'سعر التكلفة', $oldCost, $cost);

                if ($apply && $purchasePrice) {
                    $purchasePrice->update(['price' => $cost]);
                } elseif ($apply) {
                    PurchaseProduct::create([
                        'product_id' => $product->id,
                        'seller_id' => null,
                        'price' => $cost,
                    ]);
                }
            }

            if ($rowChanges['fields'] !== []) {
                $changes[] = $rowChanges;
                if ($apply) {
                    $updated++;
                }
            }
        }

        fclose($handle);

        return [
            'updated' => $updated,
            'changes' => $changes,
            'errors' => $errors,
        ];
    }

    private function addProductImportChange(array &$rowChanges, string $label, mixed $old, mixed $new): void
    {
        $oldNormalized = is_numeric($old) ? (string) (float) $old : trim((string) $old);
        $newNormalized = is_numeric($new) ? (string) (float) $new : trim((string) $new);

        if ($oldNormalized === $newNormalized) {
            return;
        }

        $rowChanges['fields'][] = [
            'label' => $label,
            'old' => (string) $old,
            'new' => (string) $new,
        ];
    }

    private function isAdminRequest(Request $request): bool
    {
        return strtolower((string) $request->user()?->type) === 'admin';
    }

    private function resolveProductImportColumns(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $value) {
            $normalized[$index] = $this->normalizeCsvHeader((string) $value);
        }

        $columns = [];
        foreach (self::PRODUCT_IMPORT_COLUMNS as $key => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($this->normalizeCsvHeader($alias), $normalized, true);
                if ($index !== false) {
                    $columns[$key] = $index;
                    break;
                }
            }
        }

        return $columns;
    }

    private function detectCsvDelimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function normalizeCsvHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function csvValue(array $row, array $columns, string $key): ?string
    {
        if (! array_key_exists($key, $columns)) {
            return null;
        }

        return trim((string) ($row[$columns[$key]] ?? ''));
    }

    private function formatProductListItem(Product $product): array
    {
        $image = $product->viewImages->first()
            ?? $product->normalImages->first();

        return [
            'product_id' => $product->id,
            'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
            'product_name' => $product->nameAr,
            'product_stock' => $product->stock,
            'product_code' => $product->product_code,
            'product_image' => $image ? $this->publicImagePath($image->imageUrl) : 'no image',
            'tags' => $product->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])->values(),
        ];
    }

    public function showProduct(Request $request)
    {
        try {

            $request->validate(['product_id' => 'required|integer|exists:products,id']);
            $product = Product::with([
                'category:id,nameAr',
                'subCategories.subCategory:id,nameAr,mainCategoryId',
                'subCategories.subCategory.category:id,nameAr',
                'sizes' => function ($q) {
                    $q->select('id', 'size', 'itemId');
                },
                'sizes.colorSizes' => function ($q) {
                    $q->select('id', 'colorAr', 'colorEn', 'colorAbbr', 'normailPrice', 'wholesalePrice', 'discount', 'stock', 'sizeId');
                },
                'wholesales',
                'normalImages:id,itemId,imageUrl',
                'viewImages:id,itemId,imageUrl',
                'image3d:id,itemId,imageUrl',
                'purchase:id,name',
                'tags' => function ($q) {
                    $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                },

            ])->findOrFail($request->product_id);

            $product->makeVisible(['wholesalePrice']);

            $product['product_tags'] = $product->tags->map(function ($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'color' => $t->color,
                    'is_active' => $t->is_active,
                ];
            })->values();
            $product->unsetRelation('tags');

            $mainCatId = $product->category_id;
            $filteredPivots = $product->subCategories->filter(function ($pivot) use ($mainCatId) {
                if ($mainCatId === null || $mainCatId === '') {
                    return true;
                }
                $subMain = $pivot->subCategory?->mainCategoryId;
                if ($subMain === null || $subMain === '') {
                    return false;
                }

                return (int) $subMain === (int) $mainCatId;
            })->values();

            $subs = $filteredPivots->map(function ($pivot) {
                return [
                    'sub_category_id' => $pivot->sub_category_id,
                    'sub_category_name' => $pivot->subCategory->nameAr,
                    'main_category_id' => $pivot->subCategory->category->id,
                    'main_category_name' => $pivot->subCategory->category->nameAr,

                ];
            });
            $product['product_subCategories'] = $subs;

            $product['sub_categories'] = $filteredPivots
                ->pluck('sub_category_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $product['category_name'] = $product->category?->nameAr;
            $product->unsetRelation('category');
            unset($product->subCategories);

            $purchase_prices = $product->purchasePrices->map(function ($pivot) {
                return [
                    'seller_id' => $pivot->seller_id,
                    'seller_id' => $pivot->seller->name,
                    'price' => $pivot->price,

                ];
            });
            $product['purchase_prices'] = $purchase_prices;

            unset($product->purchasePrices);

            $product['product_normalImages'] = $product->normalImages->map(function ($img) {
                return $img->imageUrl
                    ? $this->publicImagePath($img->imageUrl)
                    : 'no image';
            });

            $product['product_viewImages'] = $product->viewImages->map(function ($img) {
                return $img->imageUrl
                    ? $this->publicImagePath($img->imageUrl)
                    : 'no image';
            });

            $product['product_image3d'] = $product->image3d->map(function ($img) {
                return $img->imageUrl
                    ? $this->publicImagePath($img->imageUrl)
                    : 'no image';
            });

            $product['product_normalImages_items'] = $product->normalImages->map(function ($img) {
                return [
                    'id' => (string) $img->id,
                    'url' => $img->imageUrl ? $this->publicImagePath($img->imageUrl) : null,
                ];
            })->values();

            $product['product_viewImages_items'] = $product->viewImages->map(function ($img) {
                return [
                    'id' => (string) $img->id,
                    'url' => $img->imageUrl ? $this->publicImagePath($img->imageUrl) : null,
                ];
            })->values();

            $product['product_image3d_items'] = $product->image3d->map(function ($img) {
                return [
                    'id' => (string) $img->id,
                    'url' => $img->imageUrl ? $this->publicImagePath($img->imageUrl) : null,
                ];
            })->values();

            unset($product->normalImages);
            unset($product->viewImages);
            unset($product->image3d);

            $product->videoUrl = $product->videoUrl ? $this->publicImagePath($product->videoUrl) : null;

            return response()->json([
                'status' => 'success',
                'product' => $product,
            ], 200);

        } catch (ValidationException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }

    }

    /**
     * خيارات حجم المنتج (config + جدول sizes + أحجام المنتج الحالي إن وُجد).
     * يطابق منطق صفحة الاختبار test/product-edit.
     */
    public function productSizeOptions(Request $request)
    {
        try {
            $productId = $request->query('product_id');
            $product = $productId ? Product::with('sizes')->find($productId) : null;
            if ($product === null) {
                $product = new Product;
                $product->setRelation('sizes', collect());
            }

            $fromConfig = collect(config('store.size_options', []))->filter(fn ($s) => $s !== null && $s !== '');

            $fromDb = Size::query()
                ->whereNotNull('size')
                ->where('size', '!=', '')
                ->distinct()
                ->orderBy('size')
                ->pluck('size');

            $merged = $fromConfig->merge($fromDb)->unique();

            foreach ($product->sizes as $s) {
                if ($s->size && ! $merged->contains($s->size)) {
                    $merged->push($s->size);
                }
            }

            $sizes = $merged->unique()->sort()->values()->all();

            return response()->json([
                'status' => 'success',
                'sizes' => $sizes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function replaceSubCategories(Request $request)
    {

        $existingsubCategoriesIds = SubCategoryProduct::where('product_id', $request->product_id)
            ->pluck('sub_category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $newSubCategoryIds = array_values(array_filter(
            array_map('intval', (array) $request->input('sub_categories', [])),
            fn (int $id) => $id > 0
        ));

        $toAdd = array_values(array_diff($newSubCategoryIds, $existingsubCategoriesIds));
        $toDelete = array_values(array_diff($existingsubCategoriesIds, $newSubCategoryIds));

        // Delete unchecked permissions
        if (! empty($toDelete)) {
            SubCategoryProduct::where('product_id', $request->product_id)
                ->whereIn('sub_category_id', $toDelete)
                ->delete();
        }

        // Add newly checked permissions
        if (! empty($toAdd)) {
            foreach ($toAdd as $subCategoryId) {
                SubCategoryProduct::create([
                    'product_id' => $request->product_id,
                    'sub_category_id' => $subCategoryId,
                ]);
            }
        }
    }

    private function replaceWholesales(Request $request, $productId)
    {
        $newWholesales = $request->input('wholesales', []);
        // Example expected structure:
        // [
        //   ['id' => 5, 'price' => 100, 'pieces' => 10],
        //   ['price' => 200, 'pieces' => 20] // no id => new
        // ]

        $existingWholesales = WholesaleProduct::where('product_id', $productId)->get();

        $sentIds = collect($newWholesales)
            ->pluck('id')
            ->toArray();

        // Delete wholesales that exist in DB but not sent in request
        $toDelete = $existingWholesales->whereNotIn('id', $sentIds);
        if ($toDelete->isNotEmpty()) {
            WholesaleProduct::whereIn('id', $toDelete->pluck('id'))->delete();
        }

        // Loop through sent wholesales
        foreach ($newWholesales as $wholesale) {
            if (isset($wholesale['id'])) {
                // Update existing
                $model = WholesaleProduct::where('product_id', $productId)
                    ->where('id', $wholesale['id'])
                    ->first();

                if ($model) {
                    $model->update([
                        'price' => $wholesale['price'],
                        'pieces' => $wholesale['pieces'],
                    ]);
                }
            } else {
                // Create new
                WholesaleProduct::create([
                    'product_id' => $productId,
                    'price' => $wholesale['price'],
                    'pieces' => $wholesale['pieces'],
                ]);
            }
        }
    }

    private function replaceSizes(Request $request)
    {
        $productId = $request->product_id;

        // Get existing size IDs for this product
        $existingSizeIds = Size::where('itemId', $productId)->pluck('id')->toArray();

        $newSizes = $request->input('sizes', []); // expected: array of sizes with colors

        $newSizeIds = collect($newSizes)->pluck('id')->filter()->toArray();

        // Sizes to delete (not sent in request anymore)
        $sizesToDelete = array_diff($existingSizeIds, $newSizeIds);

        if (! empty($sizesToDelete)) {
            Size::whereIn('id', $sizesToDelete)->delete(); // cascade delete colorSizes if foreign key is set
        }

        foreach ($newSizes as $sizeData) {
            if (! empty($sizeData['id']) && in_array($sizeData['id'], $existingSizeIds)) {
                // Update existing size
                $size = Size::find($sizeData['id']);
                $size->update([
                    'size' => $sizeData['size'] ?? $size->size,
                    'itemId' => $productId,
                ]);
            } else {
                // Create new size
                $size = Size::create([
                    'size' => $sizeData['size'],
                    'itemId' => $productId,
                ]);
                // Size::$incrementing = false — retrieve actual lastInsertId explicitly.
                if (empty($size->id)) {
                    $size->id = \DB::getPdo()->lastInsertId();
                }
            }

            // ---- Handle colorSizes for this size ----
            $existingColorIds = $size->colorSizes()->pluck('id')->toArray();

            $newColors = $sizeData['color_sizes'] ?? []; // expected: array of colors
            $newColorIds = collect($newColors)->pluck('id')->toArray();

            $colorsToDelete = array_diff($existingColorIds, $newColorIds);
            if (! empty($colorsToDelete)) {
                SizeColor::whereIn('id', $colorsToDelete)->delete();
            }

            foreach ($newColors as $colorData) {
                if (! empty($colorData['id']) && in_array($colorData['id'], $existingColorIds)) {
                    // Update existing color
                    $color = SizeColor::find($colorData['id']);
                    $color->update([
                        'colorAr' => $colorData['colorAr'] ?? $color->colorAr,
                        'colorEn' => $colorData['colorEn'] ?? $color->colorEn,
                        'colorAbbr' => $colorData['colorAbbr'] ?? $color->colorAbbr,
                        'normailPrice' => $colorData['normailPrice'] ?? $color->normailPrice,
                        'wholesalePrice' => $colorData['wholesalePrice'] ?? $color->wholesalePrice,
                        'discount' => $colorData['discount'] ?? $color->discount,
                        'stock' => $colorData['stock'] ?? $color->stock,
                    ]);
                } else {
                    // Create new color
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

    public function editProduct(Request $request)
    {
        try {

            $request->validate([
                'product_id' => 'required|integer|exists:products,id',
                'nameAr' => 'required|string',
                'descriptionAr' => 'required|string',
                'category_id' => 'required|integer|exists:categories,id',
                'sub_categories' => 'nullable|array',
                'sub_categories.*' => 'integer|exists:sub_categories,id',
                'min_stock' => 'required|numeric|min:0',
                'normailPrice' => 'required|numeric|min:1',
                'discount' => 'required|numeric|min:0',
                'project_id' => 'nullable|integer|exists:projects,id',
                'rotation_date' => 'nullable|date',
                'min_sale_price' => 'nullable|numeric|min:1',
                'is_sold_with_paper' => 'required|in:0,1',
                'price' => 'nullable|numeric|min:1',
                // --- WHOLESALES ---
                'wholesales' => ['array'], // wholesales can be array
                'wholesales.*.id' => ['nullable', 'integer', 'exists:wholesale_products,id'],
                'wholesales.*.price' => ['required', 'numeric', 'min:0'],
                'wholesales.*.pieces' => ['required', 'integer', 'min:1'],

                // --- SIZES ---
                'sizes' => ['array'],
                'sizes.*.id' => ['nullable', 'integer', 'exists:sizes,id'],
                'sizes.*.size' => ['required', 'string', 'max:50'],

                // --- COLOR SIZES ---
                'sizes.*.color_sizes' => ['array'], // each size may have many colorSizes
                'sizes.*.color_sizes.*.id' => ['nullable', 'integer', 'exists:size_colors,id'],
                'sizes.*.color_sizes.*.colorAr' => ['required', 'string', 'max:100'],
                'sizes.*.color_sizes.*.normailPrice' => ['required', 'numeric', 'min:0'],
                'sizes.*.color_sizes.*.stock' => ['required', 'integer', 'min:0'],

                'tag_ids' => ['nullable', 'array'],
                'tag_ids.*' => ['integer', 'exists:product_tags,id'],

            ]);

            $product = Product::findOrFail($request->product_id);

            $updateData = $request->except(['product_id', 'sub_categories', 'wholesales', 'sizes', 'price', 'product_code', 'tag_ids']);

            $newCategoryId = (int) $request->input('category_id');
            $product->update(array_merge($updateData, [
                'category_id' => $newCategoryId,
            ]));

            SubCategoryProduct::deleteForProductOutsideMain((int) $request->product_id, $newCategoryId);

            if (! $product->price) {
                $product->update(['price' => $request->price]);
            }

            if ($request->filled('project_id')) {
                $product->update([
                    'stock' => 0,
                    'normailPrice' => 0,
                ]);
                $closeout = $product->closeout;
                if ($closeout) {
                    $closeout->status = 'archived';
                    $closeout->save();
                }
            }
            if ($request->has('sub_categories')) {
                $subIds = array_values(array_filter(
                    array_map('intval', (array) $request->input('sub_categories', [])),
                    fn (int $id) => $id > 0
                ));
                if ($subIds !== []) {
                    $this->replaceSubCategories($request);
                }
            }
            $this->replaceSizes($request);
            $this->replaceWholesales($request, $request->product_id);

            if ($request->has('tag_ids')) {
                app(ProductTagService::class)->syncTagsForProduct((int) $request->product_id, (array) $request->input('tag_ids', []));
            }

            $product = Product::with(['subCategories', 'sizes.colorSizes'])->findOrFail($request->product_id);
            $storeSync = app(StoreManageItemService::class)->syncProductEditToStore($product);

            $payload = [
                'status' => 'success',
                'message' => __('messages.product_updated'),
            ];
            if (! ($storeSync['ok'] ?? false) && empty($storeSync['skipped'])) {
                $payload['store_sync_warning'] = $storeSync['error'] ?? __('messages.something_wrong');
            }

            return response()->json($payload, 200);

        } catch (ValidationException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'error' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * إنشاء منتج (نفس حقول صفحة الاختبار): multipart للصور، save_scope = full|local_only.
     */
    public function createProduct(Request $request, ProductFormService $productFormService)
    {
        try {
            $out = $productFormService->create($request);

            if (empty($out['success'])) {
                $payload = [
                    'status' => 'error',
                    'message' => $out['message'] ?? __('messages.something_wrong'),
                ];
                if (! empty($out['sync_result'])) {
                    $payload['store_sync'] = $out['sync_result'];
                }
                if (($out['reason'] ?? '') === 'local_duplicate' && isset($out['new_id'])) {
                    $payload['conflict_product_id'] = $out['new_id'];
                }

                return response()->json($payload, 200);
            }

            $product = $out['product'];
            $sync = $out['sync_result'] ?? [];

            $payload = [
                'status' => 'success',
                'message' => __('messages.product_created'),
                'product_id' => $product->id,
                'store_sync' => $sync,
            ];
            if (! ($sync['ok'] ?? true) && ! empty($sync['media_error'])) {
                $payload['media_warning'] = $sync['media_error'];
            }
            if (! empty($sync['image_error'])) {
                $payload['image_warning'] = $sync['image_error'];
            }

            return response()->json($payload, 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * تعديل منتج بالحقول الكاملة + وسائط (مثل صفحة الاختبار): product_id مطلوب، save_scope = full|local_only.
     */
    public function updateProductFull(Request $request, ProductFormService $productFormService)
    {
        try {
            $out = $productFormService->update($request);

            $product = $out['product'];
            $sync = $out['sync_result'] ?? [];

            $payload = [
                'status' => 'success',
                'message' => __('messages.product_updated'),
                'product_id' => $product->id,
                'store_sync' => $sync,
            ];
            if (! ($sync['ok'] ?? false) && empty($sync['skipped'] ?? false)) {
                if (! empty($sync['local_only'])) {
                    $payload['media_warning'] = $sync['media_error'] ?? __('messages.something_wrong');
                } else {
                    $payload['store_sync_warning'] = $sync['error'] ?? __('messages.something_wrong');
                }
            }

            return response()->json($payload, 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // *********************** CLOSEOUTS SECTION *********************
    // add product among closeouts
    public function addProductToCloseout(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|integer|exists:products,id',

            ]);

            $existingProduct = Closeout::where('product_id', $request->product_id)->exists();
            if ($existingProduct) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cant_create_closeout'),
                ], 200);
            }
            Closeout::create([
                'product_id' => $request->product_id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.closeout_added'),

            ], 200);

        } catch (ValidationException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }

    }

    // archive a closeout
    public function archiveCloseout(Request $request)
    {
        try {
            $request->validate([
                'closeout_id' => 'required|integer|exists:closeouts,id',

            ]);

            $closeout = Closeout::findOrFail($request->closeout_id);
            $closeout->update(['status' => 'archived']);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.closeout_status_updated'),

            ], 200);

        } catch (ValidationException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }

    }

    private function getCloseouts($status)
    {
        try {

            $closeouts = Closeout::with(
                'product:id,nameAr,min_sale_price,stock',
                'product.viewImages:id,itemId,imageUrl',
                'product.normalImages:id,itemId,imageUrl'
            )
                ->where('status', $status)->get(['id', 'status', 'product_id']);

            $formatted = $closeouts->map(function ($closeout) {
                $image = $closeout->product->viewImages->first()
                    ?? $closeout->product->normalImages->first();

                return [
                    'closeout_id' => $closeout->id,
                    'closeout_status' => $closeout->status,
                    'product_id' => $closeout->product->id,
                    'product_name' => $closeout->product->nameAr,
                    'product_stock' => $closeout->product->stock,
                    'product_min_sale_price' => $closeout->product->min_sale_price,

                    'product_image' => $image ? $this->publicImagePath($image->imageUrl) : 'no image',
                ];
            });

            return response()->json([
                'status' => 'success',
                'closeoutes' => $formatted,
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function getUnArchivedCloseoutes()
    {
        return $this->getCloseouts('ongoing');
    }

    public function getArchivedCloseoutes()
    {
        return $this->getCloseouts('archived');
    }

    // *********************** COMBINATION SECTION *********************

    public function addCombination(Request $request)
    {
        try {
            $request->validate([
                'main_product_id' => 'required|integer|exists:products,id',
                'added_products' => 'required|array',
                'added_products.*.product_id' => ['required', 'integer', 'exists:products,id'],
                'added_products.*.quantity' => ['required', 'integer'],

            ]);

            $mainProduct = Product::findOrFail($request->main_product_id);

            // Step 1: Validate ALL sub products first
            foreach ($request->added_products as $addedProduct) {
                $subProduct = Product::findOrFail($addedProduct['product_id']);
                if ($subProduct->stock <= 0 || $subProduct->stock < $addedProduct['quantity']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.cant_sale'),
                    ], 200);
                }
            }
            foreach ($request->added_products as $addedProduct) {
                $subProduct = Product::findOrFail($addedProduct['product_id']);

                Combination::create([
                    'main_product_id' => $mainProduct->id,
                    'added_product_id' => $subProduct->id,
                    'quantity' => $addedProduct['quantity'],
                ]);
                $subProduct->stock -= $addedProduct['quantity'];
                $subProduct->save();
                if ($subProduct->stock === 0) {
                    $closeout = $subProduct->closeout;
                    if ($closeout) {
                        $closeout->status = 'archived';
                        $closeout->save();
                    }
                }

            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.combination_created'),
            ]);

        } catch (ValidationException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function getCombinations()
    {
        try {
            $productsWithCombinations =
             Product::whereIn('id', function ($query) {
                 $query->select('main_product_id')
                     ->from('combinations');
             })
                 ->with([
                     'normalImages:id,itemId,imageUrl',
                     'tags' => function ($q) {
                         $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                     },
                 ])
                 ->withCount('combinations')
                 ->get(['id', 'nameAr', 'stock', 'product_code']); // select only needed columns

            $formatted = $productsWithCombinations->map(function ($product) {
                $image = $product->normalImages->first();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_code' => $product->product_code,
                    'product_image' => $image ? $this->publicImagePath($image->imageUrl) : 'no image',

                    'number_of_used_products' => $product->combinations_count,
                    'tags' => $product->tags->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ])->values(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'combinations' => $formatted,
            ], 200);

        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // for editing product
    public function allSubCategories()
    {
        try {

            $subCategories = SubCategory::get(['id', 'nameAr', 'mainCategoryId']);

            return response()->json([
                'status' => 'success',
                'sub_categories' => $subCategories,
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // for editing product
    public function allProjects()
    {
        try {

            $projects = Project::get(['id', 'name']);

            return response()->json([
                'status' => 'success',
                'projects' => $projects,
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function allCategories()
    {
        try {

            $categories = Category::get(['id', 'nameAr']);

            return response()->json([
                'status' => 'success',
                'categories' => $categories,
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // for product search
    public function searchProduct(Request $request)
    {
        try {
            ini_set('max_execution_time', 2000); // 0 = unlimited

            $request->validate(['name' => 'required|string']);
            $search = $request->name;

            $products = Product::where('nameAr', 'like', "%{$search}%")
                ->with([
                    'viewImages:id,itemId,imageUrl',
                    'normalImages:id,itemId,imageUrl',
                    'tags' => function ($q) {
                        $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                    },
                ])
                ->get(['id', 'nameAr', 'stock', 'product_code', 'normailPrice']);

            $formatted = $products->map(function ($product) {
                $image = $product->viewImages->first()
                    ?? $product->normalImages->first();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_normail_price' => (float) ($product->normailPrice ?? 0),
                    'product_code' => $product->product_code,
                    'product_image' => $image ? $this->publicImagePath($image->imageUrl) : 'no image',
                    'tags' => $product->tags->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ])->values(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'products' => $formatted,
            ], 200);

        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function maxExc()
    {
        return response()->json([
            'max_execution_time' => ini_get('max_execution_time'),
        ]);
    }
}
