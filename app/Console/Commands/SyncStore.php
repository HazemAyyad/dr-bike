<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Product;
use App\Models\SubCategoryProduct;
use App\Models\NormalImageProduct;
use App\Models\Image3dProduct;
use App\Models\ViewImageProduct;
use App\Models\Size;
use App\Models\SizeColor;

class SyncStore extends Command
{
    protected $signature = 'sync:store';
    protected $description = 'Fully sync categories, subcategories, and products from remote store';

    public function handle()
    {
        // مزامنة المتجر الخارجي معطّلة — لا طلبات HTTP ولا تعديل على البيانات.
        $this->warn('Store sync is disabled — no remote calls or DB updates will run.');

        return self::SUCCESS;
    }

    // -------------------------------
    // LOGIN TO REMOTE STORE
    // -------------------------------
    private function storeLogin()
    {
            $loginResponse = Http::post(env('STORE_DOMAIN').'/Auth/login', [
                'email' => env('STORE_EMAIL'),
                'password' => env('STORE_PASSWORD'),
            ]);

            if (!$loginResponse->successful()) {
                return response()->json(['status' => 'error', 'message' => 'Login faileddd'], 401);
            }

            return $loginResponse->json('token'); // Adjust key if needed
    }

    // -------------------------------
    // SYNC CATEGORIES
    // -------------------------------
    private function syncCategories($token)
    {
        $response = Http::post(
            env('STORE_DOMAIN') . '/MainCategorys/GetAllMainCategory?StatusShow=All',
            [
                'listRelatedObjects' => ["dolore Ut fugiat Excepteur","amet"],
                'entity' => ["nullable" => true],
                "listOrderOptions"=> [
                    "esse deserunt aliqua",
                    "id"
                ],   
            'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
            ]
        )->json();


        $rows = $response['rows'] ?? [];
        $newIds = collect($rows)->pluck('id')->toArray();

        Category::whereNotIn('id', $newIds)->delete();

        foreach ($rows as $cat) {
            Category::updateOrCreate(
                ['id' => $cat['id']],
                Arr::except($cat, ['supCategories'])
            );
        }  
    }

    // -------------------------------
    // SYNC SUBCATEGORIES
    // -------------------------------
    private function syncSubCategories($token)
    {
        $response = Http::withToken($token)->post(
            env('STORE_DOMAIN') . '/SupCategorys/GetAllSupCategories?StatusShow=All',
            [
                'listRelatedObjects' => ["dolore Ut fugiat Excepteur","amet"],
                'entity' => ["nullable" => true],
                "listOrderOptions"=> [
                    "esse deserunt aliqua",
                    "id"
                ],   
            'paginationInfo' => ["pageIndex" => 0, "pageSize" => 0]
            ]
        )->json();

        $rows = $response['rows'] ?? [];
        $newIds = collect($rows)->pluck('id')->toArray();

        SubCategory::whereNotIn('id', $newIds)->delete();

        foreach ($rows as $sub) {
            SubCategory::updateOrCreate(
                ['id' => $sub['id']],
                $sub
            );
        }
    }

    // -------------------------------
    // IMPORT ALL PRODUCTS
    // -------------------------------
    private function importAllProducts($token)
    {
        $subCategories = SubCategory::all();

        foreach ($subCategories as $sub) {
            $this->processPaginatedProducts($token, $sub->id, 'shown');
            $this->processPaginatedProducts($token, $sub->id, 'unshown');
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
                        "ItemSize", "ItemColor", "SupCategories",
                        "ViewImgs", "NormalImgs", "_3DImgs"
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
        }
        while (count($products) === $pageSize);
    }

    // -------------------------------
    // STORE OR UPDATE ONE PRODUCT (FULL SYNC)
    // -------------------------------
    private function storeSingleProduct($product)
    {
        // Remove relational arrays
        $productData = Arr::except($product, [
            'supCategory', 'normalImagesItems', '_3DImagesItems',
            'viewImagesItems', 'itemSizes', 'ItemColor'
        ]);

        $productData['stock'] = $productData['stock'] ?? 0;

        // Update or create main product
        Product::updateOrCreate(
            ['id' => $product['id']],
            $productData
        );

        /*
        |--------------------------------------------------------------------------
        | SUBCATEGORY RELATIONS
        |--------------------------------------------------------------------------
        */
        $newSubCatIds = collect($product['supCategory'] ?? [])->pluck('id')->toArray();

        SubCategoryProduct::where('product_id', $product['id'])
            ->whereNotIn('sub_category_id', $newSubCatIds)
            ->delete();

        foreach ($newSubCatIds as $cid) {
            SubCategoryProduct::updateOrCreate(
                ['product_id' => $product['id'], 'sub_category_id' => $cid]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | NORMAL IMAGES
        |--------------------------------------------------------------------------
        */
        $newNormalIds = collect($product['normalImagesItems'] ?? [])->pluck('id')->toArray();

        NormalImageProduct::where('itemId', $product['id'])
            ->whereNotIn('id', $newNormalIds)
            ->delete();

        foreach ($product['normalImagesItems'] ?? [] as $img) {
            NormalImageProduct::updateOrCreate(
                ['id' => $img['id']],
                [
                    'imageUrl' => $img['imageUrl'],
                    'itemId' => $product['id']
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 3D IMAGES
        |--------------------------------------------------------------------------
        */
        $new3DIds = collect($product['_3DImagesItems'] ?? [])->pluck('id')->toArray();

        Image3dProduct::where('itemId', $product['id'])
            ->whereNotIn('id', $new3DIds)
            ->delete();

        foreach ($product['_3DImagesItems'] ?? [] as $img) {
            Image3dProduct::updateOrCreate(
                ['id' => $img['id']],
                [
                    'imageUrl' => $img['imageUrl'],
                    'itemId' => $product['id']
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | VIEW IMAGES
        |--------------------------------------------------------------------------
        */
        $newViewIds = collect($product['viewImagesItems'] ?? [])->pluck('id')->toArray();

        ViewImageProduct::where('itemId', $product['id'])
            ->whereNotIn('id', $newViewIds)
            ->delete();

        foreach ($product['viewImagesItems'] ?? [] as $img) {
            ViewImageProduct::updateOrCreate(
                ['id' => $img['id']],
                [
                    'imageUrl' => $img['imageUrl'],
                    'itemId' => $product['id']
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SIZES & COLORS
        |--------------------------------------------------------------------------
        */
        $newSizeIds = collect($product['itemSizes'] ?? [])->pluck('id')->toArray();

        Size::where('itemId', $product['id'])
            ->whereNotIn('id', $newSizeIds)
            ->delete();

        foreach ($product['itemSizes'] ?? [] as $size) {

            Size::updateOrCreate(
                ['id' => $size['id']],
                [
                    'itemId' => $product['id'],
                    'size' => $size['size'],
                    'discount' => $size['discount'],
                    'description' => $size['description'],
                ]
            );

            $newColorIds = collect($size['itemSizeColor'] ?? [])->pluck('id')->toArray();

            SizeColor::where('sizeId', $size['id'])
                ->whereNotIn('id', $newColorIds)
                ->delete();

            foreach ($size['itemSizeColor'] ?? [] as $color) {
                SizeColor::updateOrCreate(
                    ['id' => $color['id']],
                    $color
                );
            }
        }
    }
}
