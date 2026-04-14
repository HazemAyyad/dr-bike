<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillQuantity;
use App\Models\Debt;
use App\Models\Product;
use App\Models\PurchaseProduct;
use App\Services\StoreManageItemService;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Bills extends Controller
{

        //DONE
    public function createBill(Request $request){
        try{
            $data = $request->validate([
                'seller_id'=>['required','integer','exists:sellers,id'],
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.quantity'=>['required','numeric','min:1'],
                'products.*.purchase_price'=>['required','numeric','min:1'],

                'total'=>'required|numeric|min:1',
            ]);
            $bill = Bill::create([
                'seller_id' => $request->seller_id,
                'total' => $request->total

            ]);
            $storeSyncWarnings = [];
            foreach($request->products as $item){
                $product = Product::findOrFail($item['product_id']);

                $product->stock += $item['quantity'];
                $product->save();


                $purchasePro = PurchaseProduct::where('seller_id',$request->seller_id)
                ->where('product_id',$item['product_id'])->first();
                if($purchasePro){
                    $purchasePro->update(['price'=>$item['purchase_price']]);
                }
                else{
                PurchaseProduct::create([
                    'product_id'=> $product->id,
                    'seller_id' => $request->seller_id,
                    'price' => $item['purchase_price'],
                ]); }


                BillItem::create([
                    'bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'quantity'=> $item['quantity'],
                    'price' => $item['purchase_price'],
                ]);

                $sync = app(StoreManageItemService::class)->syncProductStockToStore($product->fresh());
                if (! ($sync['ok'] ?? false)) {
                    $storeSyncWarnings[] = ($sync['error'] ?? __('messages.something_wrong')).' (منتج '.$product->id.')';
                }
                
            }




            Logs::createLog('انشاء فاتورة جديدة','انشاء فاتورة جديدة للتاجر'.' '
            .$bill->seller->name.' '.'بقيمة'.' '.$bill->total,'bills');

            $payload = [
                'status'=>'success',
                'message'=> __('messages.bill_added'),
            ];
            if (count($storeSyncWarnings) > 0) {
                $payload['store_sync_warnings'] = $storeSyncWarnings;
            }

            return response()->json($payload,200);
            
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

private function getBills($statuses)
{
    try {
        $bills = Bill::whereIn('status', (array) $statuses) 
            ->with('seller:id,name')
            ->get(['id','total','created_at','seller_id','status'])
            ->map(function($bill) {
                return [
                    'id'         => $bill->id,
                    'total'      => $bill->total,
                    'seller'     => $bill->seller? $bill->seller->name : 'no seller',
                    'created_at' => $bill->created_at->format('Y-m-d'), 
                    'status' => $bill->status, 
                
                ];
            });

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
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



    public function getBillDetails(Request $request){
        try{

            $request->validate([
                'bill_id'=>'required|integer|exists:bills,id'
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $items = $bill->items;

            $productsFormatted =  $items->map( function ($item) use ($bill){
                $image = $item->product->normalImages->first();

                return [
                        'bill_id' => $bill->id,
                        'product_id' => $item->product->id,
                        'product_name'=> $item->product->nameAr,
                        'product_image' => $image ? $image->imageUrl : 'no image',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'product_status' => $item->status,
                        'sub_total' => $item->quantity * $item->price,
                        'extra_amount' => $item->extra_amount?? null,
                        'missing_amount' => $item->missing_amount?? null,
                        'not_compatible_amount' => $item->not_compatible_amount?? null,
                    ];
                } );
            $formatted =  [
                'bill_id' => $bill->id,
                'products'=> $productsFormatted,
                'seller_id' => $bill->seller_id,
                'seller_name' => $bill->seller->name,
                'created_at' => $bill->created_at->format('d M Y'), 
                'total_bill' => $bill->total,
            ];

            return response()->json([
                'status'=>'success',
                'bill_details'=> $formatted,
            ],200);

        }


        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.retrieve_data_error'),
                ],200);
            }
        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                ],200);
            }


        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.retrieve_data_error'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }


    public function createBillQuantity(Request $request){
                try{
            $data = $request->validate([
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.quantity'=>['required','integer','min:1'],

            ]);

                $storeSyncWarnings = [];
                foreach($request->products as $item){
                    $product = Product::findOrFail($item['product_id']);
                    $product->update(['stock'=> $product->stock+ $item['quantity']]);

                    BillQuantity::create([
                        'product_id'=> $product->id,
                        'quantity' => $item['quantity'],
                    ]);

                    $sync = app(StoreManageItemService::class)->syncProductStockToStore($product->fresh());
                    if (! ($sync['ok'] ?? false)) {
                        $storeSyncWarnings[] = ($sync['error'] ?? __('messages.something_wrong')).' (منتج '.$product->id.')';
                    }

                }

                $payload = [
                'status'=>'success',
                'message'=> __('messages.bill_quantity_added'),
            ];
                if (count($storeSyncWarnings) > 0) {
                    $payload['store_sync_warnings'] = $storeSyncWarnings;
                }

                return response()->json($payload,200);
            
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

    // *********************BILL STATUS ***********************
    // غير معالجة
   
    // bills that have at least one unfinished item (item with no status yet)
  //DONE
    public function getUnfinishedBills(){
       // return $this->getBills('unfinished');
       try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->where('status', 'unfinished');
                })        
            ->with('seller:id,name')
            ->get(['id','total','created_at','seller_id','status'])
            ->map(function($bill) {
                return [
                    'id'         => $bill->id,
                    'total'      => $bill->total,
                    'seller'     => $bill->seller? $bill->seller->name : 'no seller',
                    'created_at' => $bill->created_at->format('Y-m-d'), 
                    'status' => $bill->status, 
                
                ];
            });

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
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

    public function getFinishedBills(){
        return $this->getBills('finished');
    }

    // if there's missing values for at least one item and bill isn't finished yet and all items have status
    public function getUnmatchedBills(){
     try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->whereNotNull('missing_amount');
                })  
        ->whereDoesntHave('items', function ($q) {
                        // exclude bills with any unfinished items
                        $q->where('status', 'unfinished');
                    })      
           ->where('status','!=','finished')
            ->with('seller:id,name')
            ->get(['id','total','created_at','seller_id','status'])
            ->map(function($bill) {
                return [
                    'id'         => $bill->id,
                    'total'      => $bill->total,
                    'seller'     => $bill->seller? $bill->seller->name : 'no seller',
                    'created_at' => $bill->created_at->format('Y-m-d'), 
                    'status' => $bill->status, 
                
                ];
            });

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
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

    public function getSecuritiesBills(){
   try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->whereIn('status', ['extra', 'not_compatible']);

                })  
        ->whereDoesntHave('items', function ($q) {
                        // exclude bills with any unfinished items
                        $q->where('status', 'unfinished');
                    })      
           ->where('status','!=','finished')
            ->with('seller:id,name')
            ->get(['id','total','created_at','seller_id','status'])
            ->map(function($bill) {
                return [
                    'id'         => $bill->id,
                    'total'      => $bill->total,
                    'seller'     => $bill->seller? $bill->seller->name : 'no seller',
                    'created_at' => $bill->created_at->format('Y-m-d'), 
                    'status' => $bill->status, 
                
                ];
            });

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
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
    // cancelled + completed
    public function getArchivedBills(){
    return $this->getBills(['cancelled', 'finished']); 
    }


        public function changeProductStatus(Request $request){
        try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',
                'status' => 'required|string|in:finished,missing,extra,not_compatible',
                'extra_amount' =>'required_if:status,extra|nullable|integer|min:1',
                'missing_amount' =>'required_if:status,missing|nullable|integer|min:1',
                'not_compatible_amount' => 'required_if:status,not_compatible|nullable|integer|min:1' ,
                'not_compatible_description'=> 'required_if:status,not_compatible|nullable|string',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $billItem = BillItem::where('bill_id',$bill->id)
            ->where('product_id',$request->product_id)
            ->first();
            if($billItem->status !== 'unfinished'){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.can_only_change_status_once'),
                ],200);
            }

            if($request->status === 'finished'){
                $billItem->update(['status'=>'finished']);

                $this->changeProductStatusToFinished($billItem, $request->bill_id);
            }
            elseif($request->status === 'missing'){

                if($request->missing_amount > $billItem->quantity){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.entered_amount_bigger_than_quantity'),
                    ],200);
                }
                $billItem->update(['missing_amount'=> $request->missing_amount]);


                $amountToReduce = $request->missing_amount * $billItem->price;
                $bill->total -= $amountToReduce;
                $bill->save();

               $billItem->product()->decrement('stock', $request->missing_amount);


                $billItem->update(['status'=>'finished']);
                $this->changeProductStatusToFinished($billItem, $request->bill_id);

            }

            elseif($request->status === 'not_compatible'){
                if($request->not_compatible_amount > $billItem->quantity){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.entered_amount_bigger_than_quantity'),
                    ],200);
                }
                $billItem->update([
                    'not_compatible_amount'=>$request->not_compatible_amount,
                    'not_compatible_description' => $request->not_compatible_description,
                    'status' => 'not_compatible',
                ]);



                $amountToReduce = $request->not_compatible_amount * $billItem->price;
                $bill->total -= $amountToReduce;
                $bill->save();
         
                $product = $billItem->product;
                $product->stock -= $request->not_compatible_amount;
                $product->save();

            }

            elseif($request->status==='extra'){

                $billItem->update([
                    'extra_amount'=> $request->extra_amount,
                    'status' =>'extra',
                ]);
            }

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.product_status_updated'),
            ],200);


        }

        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
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



    private function changeProductStatusToFinished(BillItem $billItem,$billId){
        


            $exists = BillItem::where('bill_id',$billId)
            ->whereNotIn('status',['finished'])
            ->exists();
            if(!$exists){
                $billItem->bill->update(['status'=>'finished']);
                Logs::createLog('اكتمال فاتورة ','تم اكتمال فاتورة  للتاجر'.' '
                .$billItem->bill->seller->name.' '.'بقيمة'.' '.$billItem->bill->total,'bills');

                Debt::create([
                'seller_id'=> $billItem->bill->seller_id,
                'type' => 'we owe',
                'total' => $billItem->bill->total,
                'bill_id' => $billItem->bill->id,
            ]);

                Logs::createLog(
                    'اضافة دين علينا للتاجر بعد اكتمال الفاتورة',
                    ' تمت اضافة دين علينا إلى رصيد ديون التاجر'.' '.$billItem->bill->seller->name
                    .' '.' بقيمة'.' '.$billItem->bill->total,
                    'debts'
                ); 

            }


        }

        


        public function purchaseExtraProducts(Request $request){
        try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',

            ]);

            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)
            ->first();

            if($billItem->status !== 'extra'){
                return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.must_be_status_extra'),
                    ],200);
            }


            $bill = Bill::findOrFail($request->bill_id);



            $amountToAdd = $billItem->extra_amount * $billItem->price;
            $bill->total += $amountToAdd;
            $bill->save();


            $product = Product::findOrFail($billItem->product_id);
            $product->stock += $billItem->extra_amount;
            $product->save();

            $billItem->update(['status'=>'finished']);
            $this->changeProductStatusToFinished($billItem, $request->bill_id);
            

            return response()->json([
                'status'=>'success',
                'message' =>__('messages.product_extra_was_purchased'),
            ],200);
          
            


        }

        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
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

    public function deliverOneProduct(Request $request){
        try{
            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',

            ]);

            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)
            ->first();
            if($billItem->status ==='extra' || $billItem->status === 'not_compatible'){

                $billItem->update(['status'=>'finished']);

                $this->changeProductStatusToFinished($billItem, $request->bill_id);
              
                return response()->json([
                    'status'=>'success',
                    'message'=>__('messages.product_was_delivered'),
                ],200);


            }

            else{
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.product_extra_or_not_compatible'),
                ],200);
            }
        }
            catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
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

    // for not compatible second option
        public function purchaseProdcutsNewPrice(Request $request){
             try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id' =>'required|integer|exists:bill_items,product_id',
                'price' => 'required|numeric|min:1',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)->first();

            if($billItem->status !== 'not_compatible'){
                return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.must_be_status_not_compatible'),
                    ],200);
            }

 

            $billItem->update([
               // 'price' => $request->price,
                'status' => 'finished',
                'price' => $request->price,
            ]);

            $bill->total += $request->price * $billItem->quantity;
            $bill->save();

            $this->changeProductStatusToFinished($billItem, $request->bill_id);


            return response()->json([
                'status'=>'success',
                'message' =>__('messages.bill_was_delivered'),
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

    public function cancelBill(Request $request){
        try{

            $request->validate([
                'bill_id'=>'required|integer|exists:bills,id',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $bill->update(['status'=>'cancelled']);
            $bill->items()->update(['status'=>'cancelled']);



            foreach($bill->items as $item){
                $item->product->update(['stock' => $item->product->stock - $item->quantity ]);
                    PurchaseProduct::where('seller_id',$bill->seller_id)
                    ->where('product_id',$item->product_id)->delete();
            }

            Logs::createLog('ارجاع فاتورة ','تم ارجاع فاتورة  للتاجر'.' '
            .$bill->seller->name.' '.'بقيمة'.' '.$bill->total,'bills');


            return response()->json([
                'status'=>'success',
                'message'=>__('messages.bill_cancelled'),
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

    // deliver the whole bill at one
    // public function deliverBill(Request $request){
    //          try{

    //         $request->validate([
    //             'bill_id'=> 'required|integer|exists:bills,id',

    //         ]);

    //         $bill = Bill::findOrFail($request->bill_id);


    //         $bill->update(['status'=>'finished']);
    //         $bill->items()->update(['status'=>'finished']);

    //         Logs::createLog('تسليم فاتورة ','تم تسليم فاتورة  للتاجر'.' '
    //         .$bill->seller->name.' '.'بقيمة'.' '.$bill->total,'bills');

    //         return response()->json([
    //             'status'=>'success',
    //             'message' =>__('messages.bill_was_delivered'),
    //         ],200);
          
            


    //     }

    //     catch(ValidationException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.validation_failed'),
    //             ],200);
    //         }

    //     catch(ModelNotFoundException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.something_wrong'),
    //             ],200);
    //         }
    //     catch(QueryException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.something_wrong'),
    //             ],200);
    //         }
            

    //         catch (\Exception $e) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => __('messages.something_wrong'),
    //             ], 200);
    //         }
    // }


    // download bill as pdf
    public function downloadBill(Request $request){
    try {
                $request->validate([
                    'bill_id' => 
                        'required',
                        'integer','exists:bills,id'
 
                    ]);


        $bill = Bill::findOrFail($request->bill_id);
   
       // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.bill_report', [
            'bill' => $bill,
        ])->render();

        // 🔹 Fix Arabic text
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(
                substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
            );
            $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }

        // 🔹 Load fixed HTML into PDF
        $pdf = Pdf::loadHTML($reportHtml);

        return $pdf->download('bill_report.pdf');

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),

        ], 200);
    }  catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }
    

}