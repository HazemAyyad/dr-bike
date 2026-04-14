<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StoreManageItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * صفحة ويب للاختبار — لا تضعها على إنتاج عام بدون حماية.
 */
class StoreSyncTestController extends Controller
{
    public function show()
    {
        return view('store-sync-test', [
            'steps' => session('steps'),
            'result' => session('result'),
            'product' => session('product_model'),
        ]);
    }

    public function run(Request $request, StoreManageItemService $storeManageItemService)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'add_quantity' => ['nullable', 'integer', 'min:0', 'max: 999999'],
        ]);

        $steps = [];
        $pageLog = function (string $msg, array $ctx = []) use (&$steps): void {
            $steps[] = [
                'time' => now()->format('H:i:s.v'),
                'message' => $msg,
                'context' => $ctx,
            ];
            Log::info('[store-sync-test page] '.$msg, $ctx);
        };

        $pageLog('طلب من الصفحة', $validated);

        $product = Product::findOrFail($validated['product_id']);
        $pageLog('منتج محمّل', ['id' => $product->id, 'stock_قبل' => $product->stock]);

        $addQty = (int) ($validated['add_quantity'] ?? 0);
        if ($addQty > 0) {
            $product->stock = (int) $product->stock + $addQty;
            $product->save();
            $pageLog('زيادة محلية', ['add_quantity' => $addQty, 'stock_بعد' => $product->stock]);
        }

        $product = $product->fresh();
        $result = $storeManageItemService->syncProductStockToStore($product, $pageLog);
        $pageLog('انتهاء syncProductStockToStore', $result);

        return redirect()
            ->route('test.store-sync')
            ->with('steps', $steps)
            ->with('result', $result)
            ->with('product_model', $product->fresh());
    }
}
