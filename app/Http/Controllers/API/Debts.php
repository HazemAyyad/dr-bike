<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtFormatted;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Seller;
use Barryvdh\DomPDF\Facade\Pdf;
use ArPHP\I18N\Arabic;

use App\Models\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Debts extends Controller
{
    public function store(Request $request)
{
    try{
    $data = $request->validate([
        'customer_id'    => 'nullable|exists:customers,id',
        'seller_id'    => 'nullable|exists:sellers,id',

        'type'           => 'required|string|in:we owe,owed to us',
        'due_date'       => 'required|date',
        'total'          => 'required|numeric|min:1',

        'receipt_image'  => 'nullable|array',

        'receipt_image.*'  => 'required|image',
        'notes'          => 'nullable|string',
        'box_id' =>'required|integer|exists:boxes,id',
    ]);

            // Ensure either from_customer or from_seller is provided, not both or neither
        if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

    $box = Box::findOrFail($request->box_id);
    if($box->currency!=='شيكل'){
        return response()->json([
            'status'=>'error',
            'message'=>__('messages.currency_shekel'),
        ],200);
    }

    if($request->type==='we owe' && $box->total < $request->total){
            return response()->json([
            'status'=>'error',
            'message'=>__('messages.box_out_of_money'),
        ],200);
    }

    $debtTypeInArabic = 'دين علينا ';
    if($request->type==='owed to us'){
        $debtTypeInArabic = 'دين لنا ';
    }
    // Handle file upload
    $imageNames = [];
    if ($request->hasFile('receipt_image')) {
        foreach($request->file('receipt_image') as $image){
        $imageName = $image->getClientOriginalName();
        $image->move(public_path('DebtsReceipts'), $imageName);
        $imageNames[] = $imageName;
    }
}


    if($request->filled('customer_id')){
    $debt = Debt::create([
        'customer_id'   => $request->customer_id,
        'type'          => $request->type,
        'due_date'      => $request->due_date,
        'total'         => $request->total,
        'receipt_image' => $imageNames,  
        'notes'         => $request->notes,
    ]);

        Logs::createLog('اضافة دين جديد',' اضافة'.' '.$debtTypeInArabic.' '.' للزبون'.' '
    .$debt->customer->name.' '.'بقيمة'.' '. $debt->total
    ,'debts');
}

    elseif($request->filled('seller_id')){
    $debt = Debt::create([
        'seller_id'   => $request->seller_id,
        'type'          => $request->type,
        'due_date'      => $request->due_date,
        'total'         => $request->total,
        'receipt_image' => $imageNames,  
        'notes'         => $request->notes,
    ]);


        Logs::createLog('اضافة دين جديد',' اضافة'.' '.$debtTypeInArabic.' '.' للتاجر'.' '
    .$debt->seller->name.' '.'بقيمة'.' '. $debt->total
    ,'debts');
   }

    if($debt->type==='we owe'){
            $box->update([
                'total' => $box->total+ $debt->total,
            ]);
            BoxLogs::createBoxLog($box,'تم اخذ دين من الشخص '.' '.($debt->customer_id? $debt->customer->name:$debt->seller->name).' '.'من الصندوق'
            ,'add',$debt->total);
     }
     else{
             $box->update([
                'total' => $box->total- $debt->total,
            ]);
            BoxLogs::createBoxLog($box,'تم اعطاء دين  للشخص '.' '.($debt->customer_id? $debt->customer->name:$debt->seller->name).' '.'لصالح الصندوق'
            ,'minus',$debt->total);       
     }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.debt_created_successfully')
            ], 200);
      }
      catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_create_debt')
            ], 200);
        }

}

    public function showDebt(Request $request){
        try{
            $request->validate(['debt_id'=>'required|exists:debts,id']);

            $debt = Debt::
            with('customer:id,name,is_canceled')
            ->with('seller:id,name,is_canceled')
            ->findOrFail($request->debt_id);

            
                $data = [
                    'customer_name' => $debt->customer_id ? $debt->customer->name:null,
                    'customer_is_canceled' => $debt->customer_id ? $debt->customer->is_canceled:null,
                    'seller_name' => $debt->seller_id? $debt->seller->name:null,
                    'seller_is_canceled' => $debt->seller_id?$debt->seller->is_canceled:null,

                    'debt_type' => $debt->type,
                    'due_date' => $debt->due_date?? null,
                    'debt_total' => $debt->total,
                    'notes' => $debt->notes?? 'no notes',
                    'status' => $debt->status,
                    'receipt_image' =>
                     $debt->receipt_image?
                    collect($debt->receipt_image)->map(fn($img) => 'public/DebtsReceipts/'.$img)->toArray()
                    : 'no images',

                ];

            return response()->json([
                'status' => 'success',
                'debt' => $data
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.debt_not_found')
            ], 200);
        } 
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_debt')
            ], 200);
         } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
      }
    
    public function editDebt(Request $request){
     try{
        $data = $request->validate([
            'debt_id' => 'required|exists:debts,id',
            'total' => 'required|numeric|min:1',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:paid,unpaid',
            'receipt_image'  => 'nullable|array',

            'receipt_image.*'  => 'nullable',

            
        ]);
        $debt = Debt::findOrFail($request->debt_id);

        $images = CommonUse::handleImageUpdate($request,'receipt_image','DebtsReceipts',$debt->receipt_image);
        $data['receipt_image'] = $images;
        $debt->update($data);
        $statusInArabic = 'غير مدفوع';
        if($debt->status ==='paid'){ $statusInArabic = 'مدفوع' ;}

        if($debt->customer_id){
            Logs::createLog('تعديل بيانات دين ','تعديل بيانات دين للزبون'.' '.$debt->customer->name
            
            .' '.'حالة الدين'.' '.$statusInArabic.' '.'بقيمة'.' '.$debt->total
            ,'debts');
        }
        elseif($debt->seller_id){
            Logs::createLog('تعديل بيانات دين ','تعديل بيانات دين للتاجر'.' '.$debt->seller->name
            
            .' '.'حالة الدين'.' '.$statusInArabic.' '.'بقيمة'.' '.$debt->total
            ,'debts');
        }

         return response()->json([
                'status' => 'success',
                'message' => __('messages.debt_updated_successfully')
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()

            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.debt_not_found')
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.update_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_update_debt')
            ], 200);
        }
    }

    // get total of the debts owed to us
    public function getDebtsOwedToUsTotal(){
        try{
        $total = Debt::where('type','owed to us')
        ->where('status','unpaid')
        ->sum('total');

            return response()->json([
                'status' => 'success',
                'total_debts_owed_to_us' => $total
            ], 200);

        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    // get total of the debts we owe
    public function getDebtsWeOweTotal(){
        try{
            $total = Debt::where('type','we owe')
            ->where('status','unpaid')
            ->sum('total');
           return response()->json([
                'status' => 'success',
                'total_debts_we_owe' => $total
            ], 200);

        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }   
     }

    private function commonData($type){
    try{
        $debts = Debt::where('type',$type)
        ->with('customer:id,name,is_canceled')
        ->with('seller:id,name,is_canceled')
        ->get();

        $formatted =  new DebtFormatted($debts);
            return response()->json([
                'status' => 'success',
                'debts' => $formatted
            ], 200);
    }
     catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_debts')
            ], 200);
        }
}

    public function getDebtsWeOwe(){
        return $this->commonData('we owe');


    }

    public function getDebtsOwedToUs(){
        return $this->commonData('owed to us');


    }

    // get customer's all debts (owed and owe)
    public function customerDebts(Request $request){
        try{
            $request->validate([
                'customer_id'=>'nullable|exists:customers,id',
                'seller_id'=>'nullable|exists:sellers,id',

            ]);
        // Ensure either from_customer or from_seller is provided, not both or neither
        if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }
        if($request->filled('customer_id')){
            $customer = Customer::findOrFail($request->customer_id);
            $debts = $customer->debts;
        }
        elseif($request->filled('seller_id')){
            $seller = Seller::findOrFail($request->seller_id);
            $debts = $seller->debts;
        }
        
            $owedDebts = $debts->where('type','owed to us')
            ->where('status','unpaid')->sum('total');
            $owedToMeDebts = $debts->where('type','we owe')
            ->where('status','unpaid')->sum('total');

            $customerBalance = $owedToMeDebts - $owedDebts ;
            $customerDebts =  new DebtFormatted($debts);

                 return response()->json([
                'status' => 'success',
                'person_balance' => $customerBalance,
                'person_debts' => $customerDebts
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.customer_not_found')
            ], 200);
        } catch (QueryException $e) {
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


public function debtReports(Request $request)
{
    try {
        $request->validate([
            'customer_id'=>'nullable|exists:customers,id',
            'seller_id'=>'nullable|exists:sellers,id',
        ]);

        if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id')) {
            $person = Customer::findOrFail($request->customer_id);
            $debts = $person->debts;
            $type = 'customer';
        } else {
            $person = Seller::findOrFail($request->seller_id);
            $debts = $person->debts;
            $type = 'seller';
        }

        $owedDebts = $debts->where('type','owed to us')->where('status','unpaid')->sum('total');
        $owedToMeDebts = $debts->where('type','we owe')->where('status','unpaid')->sum('total');
        $balance = $owedToMeDebts - $owedDebts;


       // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.debt-report', [
            'person'         => $person,
            'debts'          => $debts,
            'owedDebts'      => $owedDebts,
            'owedToMeDebts'  => $owedToMeDebts,
            'balance'        => $balance,
            'type'           => $type,
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

        return $pdf->download('debt_report.pdf');

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.customer_not_found')
        ], 200);
    } catch (QueryException $e) {
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
