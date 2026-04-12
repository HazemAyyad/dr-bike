<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Seller;

use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentAndRecieve extends Controller
{


    public function handlePayment(Request $request)
{
    try {
        $request->validate([
            'type'        => 'required|string|in:payment,receive',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'seller_id'   => 'nullable|integer|exists:sellers,id',
            'box_id'      => 'nullable|integer|exists:boxes,id',
            'box_value'   => 'nullable|numeric|min:0',

            'checks' => 'nullable|array',
            'checks.*.check_value'    => 'required|numeric|min:1',
            'checks.*.check_currency' => 'required|string|max:255',
            'checks.*.check_id'       => 'required|string',
            'checks.*.bank_name'      => 'required|string',
            'checks.*.due_date'       => 'nullable|date',
            'checks.*.img'            => 'nullable|image',
            'checks.*.notes'            => 'nullable|string',

            'debts' => 'nullable|array',
            'debts.*.total'    => 'required|numeric|min:1',
            'debts.*.box_id'    => 'required|integer|exists:boxes,id',

            'debts.*.due_date' => 'nullable|date',
        ]);

        $type = $request->type;

        // Ensure either customer OR seller is provided
        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

        /** ---------------- BOX HANDLING ---------------- */
        if ($request->filled('box_id')) {
            if (!$request->filled('box_value')) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.must_enter_box_value')
                    ], 200);
                }
            $box = Box::findOrFail($request->box_id);

            if ($type === 'payment') {
                if ($box->total < $request->box_value) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.box_out_of_money')
                    ], 200);
                }
                $box->total -= $request->box_value;
                 BoxLogs::createBoxLog($box,'تم الدفع عن طريق الصندوق','minus',-$request->box_value);
    
            } else { // receive
                $box->total += $request->box_value;

                 BoxLogs::createBoxLog($box,'تم القبض عن طريق الصندوق','add',$request->box_value);

            }

            $box->save();
        }

        /** ---------------- CHECKS HANDLING ---------------- */
        if ($request->filled('checks')) {
                if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('messages.must_select_customer_or_seller')
                        ], 200);
                    }  
            $path=null;
            $personName='غير معروف';
            if($request->filled('customer_id')){
                $customer = Customer::findOrFail($request->customer_id);
                $personName = $customer->name;
            }
            elseif($request->filled('seller_id')){
                $seller = Seller::findOrFail($request->seller_id);
                $personName = $seller->name;
            }


            if($type==='payment'){
                $path = 'OutgoingChecksImages';
            } 
            else
                { 

                    $path = 'IncomingCheckImages/front' ;
                
                }
            foreach ($request->checks as $index => $checkData) {
                $checkImageName = null;

                if ($request->hasFile("checks.$index.img")) {
                    $image = $request->file("checks.$index.img");
                    $imageName = $image->getClientOriginalName();
                    $image->move(public_path($path), $imageName);
                    $checkImageName = $imageName;
                }

                if ($type === 'payment') {
                    $currencyService = new \App\Services\CurrencyService();

                    $amountInShekel = $currencyService->convertToShekel($checkData['check_value'], $checkData['check_currency']);

                    $check = OutgoingCheck::create([
                        'total'     => $checkData['check_value'],
                        'due_date'  => $checkData['due_date'] ?? null,
                        'currency'  => $checkData['check_currency'],
                        'check_id'  => $checkData['check_id'],
                        'bank_name' => $checkData['bank_name'],
                        'img'       => $checkImageName,
                        'status' => 'cashed_to_person',
                        'customer_id' => $request->customer_id??null,
                        'seller_id' => $request->seller_id??null,
                        'notes'     => $checkData['notes'],
                       
                    ]);



                    $sdebt = Debt::create([
                        'customer_id' => $request->customer_id??null,
                        'seller_id' => $request->seller_id??null,
                        'total' => $amountInShekel,
                        'type' => 'owed to us',
                    ]);
                        Logs::createLog(
                            'اضافة دين لنا بعد الدفع',
                            'تم  اضافة دين لنا بقيمة ' .' '. $sdebt->total.' '.
                            ' لصالح ' . $personName,
                            'debts'
                        );

                    Logs::createLog(
                        'اضافة شيك صادر والتصرف فيه',
                        "تمت إضافة شيك صادر بقيمة {$check->total}  {$check->currency}".' '.'والتصرف فيه لصالح'.$personName,
                        'outgoing_checks'
                    );
                } else {
                    $currencyService = new \App\Services\CurrencyService();

                    $amountInShekelIncom = $currencyService->convertToShekel($checkData['check_value'], $checkData['check_currency']);

                    $check = IncomingCheck::create([
                        'total'        => $checkData['check_value'],
                        'due_date'     => $checkData['due_date'] ?? null,
                        'currency'     => $checkData['check_currency'],
                        'check_id'     => $checkData['check_id'],
                        'bank_name'    => $checkData['bank_name'],
                        'front_image'  => $checkImageName,
                        'from_customer'=> $request->customer_id ?? null,
                        'from_seller'  => $request->seller_id ?? null,
                        'notes'     => $checkData['notes'],

                    ]);


                    $mydebt = Debt::create([
                        'customer_id' => $request->customer_id??null,
                        'seller_id' => $request->seller_id??null,
                        'total' => $amountInShekelIncom,
                        'type' => 'we owe',
                    ]);
                        Logs::createLog(
                            'اضافة دين علينا بعد القبض',
                            'تم  اضافة دين علينا بقيمة ' .' '. $mydebt->total.' '.
                            ' لصالح ' . $personName,
                            'debts'
                        );


                    Logs::createLog(
                        'اضافة شيك وارد جديد',
                        "تمت إضافة شيك وارد بقيمة {$check->total} {$check->currency}"." "."من الشخص"." ".$personName,
                        'incoming_checks'
                    );
                }
            }
        }

        /** ---------------- DEBTS HANDLING ---------------- */
        if ($request->filled('debts')) {
            if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.must_select_customer_or_seller')
                ], 200);
            }
            foreach($request->debts as $debtData){

                 $box = Box::findOrFail($debtData['box_id']);
                   if($box->currency!=='شيكل'){
                        return response()->json([
                            'status'=>'error',
                            'message'=>__('messages.currency_shekel'),
                        ],200);
                    }

                    if($type === 'payment' && $box->total < $debtData['total']){
                            return response()->json([
                            'status'=>'error',
                            'message'=>__('messages.box_out_of_money'),
                        ],200);
                    }
            }
            foreach ($request->debts as $debtData) {

                $debt = Debt::create([
                    'customer_id' => $request->customer_id ?? null,
                    'seller_id'   => $request->seller_id ?? null,
                    'total'       => $debtData['total'],
                    'due_date'    => $debtData['due_date'] ?? null,
                    'type'        => $type === 'receive' ? 'we owe' : 'owed to us',
                ]);

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
                Logs::createLog(
                    $type === 'payment' ? 'انشاء دين علينا' : 'انشاء دين لنا',
                    ($type === 'payment'
                        ? "تم اضافة دين علينا بعد الدفع بقيمة {$debt->total}"
                        : "تم اضافة دين لنا بعد القبض بقيمة {$debt->total}"),
                    'debts'
                );
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => $type === 'payment'
                ? __('messages.payment_success')
                : __('messages.receive_success'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
}

}
