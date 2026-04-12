<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
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

class Test extends Controller
{
    
    public function edit($id){
        $project = Project::findOrFail($id);
        return view('projects.edit',compact('project'));
    }

    public function update(Request $request,$id){
        dd($request->all());
        // $data = $request->validate([

        // ]);
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

        // combine both Show and Unshow categories
    private function getAllSubCategories(){
        $subCategories = $this->getSubCategoriesByStatus('All');

        return $subCategories; 
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

    // heres to store products from other db
    public function importAllProducts()
{
    try {
        ini_set('max_execution_time', 0); // 0 = unlimited

        $token = $this->storeLogin();
        $subCategories = $this->getAllSubCategories();

        foreach ($subCategories['rows'] as $subCategory) {
            $subCategoryId = $subCategory['id'];

            // Process Shown Products
            $this->processPaginatedProducts($token, $subCategoryId, 'shown');

            // Process Unshown Products
            $this->processPaginatedProducts($token, $subCategoryId, 'unshown');
        }

        return response()->json(['status' => 'done']);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
            'msg' => $e->getMessage(),
        ]);
    }
}
private function processPaginatedProducts($token, $subCategoryId, $status)
{
    $page = 0;
    $pageSize = 50;

    do {
        if ($status === 'shown') {
            $endpoint = "/Items/GetAllShowItemsBySupCatId?supCategoryId=$subCategoryId";
        } else {
            $endpoint = "/Items/GetAllItemsToSup?supCategoryId=$subCategoryId&StatusShow=UnShow";
        }

        $response = Http::withToken($token)->post(
            env('STORE_DOMAIN') . $endpoint,
            [
                'listRelatedObjects' => [
                    "ItemSize", "ItemColor", "SupCategories", "ViewImgs", "NormalImgs", "_3DImgs"
                ],
                'entity' => ["nullable" => true],
                'listOrderOptions' => ["id"],
                'paginationInfo' => ["pageIndex" => $page, "pageSize" => $pageSize]
            ]
        )->json();

        $products = $response['rows'] ?? [];

        foreach ($products as $product) {
            $this->storeSingleProduct($product);
        }

        $page++;
    } while (count($products) === $pageSize);
}
private function storeSingleProduct($product)
{
    $existingProduct = Product::find($product['id']);
    if ($existingProduct) return;

    $productData = Arr::except($product, [
        'supCategory', 'normalImagesItems', '_3DImagesItems', 'viewImagesItems', 'itemSizes', 'ItemColor'
    ]);

    Product::create($productData);

    foreach ($product['supCategory'] ?? [] as $subCategory) {
        SubCategoryProduct::create([
            'product_id' => $product['id'],
            'sub_category_id' => $subCategory['id'],
        ]);
    }

    foreach ($product['normalImagesItems'] ?? [] as $image) {
        NormalImageProduct::create([
            'id' => $image['id'],
            'imageUrl' => $image['imageUrl'],
            'itemId' => $product['id'],
        ]);
    }

    foreach ($product['_3DImagesItems'] ?? [] as $image) {
        Image3dProduct::create([
            'id' => $image['id'],
            'imageUrl' => $image['imageUrl'],
            'itemId' => $product['id'],
        ]);
    }

    foreach ($product['viewImagesItems'] ?? [] as $image) {
        ViewImageProduct::create([
            'id' => $image['id'],
            'imageUrl' => $image['imageUrl'],
            'itemId' => $product['id'],
        ]);
    }

    foreach ($product['itemSizes'] ?? [] as $size) {
        Size::create([
            'id' => $size['id'],
            'itemId' => $size['itemId'],
            'size' => $size['size'],
            'discount' => $size['discount'],
            'description' => $size['description'],
        ]);

        foreach ($size['itemSizeColor'] ?? [] as $color) {
            SizeColor::create($color);
        }
    }
}

}
