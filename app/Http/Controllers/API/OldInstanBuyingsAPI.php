<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\InstantBuying;
use App\Models\Product;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class OldInstanBuyingsAPI extends Controller
{
    public function addInstantBuying(Request $request){
        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

        $user = $request->user();
        $data = $request->validate([
            'products'=>'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:1',
            'payment_method'=>'required|string',

        ]);

        $productData = [];
        $total=0;

        foreach ($data['products'] as $product) {
            $productData[$product['id']] = ['quantity' => $product['quantity']];
            $dbProduct = Product::findOrFail($product['id']);
            $total += $dbProduct->price * $product['quantity'] ;
        }
    

        $instantBuying = InstantBuying::create([
            'customer_id'=> $user->customer->id,
            'payment_method'=> $data['payment_method'],
            'total' => $total,
        ]);

        $instantBuying->products()->attach($productData);
        return response()->json([
            'message' => 'instant buying created successfully',
            'instant buying' => $instantBuying->load('products')
        ]);
    }
    else{
        return response(['message'=>'token is expired']);

    }
    }

    // retrieve all instant buyings
    public function allInstantBuyings(){

        $instantBuyings = InstantBuying::with(['customer:id,user_id',
        'customer.user:id,name'])
        ->get();

        if($instantBuyings){
            $formatted = [];
            foreach($instantBuyings as $buying){
                $formatted[] = [
                    'id'=> $buying->id,
                    'customer_name' => $buying->customer->user->name,
                    'total'=> $buying->total,
                    'payment_method'=>$buying->payment_method,
                ];
            }
            return response(['all instant buyings'=>$formatted]);
        }
        else{
            return response(['message'=>'instant buyings could not be found']);
        }
    }
}
