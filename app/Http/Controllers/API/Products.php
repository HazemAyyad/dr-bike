<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\Product;
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
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isEmpty;

class Products extends Controller
{
    public function allProducts(Request $request){
      try{
        $stockService = app(ProductStockService::class);

        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $sellerId = $request->filled('seller_id') ? (int) $request->input('seller_id') : null;
        $settings = collect();
        if (($customerId !== null) xor ($sellerId !== null)) {
            $settings = PersonProductSetting::query()
                ->where($customerId !== null ? 'customer_id' : 'seller_id', $customerId ?? $sellerId)
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
                'purchasePrices' => fn ($q) => $q->orderByDesc('id')->limit(1),
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
            ]);

        $formatted = $products
          ->reject(fn ($product) => (bool) ($settings->get($product->id)?->is_hidden ?? false))
          ->map(function ($product) use ($stockService, $settings) {
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

            if (auth()->user()?->type === 'admin') {
                $row['purchase_cost'] = (float) ($product->purchasePrices->first()?->price ?? 0);
            }

            return $row;
        })->values();

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
