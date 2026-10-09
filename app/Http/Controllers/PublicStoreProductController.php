<?php

namespace App\Http\Controllers;

use App\Services\OnlineStore\PublicStoreProductPageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicStoreProductController extends Controller
{
    public function show(Request $request, int $product, PublicStoreProductPageService $pages)
    {
        $page = $pages->find($product, $request);
        abort_if($page === null, 404);

        DB::table('online_store_listings')
            ->where('product_id', $product)
            ->increment('view_count');

        return view('store.products.show', $page);
    }
}
