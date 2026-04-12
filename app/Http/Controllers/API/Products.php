<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\ViewImageProduct;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

use function PHPUnit\Framework\isEmpty;

class Products extends Controller
{
    public function allProducts(){
      try{
        $products = Product::with('projects:product_id,project_id')
        ->get(['id','nameAr','stock'])
        ;
        
        $products->map(function ($product) {
            $product['projects'] = $product->projects->pluck('project_id')->toArray();
            $product->unsetRelation('projects');
            return $product;
        });

        
            return response()->json([
                'status' => 'success',
                'products' => $products
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
