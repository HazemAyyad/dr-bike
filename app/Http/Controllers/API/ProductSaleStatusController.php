<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ProductSaleStatusService;
use Illuminate\Http\Request;

class ProductSaleStatusController extends Controller
{
    public function update(Request $request, ProductSaleStatusService $service)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'is_sale_blocked' => ['required', 'boolean'],
        ]);
        $product = $service->setBlocked((int) $data['product_id'], (bool) $data['is_sale_blocked'], $request->user()?->id);

        return response()->json([
            'status' => 'success',
            'product_id' => $product->id,
            'is_sale_blocked' => $product->is_sale_blocked,
            'message' => $product->is_sale_blocked ? 'تم منع المنتج من البيع' : 'تم السماح ببيع المنتج',
        ]);
    }
}
