<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\Product;
use App\Models\ProductAliasMapping;
use App\Models\PersonProductSetting;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\ViewImageProduct;
use App\Services\ProductStockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isEmpty;

class Products extends Controller
{
    private array $pasteStopWords = [
        'عدد', 'كمية', 'حبة', 'حبه', 'قطع', 'قطعة', 'قطعه',
        'عادي', 'كبير', 'كبيرة', 'صغير', 'صغيرة',
    ];

    public function allProducts(Request $request){
      try{
        $stockService = app(ProductStockService::class);

        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $sellerId = $request->filled('seller_id') ? (int) $request->input('seller_id') : null;
        $settings = collect();
        if (($customerId !== null) xor ($sellerId !== null)) {
            $settings = PersonProductSetting::query()
                ->where($customerId !== null ? 'customer_id' : 'seller_id', $customerId ?? $sellerId)
                ->with('priceTiers')
                ->get()
                ->keyBy('product_id');
        }

        $products = Product::query()
            ->with([
                'projects:product_id,project_id',
                'viewImages',
                'normalImages',
                'image3d',
                'storeSection:id,name',
                'sizes.colorSizes',
                'purchasePrices' => fn ($q) => $q->orderByDesc('id'),
            ]);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $products->where(function ($q) use ($term) {
                $q->where('nameAr', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhereHas('storeSection', function ($section) use ($term) {
                        $section->where('name', 'like', $term);
                    })
                    ->orWhereHas('sizes', function ($size) use ($term) {
                        $size->where('size', 'like', $term)
                            ->orWhereHas('colorSizes', function ($color) use ($term) {
                                $color->where('colorAr', 'like', $term)
                                    ->orWhere('colorEn', 'like', $term)
                                    ->orWhere('colorAbbr', 'like', $term);
                            });
                    });
            });
        }

        if ($request->filled('store_section_id')) {
            $storeSectionId = $request->input('store_section_id');
            if (in_array((string) $storeSectionId, ['none', 'null', '0'], true)) {
                $products->whereNull('store_section_id');
            } else {
                $products->where('store_section_id', (int) $storeSectionId);
            }
        }

        $products = $products->get([
                'id',
                'nameAr',
                'stock',
                'normailPrice',
                'wholesalePrice',
                'price',
                'min_sale_price',
                'rate',
                'product_code',
                'store_section_id',
            ]);

        $formatted = $products
          ->reject(fn ($product) => (bool) ($settings->get($product->id)?->is_hidden ?? false))
          ->map(fn ($product) => $this->formatSaleProduct($product, $stockService, $settings))
          ->values();

            return response()->json([
                'status' => 'success',
                'products' => $formatted,
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

    public function pasteSuggestions(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|max:10000',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'seller_id' => 'nullable|integer|exists:sellers,id',
        ]);

        $stockService = app(ProductStockService::class);
        $settings = $this->personProductSettingsForRequest($request);
        $products = Product::query()
            ->with([
                'projects:product_id,project_id',
                'viewImages',
                'normalImages',
                'image3d',
                'storeSection:id,name',
                'sizes.colorSizes',
                'purchasePrices' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->get([
                'id',
                'nameAr',
                'stock',
                'normailPrice',
                'wholesalePrice',
                'price',
                'min_sale_price',
                'rate',
                'product_code',
                'store_section_id',
            ])
            ->reject(fn ($product) => (bool) ($settings->get($product->id)?->is_hidden ?? false))
            ->values();

        $aliasRows = ProductAliasMapping::query()
            ->with('product:id,nameAr,product_code')
            ->get()
            ->groupBy('normalized_alias');

        $lines = collect(preg_split('/\R/u', $data['text']))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->map(function (string $line, int $index) use ($products, $settings, $stockService, $aliasRows) {
                $parsed = $this->parsePasteLine($line);
                $normalized = $this->normalizeAlias($parsed['search_text']);
                $tokens = $this->pasteTokens($parsed['search_text']);
                $suggestions = [];

                foreach (($aliasRows->get($normalized) ?? collect()) as $alias) {
                    $product = $products->firstWhere('id', $alias->product_id);
                    if ($product) {
                        $suggestions[$product->id] = [
                            'score' => 1000 + (int) $alias->times_used,
                            'reason' => 'تعلم سابق',
                            'product' => $this->formatSaleProduct($product, $stockService, $settings),
                        ];
                    }
                }

                foreach ($products as $product) {
                    $score = $this->scoreProductForPaste($product, $tokens, $parsed['search_text']);
                    if ($score <= 0) {
                        continue;
                    }
                    $current = $suggestions[$product->id]['score'] ?? 0;
                    if ($score > $current) {
                        $suggestions[$product->id] = [
                            'score' => $score,
                            'reason' => 'تشابه الاسم والكلمات',
                            'product' => $this->formatSaleProduct($product, $stockService, $settings),
                        ];
                    }
                }

                $suggestions = collect($suggestions)
                    ->sortByDesc('score')
                    ->take(5)
                    ->values()
                    ->all();

                return [
                    'index' => $index,
                    'raw_line' => $line,
                    'search_text' => $parsed['search_text'],
                    'quantity' => $parsed['quantity'],
                    'normalized_alias' => $normalized,
                    'suggestions' => $suggestions,
                ];
            });

        return response()->json([
            'status' => 'success',
            'lines' => $lines,
        ]);
    }

    public function storePasteAlias(Request $request)
    {
        $data = $request->validate([
            'alias_text' => 'required|string|max:255',
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $normalized = $this->normalizeAlias($data['alias_text']);
        $userId = auth()->id();

        $mapping = ProductAliasMapping::query()->firstOrNew([
            'normalized_alias' => $normalized,
            'product_id' => $data['product_id'],
        ]);

        $mapping->alias_text = $data['alias_text'];
        $mapping->times_used = $mapping->exists ? ((int) $mapping->times_used) + 1 : 1;
        $mapping->created_by = $mapping->created_by ?: $userId;
        $mapping->updated_by = $userId;
        $mapping->last_used_at = now();
        $mapping->save();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ ربط المنتج مع النص.',
        ]);
    }

    public function ocrText(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:8192',
        ]);

        $apiKey = config('services.google_vision.api_key');
        if (!$apiKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Vision API key is not configured.',
                'text' => '',
            ], 503);
        }

        $image = $request->file('image');
        $content = base64_encode(file_get_contents($image->getRealPath()));

        $response = Http::timeout(30)->post(
            'https://vision.googleapis.com/v1/images:annotate?key='.$apiKey,
            [
                'requests' => [
                    [
                        'image' => ['content' => $content],
                        'features' => [
                            ['type' => 'DOCUMENT_TEXT_DETECTION'],
                        ],
                        'imageContext' => [
                            'languageHints' => ['ar', 'en'],
                        ],
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            return response()->json([
                'status' => 'error',
                'message' => 'OCR provider failed.',
                'text' => '',
            ], 502);
        }

        $payload = $response->json();
        $result = $payload['responses'][0] ?? [];
        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error']['message'] ?? 'OCR provider failed.',
                'text' => '',
            ], 502);
        }

        $text = $result['fullTextAnnotation']['text']
            ?? ($result['textAnnotations'][0]['description'] ?? '');

        return response()->json([
            'status' => 'success',
            'text' => trim((string) $text),
        ]);
    }

    private function personProductSettingsForRequest(Request $request)
    {
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $sellerId = $request->filled('seller_id') ? (int) $request->input('seller_id') : null;
        if (($customerId !== null) xor ($sellerId !== null)) {
            return PersonProductSetting::query()
                ->where($customerId !== null ? 'customer_id' : 'seller_id', $customerId ?? $sellerId)
                ->with('priceTiers')
                ->get()
                ->keyBy('product_id');
        }

        return collect();
    }

    private function parsePasteLine(string $line): array
    {
        $text = $this->normalizeDigits($line);
        $quantity = 1;
        foreach ([
            '/(?:عدد|كمية|qty|quantity)\s*[:：]?\s*(\d+)/iu',
            '/[xX×]\s*(\d+)\s*$/u',
            '/(\d+)\s*(?:حبة|حبه|قطع|قطعة|قطعه)\s*$/u',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $quantity = max(1, (int) ($matches[1] ?? 1));
                $text = trim(preg_replace($pattern, '', $text) ?? $text);
                break;
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', str_replace(['-', '–', '—'], ' ', $text)) ?? $text);

        return [
            'search_text' => $text,
            'quantity' => $quantity,
        ];
    }

    private function scoreProductForPaste(Product $product, array $tokens, string $query): int
    {
        if (empty($tokens)) {
            return 0;
        }

        $fields = $this->productSearchFields($product);
        $fullHaystack = $this->normalizeAlias(implode(' ', $fields));
        $fullQuery = $this->normalizeAlias($query);
        $score = Str::contains($fullHaystack, $fullQuery) ? 120 : 0;
        $matched = 0;

        foreach ($tokens as $token) {
            $aliases = $this->tokenAliases($token);
            $tokenMatched = false;
            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizeAlias($alias);
                if ($normalizedAlias !== '' && Str::contains($fullHaystack, $normalizedAlias)) {
                    $score += preg_match('/\d/u', $token) ? 80 : 35;
                    $tokenMatched = true;
                    break;
                }
            }
            if (preg_match('/\d/u', $token) && ! $tokenMatched) {
                return 0;
            }
            if ($tokenMatched) {
                $matched++;
            }
        }

        $required = max(1, (int) ceil(count($tokens) * 0.6));
        return $matched >= $required ? $score + ($matched * 10) : 0;
    }

    private function productSearchFields(Product $product): array
    {
        $fields = [
            (string) $product->nameAr,
            (string) $product->product_code,
            (string) $product->id,
            (string) ($product->storeSection?->name ?? ''),
        ];

        foreach ($product->sizes ?? [] as $size) {
            $fields[] = (string) $size->size;
            foreach ($size->colorSizes ?? [] as $color) {
                $fields[] = (string) $color->colorAr;
                $fields[] = (string) $color->colorEn;
                $fields[] = (string) $color->colorAbbr;
            }
        }

        return array_filter($fields, fn ($field) => trim($field) !== '');
    }

    private function pasteTokens(string $text): array
    {
        $normalized = $this->normalizeAlias($text);
        $parts = preg_split('/[\s,،\/\\\\\-]+/u', $normalized) ?: [];

        return collect($parts)
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '' && ! in_array($part, $this->pasteStopWords, true))
            ->filter(fn ($part) => mb_strlen($part) > 1 || preg_match('/\d/u', $part))
            ->values()
            ->all();
    }

    private function tokenAliases(string $token): array
    {
        return match ($this->normalizeAlias($token)) {
            'لوحة', 'لوحه' => ['لوحة', 'لوحه', 'كومبيوتر', 'كمبيوتر', 'كنترولر', 'controller'],
            'w', 'وات', 'واط' => ['w', 'وات', 'واط'],
            'فحمات', 'فحمه', 'فحمة' => ['فحمات', 'فحمة', 'فحمه', 'فرامل', 'بريك', 'brake'],
            'مربع' => ['مربع', 'مربعة', 'square'],
            'ضوء', 'ضو' => ['ضوء', 'ضو', 'ليت', 'لمبة', 'لمبه', 'كشاف', 'light'],
            'مدور' => ['مدور', 'دائري', 'دائرة', 'round'],
            'مجوز' => ['مجوز', 'زوج', 'جوز', 'مزدوج', 'double'],
            'اكس' => ['اكس', 'أكس', 'محور', 'اكسل', 'axle', 'motor'],
            'قاعدة' => ['قاعدة', 'حامل', 'بيت', 'base', 'holder'],
            'بطارية', 'بطاريه' => ['بطارية', 'بطاريه', 'battery'],
            'بلحة', 'بلحه' => ['بلحة', 'بلحه', 'فيشة', 'فيشه', 'مدخل', 'سوكت', 'socket'],
            'شاحن' => ['شاحن', 'شحن', 'charger'],
            'انثى', 'انتى' => ['انثى', 'انتى', 'female', 'f'],
            'حساسات', 'حساس' => ['حساسات', 'حساس', 'sensor'],
            'ماطور', 'ماتور', 'موتور' => ['ماطور', 'ماتور', 'موتور', 'motor'],
            'ضروس', 'ضرس' => ['ضروس', 'ضرس', 'ترس', 'تروس', 'مسنن', 'gear'],
            'مستقيم' => ['مستقيم', 'سنتر', 'straight'],
            'دعسات', 'دعسه', 'دعسة' => ['دعسات', 'دعسة', 'دعسه', 'دواسات', 'دواسة', 'pedal'],
            default => [$token],
        };
    }

    private function normalizeAlias(string $text): string
    {
        $text = mb_strtolower($this->normalizeDigits($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace(['ة'], 'ه', $text);
        $text = preg_replace('/[^\p{Arabic}a-z0-9]+/iu', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    private function formatSaleProduct(Product $product, ProductStockService $stockService, $settings): array
    {
        $unitPrice = (float) ($product->normailPrice ?? $product->price ?? 0);
        if ($unitPrice <= 0) {
            $unitPrice = (float) ($product->min_sale_price ?? 0);
        }

        $variantPayload = $stockService->formatProductForSaleApi($product);

        $row = array_merge(
            [
                'id' => $product->id,
                'nameAr' => $product->nameAr,
                'stock' => $variantPayload['stock'],
                'has_variants' => $variantPayload['has_variants'],
                'sizes' => $variantPayload['sizes'],
                'normail_price' => $unitPrice,
                'wholesale_price' => (float) ($product->wholesalePrice ?? 0),
                'rate' => (float) ($product->rate ?? 0),
                'product_code' => $product->product_code,
                'store_section_id' => $product->store_section_id !== null
                    ? (int) $product->store_section_id
                    : null,
                'store_section_name' => $product->storeSection?->name,
                'projects' => $product->projects->pluck('project_id')->toArray(),
            ],
            \App\Support\ProductImageResolver::formatForList($product),
        );

        $setting = $settings->get($product->id);
        if ($setting !== null && $setting->custom_price !== null) {
            $row['custom_price'] = (float) $setting->custom_price;
            $row['has_custom_price'] = true;
        } else {
            $row['has_custom_price'] = false;
        }
        $row['price_tiers'] = $setting === null
            ? []
            : $setting->priceTiers
                ->sortBy('min_qty')
                ->values()
                ->map(fn ($tier) => [
                    'min_qty' => (int) $tier->min_qty,
                    'max_qty' => $tier->max_qty === null ? null : (int) $tier->max_qty,
                    'unit_price' => (float) $tier->unit_price,
                ])
                ->all();

        if (auth()->user()?->canViewCostPrice()) {
            $row['purchase_cost'] = (float) ($product->purchasePrices->first()?->price ?? 0);
            $row['cost_price'] = $row['purchase_cost'];
        }

        return $row;
    }

    /**
     * تحديث سعر بيع المفرق (normailPrice) من شاشة البيع الفوري.
     */
    public function updateRetailPrice(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => 'required|integer|exists:products,id',
                'normail_price' => 'required|numeric|min:0.01',
                'wholesale_price' => 'nullable|numeric|min:0',
            ]);

            $product = Product::findOrFail($data['product_id']);
            $updates = ['normailPrice' => $data['normail_price']];
            if (array_key_exists('wholesale_price', $data) && $data['wholesale_price'] !== null) {
                $updates['wholesalePrice'] = $data['wholesale_price'];
            }
            $product->update($updates);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.product_updated'),
                'normail_price' => (float) $product->normailPrice,
                'wholesale_price' => (float) ($product->wholesalePrice ?? 0),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.update_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // **************************************************************

    // STORE APIs

    // store main categories
    public function storeShownMainCategories()
{
    try{
                $response = Http::post('http://mjsall-001-site1.jtempurl.com/MainCategorys/GetAllShowMainCategories', [
                'listRelatedObjects' => [
                    "dolore Ut fugiat Excepteur",
                    "amet"
                ],
                'entity' => [
                    "nullable" => true
                ],
                'listOrderOptions' => [
                    "esse deserunt aliqua",
                    "id"
                ],
                'paginationInfo' => [
                    "pageIndex" => 0,
                    "pageSize" => 0
                ],
            ]);

            foreach($response['rows'] as $mainCategory){
                        Category::create($mainCategory);
                    }
                    return response()->json(['status'=>'success'],200);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
                'msg'=> $e->getMessage(),

            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'msg'=> $e->getMessage(),
            ], 200);
        }
}


    public function storeUnshownMainCategories()
{
    try{
                $response = Http::post('http://mjsall-001-site1.jtempurl.com/MainCategorys/GetAllMainCategory?StatusShow=UnShow', [
                'listRelatedObjects' => [
                    "dolore Ut fugiat Excepteur",
                    "amet"
                ],
                'entity' => [
                    "nullable" => true
                ],
                'listOrderOptions' => [
                    "esse deserunt aliqua",
                    "id"
                ],
                'paginationInfo' => [
                    "pageIndex" => 0,
                    "pageSize" => 0
                ],
            ]);

            foreach($response['rows'] as $mainCategory){
                        Category::create($mainCategory);
                    }
                    return response()->json(['status'=>'success'],200);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
                'msg'=> $e->getMessage(),

            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'msg'=> $e->getMessage(),
            ], 200);
        }
}



   public function storeSubCategories()
{
    try{
                $response = Http::post(env('STORE_DOMAIN').'/SupCategorys/GetAllSupCategories?StatusShow=All', [
                'listRelatedObjects' => [
                    "dolore Ut fugiat Excepteur",
                    "amet"
                ],
                'entity' => [
                    "nullable" => true
                ],
                'listOrderOptions' => [
                    "esse deserunt aliqua",
                    "id"
                ],
                'paginationInfo' => [
                    "pageIndex" => 0,
                    "pageSize" => 0
                ],
            ]);

            foreach($response['rows'] as $subCategory){
                        SubCategory::create($subCategory);
                    }
                    return response()->json(['status'=>'success'],200);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
                'msg'=> $e->getMessage(),

            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'msg'=> $e->getMessage(),
            ], 200);
        }
}






    // to retrieve token
    private function storeLogin(){
            $loginResponse = Http::post(env('STORE_DOMAIN').'/Auth/login', [
                'email' => env('STORE_EMAIL'),
                'password' => env('STORE_PASSWORD'),
            ]);

            if (!$loginResponse->successful()) {
                return response()->json(['status' => 'error', 'message' => 'Login failed'], 401);
            }

            $token = $loginResponse->json('token'); // Adjust key if needed
    }


    private function getShowMainCategories(){
            $mainCategories =Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(env('STORE_DOMAIN').'/MainCategorys/GetAllShowMainCategories', [
            'listRelatedObjects' => ["dolore Ut fugiat Excepteur", "amet"],
            'entity' => ["nullable" => true],
            'listOrderOptions' => ["esse deserunt aliqua", "id"],
            'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
        ])->json();

        return $mainCategories;
    }

    // get either Show or Unshow categories
    private function getSubCategoriesByStatus($status){
        $subCategories = Http::post(env('STORE_DOMAIN')."/SupCategorys/GetAllSupCategories?StatusShow=$status", [
            'listRelatedObjects' => ["dolore Ut fugiat Excepteur", "amet"],
            'entity' => ["nullable" => true],
            'listOrderOptions' => ["esse deserunt aliqua", "id"],
            'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
        ])->json();

        return $subCategories;
    }

    // combine both Show and Unshow categories
    private function getAllSubCategories(){
        $subCategories = $this->getSubCategoriesByStatus('All');

        return $subCategories; 
    }




    private function getAllShownItemsOfSubCategory($token,$subCategoryId){
        $shownProducts = Http::withToken($token)->post(
            env('STORE_DOMAIN')."/Items/GetAllShowItemsBySupCatId?supCategoryId=$subCategoryId",
            [
                'listRelatedObjects' => [
                    "ItemSize",
                    "ItemColor",
                    "SupCategories",
                    "ViewImgs",
                    "NormalImgs",
                    "_3DImgs",
                ],
                'entity' => ["nullable" => true],
                'listOrderOptions' => ["esse deserunt aliqua", "id"],
                'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
            ]
        )->json();

        return $shownProducts;
    }

//     private function getAllShownItemsOfSubCategory($token, $subCategoryId)
// {
//     $allProducts = [];
//     $page = 0;
//     $pageSize = 50;

//     do {
//         $response = Http::withToken($token)->post(
//             env('STORE_DOMAIN') . "/Items/GetAllShowItemsBySupCatId?supCategoryId=$subCategoryId",
//             [
//                 'listRelatedObjects' => [
//                     "ItemSize", "ItemColor", "SupCategories", "ViewImgs", "NormalImgs", "_3DImgs",
//                 ],
//                 'entity' => ["nullable" => true],
//                 'listOrderOptions' => ["esse deserunt aliqua", "id"],
//                 'paginationInfo' => ["pageIndex" => $page, "pageSize" => $pageSize]
//             ]
//         )->json();

//         $rows = $response['rows'] ?? [];

//         $allProducts = array_merge($allProducts, $rows);
//         $page++;
//     } while (count($rows) === $pageSize);

//     return ['rows' => $allProducts];
// }



        private function getAllUnshownItemsOfSubCategory($token,$subCategoryId){
        $unShownProducts = Http::withToken($token)->post(
            env('STORE_DOMAIN')."/Items/GetAllItemsToSup?supCategoryId=".$subCategoryId."&StatusShow=UnShow",
            [
                'listRelatedObjects' => [
                        "ItemSize",
                        "ItemColor",
                        "SupCategories",
                        "ViewImgs",
                        "NormalImgs",
                        "_3DImgs",
                ],
                'entity' => ["nullable" => true],
                'listOrderOptions' => ["esse deserunt aliqua", "id"],
                'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
            ]
        )->json();

        return $unShownProducts;
    }

//     private function getAllUnshownItemsOfSubCategory($token, $subCategoryId)
// {
//     $allProducts = [];
//     $page = 0;
//     $pageSize = 50;

//     do {
//         $response = Http::withToken($token)->post(
//             env('STORE_DOMAIN') . "/Items/GetAllItemsToSup?supCategoryId=$subCategoryId&StatusShow=UnShow",
//             [
//                 'listRelatedObjects' => [
//                     "ItemSize", "ItemColor", "SupCategories", "ViewImgs", "NormalImgs", "_3DImgs",
//                 ],
//                 'entity' => ["nullable" => true],
//                 'listOrderOptions' => ["esse deserunt aliqua", "id"],
//                 'paginationInfo' => ["pageIndex" => $page, "pageSize" => $pageSize]
//             ]
//         )->json();

//         $rows = $response['rows'] ?? [];

//         $allProducts = array_merge($allProducts, $rows);
//         $page++;
//     } while (count($rows) === $pageSize);

//     return ['rows' => $allProducts];
// }


    // store all products shown and unshown from shown and unshown subcategories
  public function importAllProducts()
  {
   try{

    $token = $this->storeLogin();

    $subCategories = $this->getAllSubCategories();
       
    
        foreach ($subCategories['rows'] as $subCategory) {
           $subCategoryId = $subCategory['id'];
           
            $shownProducts = $this->getAllShownItemsOfSubCategory($token, $subCategoryId);
            $unShownProducts = $this->getAllUnshownItemsOfSubCategory($token,$subCategoryId);

           


            $products = array_merge(
                $shownProducts['rows'] ?? [],
                $unShownProducts['rows'] ?? []
            );      
            
           
            foreach ($products?? [] as $product) {

                $existingProduct = Product::where('id',$product['id'])->first();
                if(!$existingProduct){
                    $productData = Arr::except($product,['supCategory','normalImagesItems',
                    '_3DImagesItems','viewImagesItems','itemSizes','ItemColor']);
                    Product::create($productData);

                    foreach($product['supCategory']?? [] as $subCategory){
                        SubCategoryProduct::create([
                            'product_id' => $product['id'],
                            'sub_category_id' => $subCategory['id'],
                        ]);
                    }
                    // product Images store
                    foreach($product['normalImagesItems']?? [] as $image){
                        NormalImageProduct::create([
                            'id' => $image['id'],
                            'imageUrl' => $image['imageUrl'],
                            'itemId' => $product['id'],

                        ]);
                    }

                    foreach($product['_3DImagesItems']?? [] as $image){
                        Image3dProduct::create([
                            'id' => $image['id'],
                            'imageUrl' => $image['imageUrl'],
                            'itemId' => $product['id'],

                        ]);
                    }

                    foreach($product['viewImagesItems']?? [] as $image){
                        ViewImageProduct::create([
                            'id' => $image['id'],
                            'imageUrl' => $image['imageUrl'],
                            'itemId' => $product['id'],

                        ]);
                    }

                    // products sizes and colors store
                    foreach($product['itemSizes']?? [] as $size){
                        Size::create([
                            'id' => $size['id'],
                            'itemId' => $size['itemId'],
                            'size' => $size['size'],
                            'discount' => $size['discount'],
                            'description' => $size['description'],

                        ]);
                        if(count($size['itemSizeColor'])>0){
                            foreach($size['itemSizeColor'] as $color){
                            SizeColor::create($color);
                        }
                    }
                    }
                }

            }



                // $productData = Arr::except($products[0],['supCategory','normalImagesItems',
                // '_3DImagesItems','viewImagesItems','itemSizes']);
                // Product::create($productData);
            
        }

   
    
    return response()->json(['status' => 'done']);
}


   catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'msg'=> $e->getMessage(),
            ], 200);
        }

}






}
