<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\ReturnModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReturnsAPI extends Controller
{
        public function createReturnPurchase(Request $request){
        try{
            $data = $request->validate([
                'seller_id'=>['required','integer','exists:sellers,id'],
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.quantity'=>['required','numeric','min:1'],
                'products.*.purchase_price'=>['required','numeric','min:1'],
                'total' => 'required|numeric|min:1',
            ]);



            foreach($request->products as $item){ 
                $product = Product::findOrFail($item['product_id']);
                if($product->stock < $item['quantity']){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.stcok_failed'),
                    ],200);            
                }
           
            }

            $returnProduct = ReturnModel::create([
                'seller_id' => $request->seller_id,
                'total' => $request->total,
            ]);

            foreach($request->products as $item){
                $product = Product::findOrFail($item['product_id']);

                $product->stock -= $item['quantity'];
                $product->save();
                if ($product->stock === 0) {
                    $closeout = $product->closeout;

                    if ($closeout) { // check if it exists
                        $closeout->status = 'archived'; 
                        $closeout->save();
                    }
                }
             
                PurchaseReturn::create([
                    'return_id' => $returnProduct->id,
                    'product_id' => $product->id,
                    'quantity'=> $item['quantity'],
                    'price' => $item['purchase_price'],
                ]);
                
            }



            Logs::createLog('انشاء مردودات مشتريات','انشاء مردودات مشتريات للتاجر'.' '
            .$returnProduct->seller->name.' '.'بقيمة'.' '.$returnProduct->total,'returns');

            return response()->json([
                'status'=>'success',
                'message'=> __('messages.return_products_added'),
            ],200);
            
        }

             catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }


        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }


    private function getReturns($status){
        try{

            $returnProducts = ReturnModel::where('status',$status)
            ->with('seller:id,name')
            ->with('items')
            ->get();

            foreach($returnProducts as $returnProduct){
                foreach($returnProduct->items as $item){
                    $item->product_name = $item->product->nameAr;
                    $item->unsetRelation('product');
                }
                
            }

            return response()->json([
                'status'=>'success',
                'return_products'=> $returnProducts,
            ],200);

        }

        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function getPendingReturns(){
        return $this->getReturns('pending');
    }

    public function getDeliveredReturns(){
        return $this->getReturns('delivered');
    }

    public function changeToDelivered(Request $request){
        try{

            $request->validate([
                'return_purchase_id'=>'required|integer|exists:returns,id',
            ]);
            $returnProduct = ReturnModel::findOrFail($request->return_purchase_id);
            $returnProduct->update(['status'=>'delivered']);


            Debt::create([
                'seller_id'=> $returnProduct->seller_id,
                'type' => 'owed to us',
                'total' => $returnProduct->total,
                'return_id' => $returnProduct->id,
            ]);

            Logs::createLog('تسليم مردودات مشتريات','تسم تسليم مردودات مشتريات للتاجر'.' '
            .$returnProduct->seller->name.' '.'بقيمة'.' '.$returnProduct->total,'returns');
            Logs::createLog(
                    'اضافة دين لنا بعد انشاء مردودات مشتريات',
                    ' تمت اضافة دين لنا على رصيد ديون التاجر'.' '.$returnProduct->seller->name
                    .' '.' بقيمة'.' '.$returnProduct->total,
                    'debts'
                );

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.return_delivered'),
            ],200);
        }

                  catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }

        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    
}
