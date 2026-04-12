<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class UserOrders extends Controller
{
    public function completedOrders(Request $request){
        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

          $user = $request->user();
          $orders = $user->orders()
          ->with('product') 
          ->where('status', 'completed')
          ->get()
          ->groupBy('order_date');
  
      $result = [];
  
      foreach ($orders as $date => $groupedOrders) {
          $result[] = [
              'products' => $groupedOrders->pluck('product.name')->filter()->unique()->values(),
              'date' => Carbon::parse($date)->format('jS F'),
              'status' => 'completed'
          ];
      }
  
      return response()->json($result);
    }

    else{
        return response(['message'=>'token is expired']);
    }
}



 

public function ongoingOrders(Request $request){
  if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

          $user = $request->user();
          $orders = $user->orders()
          ->with('product') 
          ->where('status', 'ongoing')
          ->get()
          ->groupBy('order_date');

      $result = [];

      foreach ($orders as $date => $groupedOrders) {
          $result[] = [
              'products' => $groupedOrders->pluck('product.name')->filter()->unique()->values(),
              'date' => Carbon::parse($date)->format('jS F'),
              'status' => 'ongoing'
          ];
      }

          return response()->json($result);
          }

    else{
      return response(['message'=>'token is expired']);
    }
}


public function canceledOrders(Request $request){
      if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){

        $user = $request->user();
        $orders = $user->orders()
        ->with('product') 
        ->where('status', 'canceled')
        ->get()
        ->groupBy('order_date');

        $result = [];

        foreach ($orders as $date => $groupedOrders) {
            $result[] = [
                'products' => $groupedOrders->pluck('product.name')->filter()->unique()->values(),
                'date' => Carbon::parse($date)->format('jS F'),
                'status' => 'canceled'
            ];
        }

        return response()->json($result);
        }

    else{
    return response(['message'=>'token is expired']);
    }
}


    public function cancelOrder(Request $request){
        if(!PersonalAccessToken::findToken($request->bearerToken())->isExpired()){


           $user = $request->user();
           $request->validate([
            'order_id'=>'required',
           ]);

           $order = Order::findOrFail($request->order_id);
           if($order){

            $order->update(['status'=>'canceled']);
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
}