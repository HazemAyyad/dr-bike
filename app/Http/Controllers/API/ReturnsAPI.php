<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReturnModel;
use App\Services\PurchaseAccountService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReturnsAPI extends Controller
{
    public function __construct(private PurchaseAccountService $purchaseAccounts)
    {
    }

        public function createReturnPurchase(Request $request){
        try{
            $data = $request->validate([
                'seller_id'=>['nullable','integer','exists:sellers,id'],
                'customer_id'=>['nullable','integer','exists:customers,id'],
                'bill_id'=>['nullable','integer','exists:bills,id'],
                'currency'=>['nullable','string'],
                'resolution'=>['nullable','string','in:supplier_credit,cash_refund'],
                'refund_box_id'=>['nullable','integer','exists:boxes,id'],
                'note'=>['nullable','string'],
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.bill_item_id'=>['nullable','integer','exists:bill_items,id'],
                'products.*.size_id'=>['nullable','integer'],
                'products.*.size_color_id'=>['nullable','integer'],
                'products.*.quantity'=>['required','numeric','min:1'],
                'products.*.purchase_price'=>['required','numeric','min:1'],
                'products.*.note'=>['nullable','string'],
                'total' => 'nullable|numeric|min:1',
            ]);

            $returnProduct = $this->purchaseAccounts->createPurchaseReturn($data, auth()->id());
            Logs::createLog('انشاء مردودات مشتريات','انشاء مردودات مشتريات للتاجر'.' '
            .($returnProduct->seller?->name ?? $returnProduct->customer?->name ?? '').' '.'بقيمة'.' '.$returnProduct->total,'returns');

            return response()->json([
                'status'=>'success',
                'message'=> __('messages.return_products_added'),
                'return_purchase' => $returnProduct,
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
            ->with('customer:id,name')
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
                'refund_box_id'=>'nullable|integer|exists:boxes,id',
            ]);
            $returnProduct = ReturnModel::findOrFail($request->return_purchase_id);
            $returnProduct = $this->purchaseAccounts->deliverPurchaseReturn($returnProduct, $request->refund_box_id, auth()->id());

            Logs::createLog('تسليم مردودات مشتريات','تسم تسليم مردودات مشتريات للتاجر'.' '
            .($returnProduct->seller?->name ?? $returnProduct->customer?->name ?? '').' '.'بقيمة'.' '.$returnProduct->total,'returns');

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.return_delivered'),
                'return_purchase' => $returnProduct,
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
