<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Followup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowupAPI extends Controller
{
    public function storeFollowup(Request $request)
{
    try{
        $data = $request->validate([
            'customer_id' => [
                'nullable',
                'exists:customers,id',
            ],
            'seller_id' => [
                'nullable',
                'exists:sellers,id',
            ],

            'product_id'  => 'required|string',
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

        $data['status'] = "initial";
        $followup = Followup::create($data);
        if($followup->customer_id){
          Logs::createLog('اضافة متابعة جديدة','اضافة متابعة للزبون'.' '.$followup->customer->name,'followups');
        }
        else{
            Logs::createLog('اضافة متابعة جديدة','اضافة متابعة للتاجر'.' '.$followup->seller->name,'followups');

        }
            return response()->json([
                'status'  => 'success',
                'message' => __('messages.followup_created_successfully'),
            ],200);

    }

    catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_create_followup')
            ], 200);
        }
}


public function updateFollowup(Request $request)
{
    try{
    $data = $request->validate([
        'followup_id' => 'required|exists:followups,id',
            'customer_id' => [
                'nullable',
                'exists:customers,id',
            ],
            'seller_id' => [
                'nullable',
                'exists:sellers,id',
            ],

        'product_id'  => 'required|string',
        'status' => 'required|string|in:inform,agreement,delivered,rejected',
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

        $followup = Followup::findOrFail($request->followup_id);
        $followup->update($data);

        if($request->status==='delivered'||$request->status==='rejected'){
                $name = $followup->customer_id? $followup->customer->name:$followup->seller->name;
                $cstatus = $request->status==='delivered'? 'تسليم':'رفض';

                Logs::createLog($cstatus.' '.'متابعة',
                'تم'.' '.$cstatus.' المتابعة للشخص'.' '.$name,'followups');
            }

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.followup_updated'),
        ],200);
 }
 catch (ValidationException $e) {
        return response(['status' => 'error',
         'message' => __('messages.validation_failed'),
         'errors'  => $e->errors()

        ], 200);
    } catch (ModelNotFoundException $e) {
        return response(['status' => 'error', 'message' => __('messages.followup_not_found')], 200);
    } catch (QueryException $e) {
        return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
    } catch (\Exception $e) {
        return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
    }
}



  private function getFollowups($status){
    try{

        $statuses = is_array($status) ? $status : [$status];

            $followups = Followup::whereIn('status',$statuses)
            ->where('is_canceled',0)
            ->with([
                'customer:id,name,phone,ID_image',
                'seller:id,name,phone,ID_image',

            ])->get();

            $formatted = $followups->map(function($followup){

                return [
                    'id'=> $followup->id,
                    'customer_name' => $followup->customer_id? $followup->customer->name:null,
                    'customer_phone' => $followup->customer_id
                        ? ($followup->customer->phone ?? 'no phone')
                        : null,
                    'customer_img' => $followup->customer_id
                        ?(  ($followup->customer->ID_image && count($followup->customer->ID_image)>0)? 'public/customerImages/ID/' . $followup->customer->ID_image[0]:'no image'   ):null,


                    'seller_name' => $followup->seller_id? $followup->seller->name:null,
                    'seller_phone' => $followup->seller_id
                        ? ($followup->seller->phone ?? 'no phone')
                        : null,
                    'seller_img' => $followup->seller_id
                        ?( ($followup->seller->ID_image && count($followup->seller->ID_image)>0)? 'public/sellerImages/ID/' . $followup->seller->ID_image[0]:'no image') :null,

                    'product_name' => $followup->product_id,
                    'followup_status'=> $followup->status,
                    'created_at' => $followup->created_at? $followup->created_at->format('Y-m-d'):null,

                ];
            });
            return response()->json([
                'status' => 'success',
                'followups' => $formatted
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_followups')
            ], 200);
        }
    }

    public function getInitialFollowups()
    {
        return $this->getFollowups('initial');
    }

    public function getSecondStepFollowups()
    {
        return $this->getFollowups('inform');
    }
    public function getThirdStepFollowups()
    {
        return $this->getFollowups('agreement');
    }

    public function getArchivedFollowups()
    {
        return $this->getFollowups(['delivered','rejected']);
    }


    public function cancelFollowUp(Request $request){
      try{
        $request->validate(['followup_id'=>'required|exists:followups,id']);

        $followup = Followup::findOrFail($request->followup_id);

       
        $followup->update(['is_canceled'=>1]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.followup_canceled_successfully')
            ], 200);
    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.followup_not_found')
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.update_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_followup')
            ], 200);
        }
}



    // for the last page
    public function storeCustomer(Request $request){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'type'=>'required|string|max:255',
                'phone' => [
                        'nullable',
                        'regex:/^\+\d{3}\ \d{9}$/',
                        'unique:customers,phone',
                    ],
                'notes'=>'nullable|string',

                ]);

             Customer::create($data);
             Logs::createLog('اضافة زبون جديد','تم اضافة زبون جديد باسم'.' '.$request->name,'customers');
             return response()->json([
                'status'=>'success',
                'message' =>__('messages.created_customer_successfully'),
             ],200);
   
        }

        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function showFollowup(Request $request){
        try{
            $request->validate(['followup_id'=>'required|integer|exists:followups,id']);

            $followup = Followup::with('customer:id,name,ID_image')
            ->with('seller:id,name,ID_image')->
            findOrFail($request->followup_id);

            $followup->makeHidden(['customer_id','seller_id','step','start_date','end_date']);
            if($followup->customer_id){
                $followup['customer']['ID_image'] = 
                ($followup->customer->ID_image && count($followup->customer->ID_image)>0)? 'public/customerImages/ID/' . $followup->customer->ID_image[0]:'no image';
            }

            elseif($followup->seller_id){
                $followup['seller']['ID_image'] = 
                ($followup->seller->ID_image && count($followup->seller->ID_image)>0)? 'public/sellerImages/ID/' . $followup->seller->ID_image[0]:'no image';
            }
            return response()->json([
                'status'=>'success',
                'followup'=> $followup,
            ],200);
        }
       catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } 
        
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }
}