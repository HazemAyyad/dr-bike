<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Seller;
use App\Models\IncomingCheck;
use App\Models\IncomingCheckBox;
use App\Models\OutgoingCheck;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class IncomingChecks extends Controller
{


   public static function handleImages(Request $request, array $data, array $fields, $existing = null): array
    {
        foreach ($fields as $field => $path) {
            // //remove if not sent
            // if ($request->has($field) && $request->input($field) === null) {
            //         if ($existing && $existing->$field && file_exists(public_path("$path/" . $existing->$field))) {
            //             unlink(public_path("$path/" . $existing->$field));
            //         }
            //         $data[$field] = null;
            //         continue;
            //     }
            if(is_string($request->input($field))){
                $data[$field] = basename($request->input($field));
            }
            if ($request->hasFile($field)) {
                // Delete old image if editing
                if ($existing && $existing->$field && file_exists(public_path("$path/" . $existing->$field))) {
                    unlink(public_path("$path/" . $existing->$field));
                }

                $file = $request->file($field);
                $name = $file->getClientOriginalName();
                $file->move(public_path($path), $name);

                $data[$field] = $name;
            }
        }

        return $data;
    }


public function store(Request $request)
{
    try {
        $data = $request->validate([
            'from_customer' => 'nullable|exists:customers,id',
            'from_seller'   => 'nullable|exists:sellers,id',
            'total'         => 'required|numeric|min:1',
            'due_date'      => 'required|date',
            'currency'      => 'required|string',
            'check_id'      => 'required|string',
            'bank_name'     => 'required|string',
            'front_image'   => 'nullable|image',
            'back_image'    => 'nullable|image',
            'notes' => 'nullable|string',
        ]);

        // Ensure either from_customer or from_seller is provided, not both or neither
        if (!$request->filled('from_customer') && !$request->filled('from_seller')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('from_customer') && $request->filled('from_seller')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }
       $currencyService = new \App\Services\CurrencyService();

       $amountInShekel = $currencyService->convertToShekel($request->total, $request->currency);

        $data = $this->handleImages($request, $data, [
            'front_image' => 'IncomingCheckImages/front',
            'back_image'  => 'IncomingCheckImages/back',
        ]);



        $check = IncomingCheck::create($data);


        $debt =Debt::create([
            'customer_id' => $request->from_customer ?? null,
            'seller_id'   => $request->from_seller ?? null,
            'total'       => $amountInShekel, // always in شيكل
            'type'        => 'we owe',
        ]);


        Logs::createLog(
            'إضافة شيك وارد',
            'تمت إضافة شيك وارد بقيمة ' . $check->total.' '.$check->currency,
            'incoming_checks'
        );
        $personName = $check->from_customer? $check->fromCustomer->name : $check->fromSeller->name;
        Logs::createLog(
                    'اضافة دين علينا من شيك وارد',
                    ' تمت اضافة دين علينا إلى رصيد ديون الشخص'.' '.$personName
                    .' '.' بقيمة'.' '.$debt->total,
                    'debts'
                ); 

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.incoming_check_created_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()
        ], 200);
    } catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.create_data_error'),
            'msg'=>$e->getMessage(),
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),
                        'msg'=>$e->getMessage(),

        ], 200);
    }
}


    private function commonData($status){
        try{
            $checks = IncomingCheck::where('status',$status)
            ->with('fromCustomer:id,name')
            ->with('fromSeller:id,name')

            ->with('toCustomer:id,name')
            ->with('toSeller:id,name')

            ->get();
            return response()->json([
                'status'=>'success',
                'front_checks_images_path' => 'public/IncomingCheckImages/front',
                'back_checks_images_path' => 'public/IncomingCheckImages/back',
                $status.'_'.'checks' => $checks,
                'checks_count' => IncomingCheck::where('status',$status)->count(),
                'checks_total_dollar' => IncomingCheck::where('status',$status)
                ->where('currency','دولار')->sum('total'),
                 'checks_total_shekel' => IncomingCheck::where('status',$status)
                ->where('currency','شيكل')->sum('total'),  
                'checks_total_dinar' => IncomingCheck::where('status',$status)
                ->where('currency','دينار')->sum('total'),               

            ],200);
        }

        catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function notCashedChecks(){
        return $this->commonData('not_cashed');
    }

    public function cashedToPersonChecks(){
        return $this->commonData('cashed_to_person');
    }

    //     public function cancelledChecks(){
    //     return $this->commonData('cancelled');
    // }
    //     public function returnedChecks(){
    //     return $this->commonData('returned');
    // }
    //     public function cashedChecks(){
    //     return $this->commonData('cashed');
    // }

    // public function cashedToBoxChecks(){
    //     return $this->commonData('cashed_to_box');
    // }

    // get them all together in archive section
    public function archive(){
        try{
                $checks = IncomingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_to_box',
                    ])
                    ->with([
                        'fromCustomer:id,name',
                        'fromSeller:id,name',
                        'toCustomer:id,name',
                        'toSeller:id,name',
                    ])
                    ->get();

            return response()->json([
                'status'=>'success',
                'archived_checks' => $checks,

                'front_checks_images_path' => 'public/IncomingCheckImages/front',
                'back_checks_images_path' => 'public/IncomingCheckImages/back',

                'checks_count' => IncomingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_to_box',
                    ])->count(),
                'checks_total_dollar' => IncomingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_to_box',
                    ])->where('currency','دولار')->sum('total'),

                'checks_total_shekel' => IncomingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_to_box',
                    ])->where('currency','شيكل')->sum('total'),

                'checks_total_dinar' => IncomingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_to_box',
                    ])->where('currency','دينار')->sum('total'),                    

            ],200);
        }

        catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
     }

    public function cashCheckToPerson(Request $request){
        try{
        $request->validate([
            'incoming_check_id'=>'required|exists:incoming_checks,id',
            'customer_id'=>'nullable|exists:customers,id',
            'seller_id'=>'nullable|exists:sellers,id',

        ]);

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }
        $incomingCheck = IncomingCheck::findOrFail($request->incoming_check_id);
        $personName =null;
       $currencyService = new \App\Services\CurrencyService();

       $amountInShekel = $currencyService->convertToShekel($incomingCheck->total, $incomingCheck->currency);

        if($request->filled('customer_id')){
            $customer = Customer::findOrFail($request->customer_id);
            $incomingCheck->update([
                'to_customer' => $customer->id,
                'status' =>'cashed_to_person',
            ]);
 
            Debt::create([
                'customer_id' => $customer->id,
                'total' => $amountInShekel,
                'type' => 'owed to us',
            ]);

            $personName = $customer->name;
            Logs::createLog(
                'التصرف في شيك',
                'تم صرف شيك وارد بقيمة ' . $incomingCheck->total.' '.$incomingCheck->currency . ' '.
                ' لصالح ' . $personName . 
                ' وتمت إضافته إلى رصيد ديونه',
                'debts'
            );
            
        }
        elseif($request->filled('seller_id')){
            $seller = Seller::findOrFail($request->seller_id);
            $incomingCheck->update([
                'to_seller' => $seller->id,
                'status' =>'cashed_to_person',
            ]);
            Debt::create([
                'seller_id' => $seller->id,
                'total' => $amountInShekel,
                'type' => 'owed to us',
            ]);
            $personName = $seller->name;
            Logs::createLog(
                'التصرف في شيك',
                'تم صرف شيك وارد بقيمة ' . $incomingCheck->total . ' '.$incomingCheck->currency.' '.
                ' لصالح ' . $personName . 
                ' وتمت إضافته إلى رصيد ديونه ',
                'debts'
            );   
        
        }
        else{
         return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        Logs::createLog(
            'التصرف في شيك',
            'تم التصرف في الشيك الوارد بقيمة ' . $incomingCheck->total .' '.$incomingCheck->currency.' '. ' لصالح ' .' '. $personName,
            'incoming_checks'
        );

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.check_cashed'),
        ], 200);
    }
    catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
                        'msg'=>$e->getMessage(),

        ], 200);
    } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),
                        'msg'=>$e->getMessage(),

        ], 200);
    }
    
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),
                        'msg'=>$e->getMessage(),

        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),
                        'msg'=>$e->getMessage(),

        ], 200);
    }
        

    }

    public function cashCheckToBox(Request $request){
      try{
        $request->validate([
            'incoming_check_id'=>'required|exists:incoming_checks,id',
            'box_id'=>'required|exists:boxes,id',

        ]);


        $incomingCheck = IncomingCheck::findOrFail($request->incoming_check_id);
        $box = Box::findOrFail($request->box_id);

        if($incomingCheck->currency !== $box->currency){
            return response()->json([
                'status'=>'error',
                'message' => __('messages.must_be_same_currency_check'),

            ]);
        }
        $incomingCheckBox = IncomingCheckBox::create([
            'incoming_check_id' => $incomingCheck->id,
            'box_id' => $box->id,
            
        ]);
        $incomingCheck->update(['status'=>'cashed_to_box']);
        $box->update(['total'=> $box->total + $incomingCheck->total]);

        BoxLogs::createBoxLog($box,'تم صرف شيك وارد برقم '.' '.($incomingCheck->check_id??'غير معروف').' '.'للصندوق'
        ,'add',$incomingCheck->total);
            Logs::createLog(
            'صرف شيك وارد لصندوق ',
            'تم صرف الشيك الوارد بقيمة ' . $incomingCheck->total.' '.$incomingCheck->currency.' ' . ' لصالح الصندوق' .' '. $box->name,
            'incoming_checks'
        );

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.check_cashed'),
        ], 200);
    }
    catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
    } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }

    private function changeCheckStatus(Request $request,$status){
            try{
        $request->validate([
            'incoming_check_id'=>'required|exists:incoming_checks,id',
        ]);


       $incomingCheck = IncomingCheck::findOrFail($request->incoming_check_id);
       $currencyService = new \App\Services\CurrencyService();

       $amountInShekel = $currencyService->convertToShekel($incomingCheck->total, $incomingCheck->currency);

        $incomingCheck->update(['status'=>$status]);
        $personName = $incomingCheck->from_customer? $incomingCheck->fromCustomer->name: $incomingCheck->fromSeller->name;

        if($status ==='returned'){
                $firstDebt = Debt::create([
                    'customer_id'=> $incomingCheck->from_customer??null,
                    'seller_id'=> $incomingCheck->from_seller??null,
                    'total' => $amountInShekel,
                    'type' => 'owed to us',
                ]);
                Logs::createLog(
                        'اضافة دين لنا من ارجاع شيك وارد',
                        ' تمت اضافة دين لنا إلى رصيد ديون الشخص'.' '.$personName
                        .' '.' بقيمة'.' '.$firstDebt->total,
                        'debts'
                    ); 
                if($incomingCheck->to_seller || $incomingCheck->to_customer){
                    $secondPersonName = $incomingCheck->to_customer? $incomingCheck->toCustomer->name: $incomingCheck->toSeller->name;
                    $secondDebt = Debt::create([
                        'customer_id'=> $incomingCheck->to_customer??null,
                        'seller_id'=> $incomingCheck->to_seller??null,
                        'total' => $amountInShekel,
                        'type' => 'we owe',
                    ]);
                    Logs::createLog(
                            'اضافة دين علينا من ارجاع شيك وارد بعد التصرف فيه',
                            ' تمت اضافة دين علينا إلى رصيد ديون الشخص'.' '.$secondPersonName
                            .' '.' بقيمة'.' '.$secondDebt->total,
                            'debts'
                        );                    
                }

            // $boxCheck = IncomingCheckBox::where('incoming_check_id',$incomingCheck->id)->first();
            // if($boxCheck){
            //     $box = $boxCheck->box;
            //     $box->total -= $incomingCheck->total;
            //     $box->save();
            //         Logs::createLog(
            //                 'سحب رصيد صندوق',
            //                 ' تم سحب رصيد من الصندوق'.' '.$box->name
            //                 .' '.' بقيمة'.' '.$incomingCheck->total,
            //                 'boxes'
            //             );                 
            // }

        }

        elseif($status==='cancelled'){
                $debtCancel = Debt::create([
                    'customer_id'=> $incomingCheck->from_customer??null,
                    'seller_id'=> $incomingCheck->from_seller??null,
                    'total' => $amountInShekel,
                    'type' => 'owed to us',
                ]);
                Logs::createLog(
                        'اضافة دين لنا من الغاء شيك وارد',
                        ' تمت اضافة دين لنا إلى رصيد ديون الشخص'.' '.$personName
                        .' '.' بقيمة'.' '.$debtCancel->total,
                        'debts'
                    );             
        }

         Logs::createLog(
            'تغيير حالة شيك',
            'تم تغيير حالة الشيك الوارد بقيمة ' .' '. $incomingCheck->total.' ',$incomingCheck->currency.' ' . ' إلى ' .' '. $status,
            'incoming_checks'
        );

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.check_'.$status),
        ], 200);
    }
    catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
    } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }


       // delete check
    public function deleteCheck(Request $request){
        try{

            $request->validate(['incoming_check_id'=>'required|exists:incoming_checks,id',]);

            $incomingCheck = IncomingCheck::findOrFail($request->incoming_check_id);

            $deletableStatuses = ['not_cashed', 'cancelled', 'returned','cashed_to_box'];

            if (! in_array($incomingCheck->status, $deletableStatuses)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cannot_delete_check'),
                ], 200);
            }
                $currencyService = new \App\Services\CurrencyService();

                $amountInShekel = $currencyService->convertToShekel($incomingCheck->total, $incomingCheck->currency);
                $personName = $incomingCheck->from_customer? $incomingCheck->fromCustomer->name: $incomingCheck->fromSeller->name;

            if($incomingCheck->status==='not_cashed' || $incomingCheck->status==='cashed_to_box'){
                $firstDebt = Debt::create([
                    'customer_id'=> $incomingCheck->from_customer??null,
                    'seller_id'=> $incomingCheck->from_seller??null,
                    'total' => $amountInShekel,
                    'type' => 'owed to us',
                ]);
                Logs::createLog(
                        'اضافة دين لنا من حذف شيك وارد',
                        ' تمت اضافة دين لنا إلى رصيد ديون الشخص'.' '.$personName
                        .' '.' بقيمة'.' '.$firstDebt->total,
                        'debts'
                    ); 

                if($incomingCheck->status==='cashed_to_box'){
                    $boxCheck = IncomingCheckBox::where('incoming_check_id',$incomingCheck->id)->first();
                    if($boxCheck){
                        $box = $boxCheck->box;
                        $box->total -= $incomingCheck->total;
                        $box->save();
                        $boxCheck->delete();
                            Logs::createLog(
                                    'سحب رصيد صندوق بعد حذف الشيك',
                                    ' تم سحب رصيد من الصندوق'.' '.$box->name
                                    .' '.' بقيمة'.' '.$incomingCheck->total,
                                    'boxes'
                                );                 
                    }
                }

            }


                Logs::createLog('حذف شيك وارد','تم حذف شيك وارد بقيمة'.' '.$incomingCheck->total.' '. $incomingCheck->currency,'incoming_checks');

                $incomingCheck->delete();
                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.check_deleted'),
                ], 200);


        }
    

        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);

        } 
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        }
    }

    public function cancelCheck(Request $request){

        return $this->changeCheckStatus($request,'cancelled');
    }

    public function returnCheck(Request $request){

        return $this->changeCheckStatus($request,'returned');
    }

    // public function cashCheck(Request $request){

    //     return $this->changeCheckStatus($request,'cashed');
    // }

    public function generalIncomingChecksData(){
        try{
            $data = [
                'incoming_checks_count' => IncomingCheck::incomingChecksCount(),
                'total_incoming_checks' => IncomingCheck::totalAmount(),

                
            ];
            return response()->json([
                'status'=>'success',
                'data' => $data,
            ],200);
        }

            catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    }
    }

    public function showCheck(Request $request){
        try{

       $request->validate([
        'incoming_check_id' => 'nullable|integer|exists:incoming_checks,id',
        'outgoing_check_id' => 'nullable|integer|exists:outgoing_checks,id',
       ]);

       if (!$request->filled('incoming_check_id') && !$request->filled('outgoing_check_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.must_select_incoming_or_outgoing')
            ], 200);
        }

        if ($request->filled('incoming_check_id') && $request->filled('outgoing_check_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.must_select_either_incoming_or_outgoing')
            ], 200);
        }

    $check = null;
    if($request->incoming_check_id) {
            $check = IncomingCheck::
            with('fromCustomer:id,name')->
            with('fromSeller:id,name')->
            with('toCustomer:id,name')->
            with('toSeller:id,name')->
            findOrFail($request->incoming_check_id);

            $check['front_image'] = $check->front_image?
            'public/IncomingCheckImages/front/'.$check->front_image
            :'no image';

            $check['back_image'] = $check->back_image?
            'public/IncomingCheckImages/back/'.$check->back_image
            :'no image';

            $check->makeHidden(['from_customer','from_seller','to_customer','to_seller']);
    }
    else{
        $check = OutgoingCheck::with('customer:id,name')->with('seller:id,name')
        ->findOrFail($request->outgoing_check_id);

        $check['img'] = $check->img? 'public/OutgoingChecksImages/'.$check->img:'no image';
        $check->makeHidden(['customer_id','seller_id']);

    }
       return response()->json([
        'status'=>'success',
        'check' => $check,
       ],200);
       
    }
     catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
     } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
     catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }

    public function editCheck(Request $request){
      try{

            $data = $request->validate([
                'incoming_check_id' => 'required|integer|exists:incoming_checks,id',
                'due_date' => 'required|date',
                'check_id' => 'required|string',
                'bank_name' => 'required|string',
                'front_image'   => 'nullable',
                'back_image'    => 'nullable',
                'notes' => 'nullable|string',
          
            ]);

            $incomingCheck = IncomingCheck::
            findOrFail($request->incoming_check_id);

            $data = $this->handleImages($request, $data, [
                'front_image' => 'IncomingCheckImages/front',
                'back_image'  => 'IncomingCheckImages/back',
            ], $incomingCheck);
    
           $incomingCheck->update($data);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.check_updated'),
            ],200);
            
    }


     catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()

        ], 200);
     } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
     catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }

}
