<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Closeout;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductAssemblyRecipe;
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
use App\Support\ProductImageResolver;
use App\Support\ProductSearchFilter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Stocks extends Controller
{
    private const PRODUCT_IMPORT_COLUMNS = [
        'product_id' => ['product_id', 'id', 'معرف المنتج', 'رقم المنتج'],
        'product_code' => ['product_code', 'code', 'كود المنتج'],
        'product_name' => ['product_name', 'name', 'اسم المنتج', 'اسم المنتح', 'اسم المنتج عربي'],
        'product_name_en' => ['product_name_en', 'name_en', 'nameEng', 'اسم المنتج إنجليزي'],
        'product_name_he' => ['product_name_he', 'name_he', 'nameAbree', 'اسم المنتج عبري'],
        'description_ar' => ['description_ar', 'descriptionAr', 'وصف عربي'],
        'description_en' => ['description_en', 'descriptionEng', 'وصف إنجليزي'],
        'description_he' => ['description_he', 'descriptionAbree', 'وصف عبري'],
        'retail_price' => ['retail_price', 'normail_price', 'normal_price', 'سعر المفرق', 'سعر المفرف'],
        'wholesale_price' => ['wholesale_price', 'سعر الجملة'],
        'cost_price' => ['cost_price', 'purchase_price', 'سعر التكلفة'],
        'price' => ['price', 'السعر'],
        'min_sale_price' => ['min_sale_price', 'أقل سعر بيع'],
        'quantity' => ['quantity', 'stock', 'العدد', 'المخزون'],
        'min_stock' => ['min_stock', 'الحد الأدنى للمخزون'],
        'discount' => ['discount', 'الخصم'],
        'is_show' => ['is_show', 'isShow', 'ظاهر بالمتجر'],
        'is_new_item' => ['is_new_item', 'isNewItem', 'منتج جديد'],
        'is_more_sales' => ['is_more_sales', 'isMoreSales', 'الأكثر مبيعاً'],
        'is_sold_with_paper' => ['is_sold_with_paper', 'بيع مع ورقة'],
        'rate' => ['rate', 'التقييم'],
        'manufacture_year' => ['manufacture_year', 'manufactureYear', 'سنة الصنع'],
        'model' => ['model', 'الموديل'],
        'rotation_date' => ['rotation_date', 'تاريخ الدوران'],
    ];

    private const SIZE_IMPORT_COLUMNS = [
        'product_id' => ['product_id', 'id', 'معرف المنتج', 'رقم المنتج'],
        'product_code' => ['product_code', 'code', 'كود المنتج'],
        'size' => ['size', 'المقاس'],
        'color_ar' => ['color_ar', 'colorAr', 'اللون عربي'],
        'color_en' => ['color_en', 'colorEn', 'اللون إنجليزي'],
        'color_abbr' => ['color_abbr', 'colorAbbr', 'اختصار اللون'],
        'retail_price' => ['retail_price', 'normail_price', 'normal_price', 'سعر المفرق'],
        'wholesale_price' => ['wholesale_price', 'سعر الجملة'],
        'discount' => ['discount', 'الخصم'],
        'quantity' => ['quantity', 'stock', 'الكمية', 'العدد'],
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
                ->with(['viewImages', 'normalImages', 'image3d', 'storeSection:id,name', 'purchasePrices' => fn ($q) => $q->latest('id'), 'tags' => function ($q) {
                    $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                }])
                ->select('id', 'nameAr', 'stock', 'product_code', 'category_id', 'store_section_id', 'created_at', 'updated_at');

            ProductSearchFilter::apply($query, $request->input('search'));

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

            if ($request->filled('store_section_id')) {
                $storeSectionFilter = $this->parseStoreSectionFilter($request->input('store_section_id'));
                if ($storeSectionFilter['include_none'] || $storeSectionFilter['ids'] !== []) {
                    $query->where(function ($q) use ($storeSectionFilter) {
                        if ($storeSectionFilter['ids'] !== []) {
                            $q->whereIn('store_section_id', $storeSectionFilter['ids']);
                        }
                        if ($storeSectionFilter['include_none']) {
                            $method = $storeSectionFilter['ids'] !== [] ? 'orWhereNull' : 'whereNull';
                            $q->{$method}('store_section_id');
                        }
                    });
                }
            }

            if ($this->isAdminRequest($request) && $request->filled('cost_price_status')) {
                $status = (string) $request->input('cost_price_status');
                if ($status === 'with') {
                    $query->whereHas('purchasePrices', fn ($q) => $q->where('price', '>', 0));
                } elseif ($status === 'without') {
                    $query->whereDoesntHave('purchasePrices', fn ($q) => $q->where('price', '>', 0));
                }
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

            $formatted = $products->getCollection()
                ->map(fn ($product) => $this->formatProductListItem($product, $this->isAdminRequest($request)));

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

    public function deletedProducts(Request $request)
    {
        try {
            ini_set('max_execution_time', 2000);

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

            $query = Product::onlyTrashed()
                ->with(['viewImages', 'normalImages', 'image3d', 'storeSection:id,name', 'purchasePrices' => fn ($q) => $q->latest('id'), 'tags' => function ($q) {
                    $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                }])
                ->select('id', 'nameAr', 'stock', 'product_code', 'category_id', 'store_section_id', 'created_at', 'updated_at', 'deleted_at');

            ProductSearchFilter::apply($query, $request->input('search'));

            $products = $query
                ->orderByDesc('deleted_at')
                ->paginate($perPage);

            $formatted = $products->getCollection()
                ->map(fn ($product) => $this->formatProductListItem($product, $this->isAdminRequest($request)));

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

    private function parseStoreSectionFilter($value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        $includeNone = false;

        foreach ($raw as $item) {
            $token = trim((string) $item);
            if ($token === '') {
                continue;
            }
            if (in_array($token, ['none', 'null', '0'], true)) {
                $includeNone = true;
                continue;
            }
            if (ctype_digit($token)) {
                $ids[] = (int) $token;
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'include_none' => $includeNone,
        ];
    }

    public function exportProductsCsv(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $fileName = 'doctor-bike-products-'.now()->format('Y-m-d-H-i').'.xlsx';

        return response()->streamDownload(function () use ($request) {
            @set_time_limit(300);
            @ini_set('memory_limit', '1024M');

            $temporaryImages = [];
            $thumbnailCache = [];
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Products');
            $sheet->setRightToLeft(true);
            $sizesSheet = $spreadsheet->createSheet();
            $sizesSheet->setTitle('Sizes & Colors');
            $sizesSheet->setRightToLeft(true);

            $headers = [
                'معرف المنتج',
                'كود المنتج',
                'اسم المنتج عربي',
                'صورة المنتج',
                'اسم المنتج إنجليزي',
                'اسم المنتج عبري',
                'وصف عربي',
                'وصف إنجليزي',
                'وصف عبري',
                'التصنيف الرئيسي',
                'التصنيفات الفرعية',
                'مكان التخزين',
                'سعر المفرق',
                'سعر الجملة',
                'سعر التكلفة',
                'السعر',
                'أقل سعر بيع',
                'العدد',
                'الحد الأدنى للمخزون',
                'الخصم',
                'ظاهر بالمتجر',
                'منتج جديد',
                'الأكثر مبيعاً',
                'بيع مع ورقة',
                'التقييم',
                'سنة الصنع',
                'الموديل',
                'تاريخ الدوران',
                'الشروة / المشروع',
            ];

            $sizeHeaders = [
                'معرف المنتج',
                'كود المنتج',
                'اسم المنتج',
                'المقاس',
                'اللون عربي',
                'اللون إنجليزي',
                'اختصار اللون',
                'صورة اللون',
                'سعر المفرق',
                'سعر الجملة',
                'الخصم',
                'الكمية',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $this->styleProductsExportSheet($sheet, count($headers));
            $sizesSheet->fromArray($sizeHeaders, null, 'A1');
            $this->styleSizesExportSheet($sizesSheet, count($sizeHeaders));

            $row = 2;
            $sizesRow = 2;
            $query = Product::query()
                ->with([
                    'category:id,nameAr',
                    'storeSection:id,name',
                    'subCategories.subCategory:id,nameAr,mainCategoryId',
                    'sizes' => fn ($q) => $q->select('id', 'size', 'itemId')->orderBy('id'),
                    'sizes.colorSizes' => fn ($q) => $q
                        ->select('id', 'sizeId', 'colorAr', 'colorEn', 'colorAbbr', 'normailPrice', 'wholesalePrice', 'discount', 'stock', 'image_url')
                        ->orderBy('id'),
                    'viewImages:id,itemId,imageUrl',
                    'normalImages:id,itemId,imageUrl',
                    'image3d:id,itemId,imageUrl',
                    'purchase:id,name',
                    'purchasePrices' => fn ($q) => $q->latest('id'),
                ])
                ->select([
                    'id',
                    'product_code',
                    'category_id',
                    'store_section_id',
                    'project_id',
                    'nameAr',
                    'nameEng',
                    'nameAbree',
                    'descriptionAr',
                    'descriptionEng',
                    'descriptionAbree',
                    'normailPrice',
                    'wholesalePrice',
                    'price',
                    'min_sale_price',
                    'stock',
                    'min_stock',
                    'discount',
                    'isShow',
                    'isNewItem',
                    'isMoreSales',
                    'is_sold_with_paper',
                    'rate',
                    'manufactureYear',
                    'model',
                    'rotation_date',
                ]);

            $this->applyProductExportFilters($query, $request);
            [$sortColumn, $sortDirection] = $this->productExportSort($request);

            $query
                ->orderBy($sortColumn, $sortDirection)
                ->chunk(100, function ($products) use ($sheet, $sizesSheet, &$row, &$sizesRow, &$temporaryImages, &$thumbnailCache) {
                    foreach ($products as $product) {
                        $sheet->fromArray([[
                            $product->id,
                            $product->product_code,
                            $product->nameAr,
                            '',
                            $product->nameEng,
                            $product->nameAbree,
                            $product->descriptionAr,
                            $product->descriptionEng,
                            $product->descriptionAbree,
                            $product->category?->nameAr,
                            $this->formatProductSubCategoriesForCsv($product),
                            $product->storeSection?->name,
                            $product->normailPrice,
                            $product->wholesalePrice,
                            optional($product->purchasePrices->first())->price ?? 0,
                            $product->price,
                            $product->min_sale_price,
                            $product->stock,
                            $product->min_stock,
                            $product->discount,
                            $this->formatCsvBoolean($product->isShow),
                            $this->formatCsvBoolean($product->isNewItem),
                            $this->formatCsvBoolean($product->isMoreSales),
                            $this->formatCsvBoolean($product->is_sold_with_paper),
                            $product->rate,
                            $product->manufactureYear,
                            $product->model,
                            $product->rotation_date,
                            $product->purchase?->name,
                        ]], null, 'A'.$row);

                        $this->addImageToSheet($sheet, $this->preferredProductImagePath($product), 'D'.$row, $temporaryImages, $thumbnailCache);
                        $sheet->getRowDimension($row)->setRowHeight(70);
                        $row++;

                        foreach ($product->sizes as $size) {
                            foreach ($size->colorSizes as $color) {
                                $sizesSheet->fromArray([[
                                    $product->id,
                                    $product->product_code,
                                    $product->nameAr,
                                    $size->size,
                                    $color->colorAr,
                                    $color->colorEn,
                                    $color->colorAbbr,
                                    '',
                                    $color->normailPrice,
                                    $color->wholesalePrice,
                                    $color->discount,
                                    $color->stock,
                                ]], null, 'A'.$sizesRow);

                                $this->addImageToSheet($sizesSheet, $this->localImagePathFromUrl($color->image_url ?? null), 'H'.$sizesRow, $temporaryImages, $thumbnailCache);
                                $sizesSheet->getRowDimension($sizesRow)->setRowHeight(70);
                                $sizesRow++;
                            }
                        }
                    }
                });

            $this->styleProductsExportBody($sheet, count($headers), $row - 1);
            $this->styleProductsExportBody($sizesSheet, count($sizeHeaders), $sizesRow - 1);

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();

            foreach ($temporaryImages as $temporaryImage) {
                if (is_string($temporaryImage) && is_file($temporaryImage)) {
                    @unlink($temporaryImage);
                }
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function styleProductsExportSheet(Worksheet $sheet, int $columnsCount): void
    {
        $highestColumn = Coordinate::stringFromColumnIndex($columnsCount);
        $headerRange = 'A1:'.$highestColumn.'1';

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($headerRange);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 13,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '245A86'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9E2EC'],
                ],
            ],
        ]);

        $sheet->getStyle('A:'.$highestColumn)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $widths = [
            'A' => 12,
            'B' => 14,
            'C' => 28,
            'D' => 16,
            'E' => 28,
            'F' => 28,
            'G' => 42,
            'H' => 42,
            'I' => 42,
            'J' => 24,
            'K' => 30,
            'L' => 22,
            'M' => 14,
            'N' => 14,
            'O' => 14,
            'P' => 14,
            'Q' => 14,
            'R' => 12,
            'S' => 18,
            'T' => 12,
            'U' => 14,
            'V' => 14,
            'W' => 16,
            'X' => 14,
            'Y' => 12,
            'Z' => 14,
            'AA' => 18,
            'AB' => 18,
            'AC' => 24,
            'AD' => 72,
            'AE' => 16,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function styleSizesExportSheet(Worksheet $sheet, int $columnsCount): void
    {
        $highestColumn = Coordinate::stringFromColumnIndex($columnsCount);
        $headerRange = 'A1:'.$highestColumn.'1';

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($headerRange);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 13,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '497A45'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9E2EC'],
                ],
            ],
        ]);

        $sheet->getStyle('A:'.$highestColumn)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $widths = [
            'A' => 12,
            'B' => 14,
            'C' => 28,
            'D' => 14,
            'E' => 18,
            'F' => 18,
            'G' => 16,
            'H' => 16,
            'I' => 14,
            'J' => 14,
            'K' => 12,
            'L' => 12,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function applyProductExportFilters($query, Request $request): void
    {
        ProductSearchFilter::apply($query, $request->input('search'));

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        $subCategoryId = $request->input('sub_category_id', $request->input('subcategory_id'));
        if ($subCategoryId !== null && $subCategoryId !== '') {
            $query->whereHas('subCategories', function ($q) use ($subCategoryId) {
                $q->where('sub_category_id', (int) $subCategoryId);
            });
        }

        if ($request->filled('store_section_id')) {
            $storeSectionFilter = $this->parseStoreSectionFilter($request->input('store_section_id'));
            if ($storeSectionFilter['include_none'] || $storeSectionFilter['ids'] !== []) {
                $query->where(function ($q) use ($storeSectionFilter) {
                    if ($storeSectionFilter['ids'] !== []) {
                        $q->whereIn('store_section_id', $storeSectionFilter['ids']);
                    }
                    if ($storeSectionFilter['include_none']) {
                        $method = $storeSectionFilter['ids'] !== [] ? 'orWhereNull' : 'whereNull';
                        $q->{$method}('store_section_id');
                    }
                });
            }
        }

        if ($this->isAdminRequest($request) && $request->filled('cost_price_status')) {
            $status = (string) $request->input('cost_price_status');
            if ($status === 'with') {
                $query->whereHas('purchasePrices', fn ($q) => $q->where('price', '>', 0));
            } elseif ($status === 'without') {
                $query->whereDoesntHave('purchasePrices', fn ($q) => $q->where('price', '>', 0));
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
    }

    private function productExportSort(Request $request): array
    {
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $sortColumn = match ($sortBy) {
            'name' => 'nameAr',
            'updated_at' => 'updated_at',
            default => 'created_at',
        };

        return [$sortColumn, $sortDirection];
    }

    private function styleProductsExportBody(Worksheet $sheet, int $columnsCount, int $lastRow): void
    {
        if ($lastRow < 2) {
            return;
        }

        $highestColumn = Coordinate::stringFromColumnIndex($columnsCount);
        $bodyRange = 'A2:'.$highestColumn.$lastRow;

        $sheet->getStyle($bodyRange)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E6EEF5'],
                ],
            ],
        ]);
    }

    private function formatCsvBoolean($value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'نعم' : 'لا';
    }

    private function formatProductSubCategoriesForCsv(Product $product): string
    {
        return $product->subCategories
            ->map(fn ($pivot) => $pivot->subCategory?->nameAr)
            ->filter()
            ->unique()
            ->values()
            ->implode(' | ');
    }

    private function preferredProductImagePath(Product $product): ?string
    {
        foreach ([$product->viewImages, $product->normalImages, $product->image3d] as $images) {
            foreach ($images as $image) {
                $url = ProductImageResolver::urlFromRecord($image);
                if (! ProductImageResolver::isValidUrl($url)) {
                    continue;
                }

                $path = $this->localImagePathFromUrl($url);
                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function preferredSizeImagePath(Product $product): ?string
    {
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $color) {
                $path = $this->localImagePathFromUrl($color->image_url ?? null);
                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function localImagePathFromUrl(?string $url): ?string
    {
        if (! ProductImageResolver::isValidUrl($url)) {
            return null;
        }

        $normalized = ApiImageUrl::normalize($url);
        $path = $normalized;
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            $path = (string) parse_url($normalized, PHP_URL_PATH);
        }

        $relative = ltrim(rawurldecode(str_replace('\\', '/', $path)), '/');
        $withoutPublic = preg_replace('#^public/#', '', $relative) ?? $relative;

        $candidates = array_unique([
            public_path($relative),
            public_path($withoutPublic),
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && @getimagesize($candidate) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $temporaryImages
     * @param  array<string, string|null>  $thumbnailCache
     */
    private function addImageToSheet(Worksheet $sheet, ?string $path, string $cell, array &$temporaryImages, array &$thumbnailCache): void
    {
        if ($path === null) {
            return;
        }

        $thumbnail = $this->thumbnailForExcel($path, $temporaryImages, $thumbnailCache);
        if ($thumbnail === null) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setPath($thumbnail);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(6);
        $drawing->setHeight(64);
        $drawing->setWorksheet($sheet);
    }

    /**
     * @param  array<int, string>  $temporaryImages
     * @param  array<string, string|null>  $thumbnailCache
     */
    private function thumbnailForExcel(string $path, array &$temporaryImages, array &$thumbnailCache): ?string
    {
        if (array_key_exists($path, $thumbnailCache)) {
            return $thumbnailCache[$path];
        }

        $source = @imagecreatefromstring((string) @file_get_contents($path));
        if ($source === false) {
            return $thumbnailCache[$path] = null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($source);

            return $thumbnailCache[$path] = null;
        }

        $maxSize = 96;
        $scale = min($maxSize / $sourceWidth, $maxSize / $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $temp = tempnam(sys_get_temp_dir(), 'products-export-image-');
        if ($temp === false) {
            imagedestroy($source);
            imagedestroy($thumb);

            return $thumbnailCache[$path] = null;
        }

        $tempJpg = $temp.'.jpg';
        @unlink($temp);
        $saved = imagejpeg($thumb, $tempJpg, 70);
        imagedestroy($source);
        imagedestroy($thumb);

        if (! $saved) {
            @unlink($tempJpg);

            return $thumbnailCache[$path] = null;
        }

        $temporaryImages[] = $tempJpg;

        return $thumbnailCache[$path] = $tempJpg;
    }

    private function formatProductSizesForCsv(Product $product): string
    {
        $rows = [];

        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $color) {
                $parts = [
                    trim((string) $size->size) !== '' ? (string) $size->size : '-',
                    trim((string) $color->colorAr) !== '' ? (string) $color->colorAr : '-',
                    trim((string) $color->colorEn) !== '' ? (string) $color->colorEn : '-',
                    trim((string) $color->colorAbbr) !== '' ? (string) $color->colorAbbr : '-',
                    'مفرق: '.($color->normailPrice ?? 0),
                    'جملة: '.($color->wholesalePrice ?? 0),
                    'خصم: '.($color->discount ?? 0),
                    'كمية: '.($color->stock ?? 0),
                ];

                if (! empty($color->image_url)) {
                    $parts[] = 'صورة: '.$this->publicImagePath($color->image_url);
                }

                $rows[] = implode(' / ', $parts);
            }
        }

        return implode("\n", $rows);
    }

    public function previewProductsCsvImport(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ]);

        $result = $this->readProductsImport($request, false);

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
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ]);

        $result = $this->readProductsImport($request, true);

        return response()->json([
            'status' => 'success',
            'message' => "تم استيراد {$result['updated']} منتج",
            'updated' => $result['updated'],
            'errors' => $result['errors'],
        ]);
    }

    private function readProductsImport(Request $request, bool $apply): array
    {
        $extension = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->readProductsXlsxImport($request, $apply);
        }

        return $this->readProductsCsvImport($request, $apply);
    }

    private function readProductsXlsxImport(Request $request, bool $apply): array
    {
        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $productsSheet = $spreadsheet->getSheetByName('Products') ?? $spreadsheet->getSheet(0);
        $rows = $productsSheet->toArray(null, true, true, false);

        if ($rows === [] || $this->isEmptyCsvRow($rows[0] ?? [])) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages(['file' => 'ملف المنتجات فارغ']);
        }

        $columns = $this->resolveProductImportColumns($rows[0]);
        if (! array_key_exists('product_id', $columns) && ! array_key_exists('product_code', $columns)) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages(['file' => 'عمود معرف المنتج أو كود المنتج مطلوب للاستيراد']);
        }

        $updated = 0;
        $errors = [];
        $changes = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;
            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $result = $this->processProductImportRow($row, $columns, $rowNumber, $apply);
            if ($result['error'] !== null) {
                $errors[] = $result['error'];
                continue;
            }
            if ($result['change'] !== null) {
                $changes[] = $result['change'];
                if ($apply) {
                    $updated++;
                }
            }
        }

        $sizesSheet = $spreadsheet->getSheetByName('Sizes & Colors');
        if ($sizesSheet !== null) {
            $sizesResult = $this->readSizesXlsxImport($sizesSheet, $apply);
            $updated += $sizesResult['updated'];
            $changes = array_merge($changes, $sizesResult['changes']);
            $errors = array_merge($errors, $sizesResult['errors']);
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'updated' => $updated,
            'changes' => $changes,
            'errors' => $errors,
        ];
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
            $isNewProduct = $productId === '';

            if (! $isNewProduct && ! ctype_digit($productId)) {
                $errors[] = "السطر {$rowNumber}: معرف المنتج غير صحيح";
                continue;
            }

            $product = $isNewProduct
                ? null
                : Product::with(['purchasePrices' => fn ($q) => $q->latest('id')])->find($productId);

            if (! $isNewProduct && ! $product) {
                $errors[] = "السطر {$rowNumber}: المنتج غير موجود";
                continue;
            }

            $updates = [];
            $rowChanges = [
                'row' => $rowNumber,
                'operation' => $isNewProduct ? 'create' : 'update',
                'product_id' => $product?->id,
                'product_name' => $product?->nameAr,
                'fields' => [],
            ];

            $name = $this->csvValue($row, $columns, 'product_name');
            if ($isNewProduct && ($name === null || $name === '')) {
                $errors[] = "السطر {$rowNumber}: اسم المنتج مطلوب للمنتج الجديد";
                continue;
            }
            if ($name !== null && mb_strlen($name) > 255) {
                $errors[] = "السطر {$rowNumber}: اسم المنتج طويل جداً";
                continue;
            }
            if ($name !== null && $name !== '') {
                $updates['nameAr'] = $name;
                $this->addProductImportChange($rowChanges, 'اسم المنتج', $product?->nameAr ?? '', $name);
                if ($isNewProduct) {
                    $rowChanges['product_name'] = $name;
                }
            }

            foreach ([
                'retail_price' => ['field' => 'normailPrice', 'label' => 'سعر المفرق'],
                'wholesale_price' => ['field' => 'wholesalePrice', 'label' => 'سعر الجملة'],
            ] as $csvKey => $config) {
                $value = $this->csvValue($row, $columns, $csvKey);
                if ($value === null || $value === '') {
                    if ($isNewProduct) {
                        $errors[] = "السطر {$rowNumber}: {$config['label']} مطلوب للمنتج الجديد";
                        continue 2;
                    }
                    continue;
                }
                $number = $this->normalizeImportNumber($value);
                if ($number === null || $number < 0) {
                    $errors[] = "السطر {$rowNumber}: {$config['label']} غير صحيح";
                    continue 2;
                }
                $field = $config['field'];
                $updates[$field] = $number;
                $this->addProductImportChange($rowChanges, $config['label'], $product?->{$field} ?? 0, $number);
            }

            $quantity = $this->csvValue($row, $columns, 'quantity');
            if ($quantity !== null && $quantity !== '') {
                $quantityNumber = $this->normalizeImportNumber($quantity);
                if ($quantityNumber === null || $quantityNumber < 0 || floor($quantityNumber) != $quantityNumber) {
                    $errors[] = "السطر {$rowNumber}: العدد غير صحيح";
                    continue;
                }
                $updates['stock'] = (int) $quantityNumber;
                $this->addProductImportChange($rowChanges, 'العدد', $product?->stock ?? 0, (int) $quantityNumber);
            } elseif ($isNewProduct) {
                $errors[] = "السطر {$rowNumber}: العدد مطلوب للمنتج الجديد";
                continue;
            }

            $cost = $this->csvValue($row, $columns, 'cost_price');
            $costNumber = null;
            if ($cost !== null && $cost !== '') {
                $costNumber = $this->normalizeImportNumber($cost);
                if ($costNumber === null || $costNumber < 0) {
                    $errors[] = "السطر {$rowNumber}: سعر التكلفة غير صحيح";
                    continue;
                }

                $purchasePrice = $product?->purchasePrices->first();
                $oldCost = $purchasePrice?->price ?? 0;
                $this->addProductImportChange($rowChanges, 'سعر التكلفة', $oldCost, $costNumber);
            } elseif ($isNewProduct) {
                $errors[] = "السطر {$rowNumber}: سعر التكلفة مطلوب للمنتج الجديد";
                continue;
            }

            if ($apply && $rowChanges['fields'] !== []) {
                if ($isNewProduct) {
                    $product = $this->createImportedProduct($updates, $costNumber);
                    $rowChanges['product_id'] = $product->id;
                } else {
                    if ($updates !== []) {
                        $product->update($updates);
                    }

                    if ($costNumber !== null) {
                        $purchasePrice = $product->purchasePrices->first();
                        if ($purchasePrice) {
                            $purchasePrice->update(['price' => $costNumber]);
                        } else {
                            PurchaseProduct::create([
                                'product_id' => $product->id,
                                'seller_id' => null,
                                'price' => $costNumber,
                            ]);
                        }
                    }
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

    private function processProductImportRow(array $row, array $columns, int $rowNumber, bool $apply): array
    {
        $productId = trim((string) ($row[$columns['product_id'] ?? -1] ?? ''));
        $productCode = $this->csvValue($row, $columns, 'product_code');
        $isNewProduct = $productId === '' && ($productCode === null || $productCode === '');

        if ($productId !== '' && ! ctype_digit($productId)) {
            return ['error' => "السطر {$rowNumber}: معرف المنتج غير صحيح", 'change' => null];
        }

        $product = null;
        if ($productId !== '') {
            $product = Product::with(['purchasePrices' => fn ($q) => $q->latest('id')])->find($productId);
        } elseif ($productCode !== null && $productCode !== '') {
            $product = Product::with(['purchasePrices' => fn ($q) => $q->latest('id')])
                ->where('product_code', $productCode)
                ->first();
        }

        if (! $isNewProduct && ! $product) {
            return ['error' => "السطر {$rowNumber}: المنتج غير موجود", 'change' => null];
        }

        $updates = [];
        $rowChanges = [
            'row' => $rowNumber,
            'operation' => $isNewProduct ? 'create' : 'update',
            'product_id' => $product?->id,
            'product_name' => $product?->nameAr,
            'fields' => [],
        ];

        $stringFields = [
            'product_name' => ['field' => 'nameAr', 'label' => 'اسم المنتج'],
            'product_name_en' => ['field' => 'nameEng', 'label' => 'اسم المنتج إنجليزي'],
            'product_name_he' => ['field' => 'nameAbree', 'label' => 'اسم المنتج عبري'],
            'description_ar' => ['field' => 'descriptionAr', 'label' => 'وصف عربي'],
            'description_en' => ['field' => 'descriptionEng', 'label' => 'وصف إنجليزي'],
            'description_he' => ['field' => 'descriptionAbree', 'label' => 'وصف عبري'],
            'model' => ['field' => 'model', 'label' => 'الموديل'],
            'rotation_date' => ['field' => 'rotation_date', 'label' => 'تاريخ الدوران'],
        ];

        foreach ($stringFields as $key => $config) {
            $value = $this->csvValue($row, $columns, $key);
            if ($key === 'product_name' && $isNewProduct && ($value === null || $value === '')) {
                return ['error' => "السطر {$rowNumber}: اسم المنتج مطلوب للمنتج الجديد", 'change' => null];
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($key, ['product_name', 'product_name_en', 'product_name_he', 'model'], true) && mb_strlen($value) > 500) {
                return ['error' => "السطر {$rowNumber}: {$config['label']} طويل جداً", 'change' => null];
            }
            $field = $config['field'];
            $updates[$field] = $value;
            $this->addProductImportChange($rowChanges, $config['label'], $product?->{$field} ?? '', $value);
            if ($key === 'product_name' && $isNewProduct) {
                $rowChanges['product_name'] = $value;
            }
        }

        $numberFields = [
            'retail_price' => ['field' => 'normailPrice', 'label' => 'سعر المفرق', 'required' => true],
            'wholesale_price' => ['field' => 'wholesalePrice', 'label' => 'سعر الجملة', 'required' => true],
            'price' => ['field' => 'price', 'label' => 'السعر', 'required' => false],
            'min_sale_price' => ['field' => 'min_sale_price', 'label' => 'أقل سعر بيع', 'required' => false],
            'quantity' => ['field' => 'stock', 'label' => 'العدد', 'required' => true, 'integer' => true],
            'min_stock' => ['field' => 'min_stock', 'label' => 'الحد الأدنى للمخزون', 'required' => false],
            'discount' => ['field' => 'discount', 'label' => 'الخصم', 'required' => false],
            'rate' => ['field' => 'rate', 'label' => 'التقييم', 'required' => false],
            'manufacture_year' => ['field' => 'manufactureYear', 'label' => 'سنة الصنع', 'required' => false, 'integer' => true],
        ];

        foreach ($numberFields as $key => $config) {
            $value = $this->csvValue($row, $columns, $key);
            if (($value === null || $value === '') && ($config['required'] ?? false) && $isNewProduct) {
                return ['error' => "السطر {$rowNumber}: {$config['label']} مطلوب للمنتج الجديد", 'change' => null];
            }
            if ($value === null || $value === '') {
                continue;
            }
            $number = $this->normalizeImportNumber($value);
            if ($number === null || $number < 0 || (($config['integer'] ?? false) && floor($number) != $number)) {
                return ['error' => "السطر {$rowNumber}: {$config['label']} غير صحيح", 'change' => null];
            }
            $field = $config['field'];
            $updates[$field] = ($config['integer'] ?? false) ? (int) $number : $number;
            $this->addProductImportChange($rowChanges, $config['label'], $product?->{$field} ?? 0, $updates[$field]);
        }

        foreach ([
            'is_show' => ['field' => 'isShow', 'label' => 'ظاهر بالمتجر'],
            'is_new_item' => ['field' => 'isNewItem', 'label' => 'منتج جديد'],
            'is_more_sales' => ['field' => 'isMoreSales', 'label' => 'الأكثر مبيعاً'],
            'is_sold_with_paper' => ['field' => 'is_sold_with_paper', 'label' => 'بيع مع ورقة'],
        ] as $key => $config) {
            $value = $this->csvValue($row, $columns, $key);
            if ($value === null || $value === '') {
                continue;
            }
            $bool = $this->normalizeImportBoolean($value);
            if ($bool === null) {
                return ['error' => "السطر {$rowNumber}: {$config['label']} غير صحيح", 'change' => null];
            }
            $field = $config['field'];
            $updates[$field] = $bool ? 1 : 0;
            $this->addProductImportChange($rowChanges, $config['label'], $this->formatCsvBoolean($product?->{$field}), $this->formatCsvBoolean($bool));
        }

        $cost = $this->csvValue($row, $columns, 'cost_price');
        $costNumber = null;
        if ($cost !== null && $cost !== '') {
            $costNumber = $this->normalizeImportNumber($cost);
            if ($costNumber === null || $costNumber < 0) {
                return ['error' => "السطر {$rowNumber}: سعر التكلفة غير صحيح", 'change' => null];
            }
            $this->addProductImportChange($rowChanges, 'سعر التكلفة', $product?->purchasePrices->first()?->price ?? 0, $costNumber);
        } elseif ($isNewProduct) {
            return ['error' => "السطر {$rowNumber}: سعر التكلفة مطلوب للمنتج الجديد", 'change' => null];
        }

        if ($apply && $rowChanges['fields'] !== []) {
            if ($isNewProduct) {
                $product = $this->createImportedProduct($updates, $costNumber);
                $rowChanges['product_id'] = $product->id;
            } else {
                if ($updates !== []) {
                    $product->update($updates);
                }
                if ($costNumber !== null) {
                    $purchasePrice = $product->purchasePrices->first();
                    if ($purchasePrice) {
                        $purchasePrice->update(['price' => $costNumber]);
                    } else {
                        PurchaseProduct::create([
                            'product_id' => $product->id,
                            'seller_id' => null,
                            'price' => $costNumber,
                        ]);
                    }
                }
            }
        }

        return [
            'error' => null,
            'change' => $rowChanges['fields'] !== [] ? $rowChanges : null,
        ];
    }

    private function readSizesXlsxImport(Worksheet $sheet, bool $apply): array
    {
        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === [] || $this->isEmptyCsvRow($rows[0] ?? [])) {
            return ['updated' => 0, 'changes' => [], 'errors' => []];
        }

        $columns = $this->resolveImportColumns($rows[0], self::SIZE_IMPORT_COLUMNS);
        if (! array_key_exists('size', $columns) || (! array_key_exists('product_id', $columns) && ! array_key_exists('product_code', $columns))) {
            return ['updated' => 0, 'changes' => [], 'errors' => ['Sheet المقاسات: عمود المنتج وعمود المقاس مطلوبان']];
        }

        $updated = 0;
        $changes = [];
        $errors = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;
            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $product = $this->findImportProduct($row, $columns);
            if (! $product) {
                $errors[] = "Sheet المقاسات - السطر {$rowNumber}: المنتج غير موجود";
                continue;
            }

            $sizeName = $this->csvValue($row, $columns, 'size');
            if ($sizeName === null || $sizeName === '') {
                $errors[] = "Sheet المقاسات - السطر {$rowNumber}: المقاس مطلوب";
                continue;
            }

            $colorAr = $this->csvValue($row, $columns, 'color_ar') ?? '';
            $colorEn = $this->csvValue($row, $columns, 'color_en') ?? '';
            $colorAbbr = $this->csvValue($row, $columns, 'color_abbr') ?? '';

            $numbers = [];
            foreach ([
                'retail_price' => 'سعر المفرق',
                'wholesale_price' => 'سعر الجملة',
                'discount' => 'الخصم',
                'quantity' => 'الكمية',
            ] as $key => $label) {
                $value = $this->csvValue($row, $columns, $key);
                if ($value === null || $value === '') {
                    $numbers[$key] = 0;
                    continue;
                }
                $number = $this->normalizeImportNumber($value);
                if ($number === null || $number < 0 || ($key === 'quantity' && floor($number) != $number)) {
                    $errors[] = "Sheet المقاسات - السطر {$rowNumber}: {$label} غير صحيح";
                    continue 2;
                }
                $numbers[$key] = $key === 'quantity' ? (int) $number : $number;
            }

            $change = [
                'row' => $rowNumber,
                'operation' => 'size_color',
                'product_id' => $product->id,
                'product_name' => $product->nameAr,
                'fields' => [[
                    'label' => 'مقاس/لون',
                    'old' => '',
                    'new' => "{$sizeName} / {$colorAr} / {$colorEn}",
                ]],
            ];

            if ($apply) {
                $size = Size::query()->firstOrCreate([
                    'itemId' => $product->id,
                    'size' => $sizeName,
                ], [
                    'discount' => 0,
                    'description' => '',
                ]);
                if (empty($size->id)) {
                    $size->id = DB::getPdo()->lastInsertId();
                }

                SizeColor::query()->updateOrCreate([
                    'sizeId' => $size->id,
                    'colorAr' => $colorAr,
                    'colorEn' => $colorEn,
                    'colorAbbr' => $colorAbbr,
                ], [
                    'normailPrice' => $numbers['retail_price'],
                    'wholesalePrice' => $numbers['wholesale_price'],
                    'discount' => $numbers['discount'],
                    'stock' => $numbers['quantity'],
                ]);
                $updated++;
            }

            $changes[] = $change;
        }

        return ['updated' => $updated, 'changes' => $changes, 'errors' => $errors];
    }

    private function createImportedProduct(array $fields, ?float $cost): Product
    {
        return DB::transaction(function () use ($fields, $cost) {
            $newId = (int) (Product::query()->lockForUpdate()->max('id') ?? 0) + 1;

            $product = Product::query()->create([
                'id' => $newId,
                'nameAr' => $fields['nameAr'],
                'nameEng' => $fields['nameEng'] ?? $fields['nameAr'],
                'nameAbree' => $fields['nameAbree'] ?? $fields['nameAr'],
                'descriptionAr' => $fields['descriptionAr'] ?? '',
                'descriptionEng' => $fields['descriptionEng'] ?? ($fields['descriptionAr'] ?? ''),
                'descriptionAbree' => $fields['descriptionAbree'] ?? ($fields['descriptionAr'] ?? ''),
                'normailPrice' => $fields['normailPrice'],
                'wholesalePrice' => $fields['wholesalePrice'],
                'stock' => $fields['stock'],
                'price' => $fields['price'] ?? null,
                'min_sale_price' => $fields['min_sale_price'] ?? null,
                'discount' => $fields['discount'] ?? 0,
                'isShow' => $fields['isShow'] ?? true,
                'isNewItem' => $fields['isNewItem'] ?? true,
                'isMoreSales' => $fields['isMoreSales'] ?? false,
                'is_sold_with_paper' => $fields['is_sold_with_paper'] ?? 0,
                'rate' => $fields['rate'] ?? 4,
                'manufactureYear' => $fields['manufactureYear'] ?? 0,
                'model' => $fields['model'] ?? '',
                'rotation_date' => $fields['rotation_date'] ?? null,
                'min_stock' => $fields['min_stock'] ?? 0,
            ]);

            if ($cost !== null) {
                PurchaseProduct::create([
                    'product_id' => $product->id,
                    'seller_id' => null,
                    'price' => $cost,
                ]);
            }

            return $product;
        });
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
        return $this->resolveImportColumns($header, self::PRODUCT_IMPORT_COLUMNS);
    }

    private function resolveImportColumns(array $header, array $definitions): array
    {
        $normalized = [];
        foreach ($header as $index => $value) {
            $normalized[$index] = $this->normalizeCsvHeader((string) $value);
        }

        $columns = [];
        foreach ($definitions as $key => $aliases) {
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

    private function findImportProduct(array $row, array $columns): ?Product
    {
        $productId = $this->csvValue($row, $columns, 'product_id');
        if ($productId !== null && $productId !== '' && ctype_digit($productId)) {
            return Product::query()->find((int) $productId);
        }

        $productCode = $this->csvValue($row, $columns, 'product_code');
        if ($productCode !== null && $productCode !== '') {
            return Product::query()->where('product_code', $productCode)->first();
        }

        return null;
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

    private function normalizeImportNumber(string $value): ?float
    {
        $value = trim(str_replace([' ', '٬'], ['', ''], $value));
        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeImportBoolean(string $value): ?bool
    {
        $normalized = mb_strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'نعم'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'لا'], true)) {
            return false;
        }

        return null;
    }

    private function csvValue(array $row, array $columns, string $key): ?string
    {
        if (! array_key_exists($key, $columns)) {
            return null;
        }

        return trim((string) ($row[$columns[$key]] ?? ''));
    }

    private function formatProductListItem(Product $product, bool $includeCostPrice = false): array
    {
        $images = \App\Support\ProductImageResolver::formatForList($product);
        $costPrice = optional($product->purchasePrices->first())->price;

        $row = [
            'product_id' => $product->id,
            'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
            'product_name' => $product->nameAr,
            'product_stock' => $product->stock,
            'product_code' => $product->product_code,
            'product_image' => $images['product_image'],
            'product_viewImages' => $images['product_viewImages'],
            'product_normalImages' => $images['product_normalImages'],
            'product_image3d' => $images['product_image3d'],
            'tags' => $product->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])->values(),
            'store_section_id' => $product->store_section_id !== null ? (int) $product->store_section_id : null,
            'store_section_name' => $product->storeSection?->name,
        ];

        if ($includeCostPrice) {
            $row['cost_price'] = $costPrice !== null ? (float) $costPrice : null;
            $row['has_cost_price'] = $costPrice !== null && (float) $costPrice > 0;
        }

        return $row;
    }

    public function updateProductCostPrice(Request $request)
    {
        if (! $this->isAdminRequest($request)) {
            return response()->json(['status' => 'error', 'message' => 'غير مصرح'], 403);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $price = (float) ($data['cost_price'] ?? 0);

        $row = PurchaseProduct::query()
            ->where('product_id', $data['product_id'])
            ->orderByDesc('id')
            ->first();

        if ($row !== null) {
            $row->update(['price' => $price]);
        } else {
            PurchaseProduct::create([
                'product_id' => $data['product_id'],
                'seller_id' => null,
                'price' => $price,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث سعر التكلفة',
            'product_id' => (int) $data['product_id'],
            'cost_price' => $price,
            'has_cost_price' => $price > 0,
        ], 200);
    }

    public function showProduct(Request $request)
    {
        try {

            $request->validate(['product_id' => 'required|integer|exists:products,id']);
            $product = Product::with([
                'category:id,nameAr',
                'storeSection:id,name',
                'subCategories.subCategory:id,nameAr,mainCategoryId',
                'subCategories.subCategory.category:id,nameAr',
                'sizes' => function ($q) {
                    $q->select('id', 'size', 'itemId');
                },
                'sizes.colorSizes' => function ($q) {
                    $q->select('id', 'colorAr', 'colorEn', 'colorAbbr', 'normailPrice', 'wholesalePrice', 'discount', 'stock', 'sizeId', 'image_url');
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

            $product['store_section_id'] = $product->store_section_id !== null
                ? (int) $product->store_section_id
                : null;
            $product['store_section_name'] = $product->storeSection?->name;
            $product->unsetRelation('storeSection');

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
                    'sub_category_name' => $pivot->subCategory?->nameAr,
                    'main_category_id' => $pivot->subCategory?->category?->id,
                    'main_category_name' => $pivot->subCategory?->category?->nameAr,

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

            $canViewCostPrice = $request->user()?->canViewCostPrice() ?? false;
            $product['can_view_cost_price'] = $canViewCostPrice;

            if ($canViewCostPrice) {
                $purchase_prices = $product->purchasePrices->map(function ($pivot) {
                    return [
                        'seller_id' => $pivot->seller_id,
                        'seller_name' => $pivot->seller?->name,
                        'price' => $pivot->price,

                    ];
                });
                $product['purchase_prices'] = $purchase_prices;
            } else {
                $product['purchase_prices'] = [];
            }

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
     * خيارات حجم المنتج: قائمة الإعدادات (app_settings) فقط.
     * عند التعديل يُضاف أحجام هذا المنتج الحالية إن لم تكن في القائمة (لعدم فقدان قيم قديمة).
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

            $merged = collect($this->configuredSizeOptionPresets())
                ->filter(fn ($s) => $s !== null && $s !== '');

            foreach ($product->sizes as $s) {
                $label = trim((string) ($s->size ?? ''));
                if ($label !== '' && ! $merged->contains($label)) {
                    $merged->push($label);
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

    public function deleteProducts(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_ids' => ['required', 'array', 'min:1'],
                'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            ]);

            $ids = array_values(array_unique(array_map('intval', $validated['product_ids'])));

            $deleted = DB::transaction(function () use ($ids) {
                Closeout::whereIn('product_id', $ids)
                    ->where('status', 'unarchived')
                    ->update(['status' => 'archived']);

                return Product::whereIn('id', $ids)->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.products_deleted'),
                'deleted' => $deleted,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.delete_data_error'),
            ], 500);
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
                'product.normalImages:id,itemId,imageUrl',
                'product.image3d:id,itemId,imageUrl',
            )
                ->where('status', $status)->get(['id', 'status', 'product_id']);

            $formatted = $closeouts->map(function ($closeout) {
                $images = \App\Support\ProductImageResolver::formatForList($closeout->product);

                return [
                    'closeout_id' => $closeout->id,
                    'closeout_status' => $closeout->status,
                    'product_id' => $closeout->product->id,
                    'product_name' => $closeout->product->nameAr,
                    'product_stock' => $closeout->product->stock,
                    'product_min_sale_price' => $closeout->product->min_sale_price,
                    'product_image' => $images['product_image'],
                    'product_viewImages' => $images['product_viewImages'],
                    'product_normalImages' => $images['product_normalImages'],
                    'product_image3d' => $images['product_image3d'],
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
                     'viewImages:id,itemId,imageUrl',
                     'normalImages:id,itemId,imageUrl',
                     'image3d:id,itemId,imageUrl',
                     'tags' => function ($q) {
                         $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                     },
                 ])
                 ->withCount('combinations')
                 ->get(['id', 'nameAr', 'stock', 'product_code']); // select only needed columns

            $formatted = $productsWithCombinations->map(function ($product) {
                $images = \App\Support\ProductImageResolver::formatForList($product);

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_code' => $product->product_code,
                    'product_image' => $images['product_image'],
                    'product_viewImages' => $images['product_viewImages'],
                    'product_normalImages' => $images['product_normalImages'],
                    'product_image3d' => $images['product_image3d'],
                    'number_of_used_products' => $product->combinations_count,
                    'tags' => $product->tags->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ])->values(),
                ];
            });

            if (Schema::hasTable('product_assembly_recipes')) {
                $latestAssemblyRecipes = ProductAssemblyRecipe::query()
                    ->with([
                        'targetProduct.viewImages:id,itemId,imageUrl',
                        'targetProduct.normalImages:id,itemId,imageUrl',
                        'targetProduct.image3d:id,itemId,imageUrl',
                        'targetProduct.tags' => function ($q) {
                            $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                        },
                        'targetSizeColor.size:id,size',
                    ])
                    ->withCount('items')
                    ->where('is_active', true)
                    ->latest()
                    ->get()
                    ->unique('target_product_id')
                    ->values();

                $assemblyFormatted = $latestAssemblyRecipes
                    ->filter(fn ($recipe) => $recipe->targetProduct instanceof Product)
                    ->map(function (ProductAssemblyRecipe $recipe) {
                        $product = $recipe->targetProduct;
                        $images = \App\Support\ProductImageResolver::formatForList($product);
                        $size = $recipe->targetSizeColor?->size?->size;
                        $color = $recipe->targetSizeColor?->colorAr;
                        $variantLabel = collect([$size, $color])
                            ->filter(fn ($value) => filled($value))
                            ->implode(' / ');

                        return [
                            'product_id' => $product->id,
                            'product_name' => $variantLabel
                                ? $product->nameAr.' - '.$variantLabel
                                : $product->nameAr,
                            'product_stock' => $product->stock,
                            'product_code' => $product->product_code,
                            'product_image' => $images['product_image'],
                            'product_viewImages' => $images['product_viewImages'],
                            'product_normalImages' => $images['product_normalImages'],
                            'product_image3d' => $images['product_image3d'],
                            'number_of_used_products' => $recipe->items_count,
                            'cost_price' => (float) $recipe->unit_cost,
                            'has_cost_price' => true,
                            'assembly_recipe_id' => $recipe->id,
                            'assembly_additional_cost' => (float) $recipe->additional_cost,
                            'tags' => $product->tags->map(fn ($t) => [
                                'id' => $t->id,
                                'name' => $t->name,
                                'color' => $t->color,
                            ])->values(),
                        ];
                    });

                $existingIds = $formatted->pluck('product_id')->map(fn ($id) => (int) $id)->all();
                $formatted = $formatted
                    ->concat($assemblyFormatted->reject(fn ($row) => in_array((int) $row['product_id'], $existingIds, true)))
                    ->values();
            }

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

            $products = ProductSearchFilter::apply(Product::query(), $search)
                ->with([
                    'viewImages:id,itemId,imageUrl',
                    'normalImages:id,itemId,imageUrl',
                    'image3d:id,itemId,imageUrl',
                    'tags' => function ($q) {
                        $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active');
                    },
                ])
                ->get(['id', 'nameAr', 'stock', 'product_code', 'normailPrice']);

            $formatted = $products->map(function ($product) {
                $images = \App\Support\ProductImageResolver::formatForList($product);

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_normail_price' => (float) ($product->normailPrice ?? 0),
                    'product_code' => $product->product_code,
                    'product_image' => $images['product_image'],
                    'product_viewImages' => $images['product_viewImages'],
                    'product_normalImages' => $images['product_normalImages'],
                    'product_image3d' => $images['product_image3d'],
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

    /**
     * Preset size labels for dropdowns (admin settings — stored in app_settings).
     */
    public function sizeOptionPresets(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'sizes' => $this->configuredSizeOptionPresets(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function updateSizeOptionPresets(Request $request)
    {
        try {
            $data = $request->validate([
                'sizes' => ['required', 'array'],
                'sizes.*' => ['required', 'string', 'max:50'],
            ]);

            $normalized = collect($data['sizes'])
                ->map(fn ($s) => trim((string) $s))
                ->filter(fn ($s) => $s !== '')
                ->unique()
                ->values()
                ->all();

            AppSetting::set(
                AppSetting::KEY_PRODUCT_SIZE_OPTIONS,
                json_encode($normalized, JSON_UNESCAPED_UNICODE)
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.settings_updated'),
                'sizes' => $normalized,
            ], 200);
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
     * @return list<string>
     */
    private function configuredSizeOptionPresets(): array
    {
        $raw = AppSetting::get(AppSetting::KEY_PRODUCT_SIZE_OPTIONS, '');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $fromDb = collect($decoded)
                    ->map(fn ($s) => trim((string) $s))
                    ->filter(fn ($s) => $s !== '')
                    ->unique()
                    ->values()
                    ->all();
                if ($fromDb !== []) {
                    return $fromDb;
                }
            }
        }

        return array_values(array_filter(array_map(
            'trim',
            (array) config('store.size_options', ['صغير', 'متوسط', 'كبير'])
        )));
    }
}
