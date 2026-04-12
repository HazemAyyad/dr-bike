<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class Orders extends Controller
{
    public function addOrder(Request $request){

        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

        $data = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:1',
            'payment_method'=>'required|string',
        ]);

        $user = $request->user();
        $order = Order::create([
            'customer_id' => $user->customer->id,
        ]);
        $productData = [];
        $total=0;

        foreach ($data['products'] as $product) {
            $productData[$product['id']] = ['quantity' => $product['quantity']];
            $dbProduct = Product::findOrFail($product['id']);
            $total += $dbProduct->price * $product['quantity'] ;
        }
    
        // Attach products with quantities to the order
        $order->products()->attach($productData);

        Invoice::create([
            'order_id'=>$order->id,
            'payment_method'=>$data['payment_method'],
            'total'=> $total,
        ]);
    
        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load('products')
        ]);
    }
    else{
        return response(['message'=>'token is expired']);

    }
    }

    private function sharedOrders(Request $request , $status){

        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

            $user = $request->user();
            $customer = $user->customer;
            $orders = $customer->orders()
            ->where('status', $status)
            ->with(['products:id,name'])
            ->get(['id', 'status', 'created_at']); // only get needed fields from orders
    
            $formatted = $orders->map(function ($order) {
                return [

                    'order_id' => $order->id,
                    'status'     => $order->status,
                    'created_at' => $order->created_at->toDateString(),
                    'products'   => $order->products->map(function ($product) {
                        return [
                            'product_id'   => $product->id,
                            'product_name' => $product->name,
                        ];
                    }),
                ];
            });

            return $formatted;
        
       }

        else{
            return response(['message'=>'token is expired']);
    
        }
    }

    public function pendingOrders(Request $request){

       $formatted = $this->sharedOrders($request,'pending');
       return response()->json([
        ' pending orders' => $formatted
        ]);  

    }



    public function completedOrders(Request $request){

        $formatted = $this->sharedOrders($request,'completed');
        return response()->json([
         ' completed orders' => $formatted
         ]);          
    }

    public function canceledOrders(Request $request){

        $formatted = $this->sharedOrders($request,'canceled');
        return response()->json([
         ' canceled orders' => $formatted
         ]);          
    }


    public function cancelOrder(Request $request){
        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

        $request->validate(['order_id'=>'required|exists:orders,id']);

        $order = Order::findOrFail($request->order_id);
        if($order){

            $order->update(['status'=>'canceled']);
            $order->invoice()->update(['status'=>'canceled']);

            return response(['message'=>'order is canceled']);

        }
        else{
            return response(['message'=>'order not found']);

        }

    }
    else{
        return response(['message'=>'token is expired']);

    }

    }

    // return all orders
    public function allOrders(){

        $orders = Order::with(['customer:id,user_id','customer.user:id,name'])->
        get(['id','customer_id','status']);

        $formatted = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'customer_name' => $order->customer->user->name,
                'total' => $order->invoice->total,
                'status' =>$order->status,
            ];
        });

        return response(['all orders'=>$formatted]);


    }

}
