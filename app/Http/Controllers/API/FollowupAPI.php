<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\FollowupActivityLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowupAPI extends Controller
{
    private function actorName(Request $request): string
    {
        $user = $request->user();

        return $user?->name ?? 'System';
    }

    private function logActivity(Followup $followup, Request $request, string $action, string $description, array $changes = []): void
    {
        FollowupActivityLog::create([
            'followup_id' => $followup->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'description' => $description,
            'changes' => $changes ?: null,
        ]);
    }

    private function visibleFollowupsQuery(Request $request, array $statuses)
    {
        $query = Followup::whereIn('status', $statuses)
            ->where('is_canceled', 0);

        if ($request->user()?->type !== 'admin') {
            $query->where(function ($q) {
                $q->where('admin_only', 0)->orWhereNull('admin_only');
            });
        }

        return $query;
    }

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
            'admin_only' => 'nullable|boolean',
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
        $data['admin_only'] = $request->user()?->type === 'admin'
            ? $request->boolean('admin_only', false)
            : false;
        $data['created_by_user_id'] = $request->user()?->id;
        $followup = Followup::create($data);
        $this->logActivity(
            $followup,
            $request,
            'created',
            'تم إنشاء المتابعة بواسطة '.$this->actorName($request),
            [
                'admin_only' => $data['admin_only'],
                'status' => 'initial',
            ]
        );
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
        'admin_only' => 'nullable|boolean',
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
        $before = $followup->only(['customer_id','seller_id','product_id','status','admin_only']);
        if ($request->has('admin_only') && $request->user()?->type === 'admin') {
            $data['admin_only'] = $request->boolean('admin_only');
        }
        $followup->update($data);
        $after = $followup->fresh()->only(['customer_id','seller_id','product_id','status','admin_only']);
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changes[$key] = [
                    'from' => $before[$key] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->logActivity(
            $followup,
            $request,
            'updated',
            'تم تعديل المتابعة بواسطة '.$this->actorName($request),
            $changes
        );

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


    public function deleteFollowup(Request $request){
      try{
        $request->validate(['followup_id'=>'required|exists:followups,id']);

        $followup = Followup::findOrFail($request->followup_id);
        $name = $followup->customer_id? $followup->customer->name:$followup->seller->name;
        $this->logActivity(
            $followup,
            $request,
            'deleted',
            'تم حذف المتابعة بواسطة '.$this->actorName($request)
        );
        $followup->delete();

        Logs::createLog('حذف متابعة','تم حذف المتابعة للشخص '.$name,'followups');

        return response()->json([
            'status' => 'success',
            'message' => __('messages.followup_deleted_successfully')
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
                'message' => __('messages.delete_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_delete_followup')
            ], 200);
        }
}



  private function getFollowups(Request $request, $status){
    try{

        $statuses = is_array($status) ? $status : [$status];

            $followups = $this->visibleFollowupsQuery($request, $statuses)
            ->with([
                'customer:id,name,phone,ID_image',
                'seller:id,name,phone,ID_image',
                'createdBy:id,name,type',

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
                    'created_by_name' => $followup->createdBy?->name,
                    'created_by_type' => $followup->createdBy?->type,
                    'admin_only' => (bool) $followup->admin_only,

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

    public function getInitialFollowups(Request $request)
    {
        return $this->getFollowups($request, 'initial');
    }

    public function getSecondStepFollowups(Request $request)
    {
        return $this->getFollowups($request, 'inform');
    }
    public function getThirdStepFollowups(Request $request)
    {
        return $this->getFollowups($request, 'agreement');
    }

    public function getArchivedFollowups(Request $request)
    {
        return $this->getFollowups($request, ['delivered','rejected']);
    }


    public function cancelFollowUp(Request $request){
      try{
        $request->validate(['followup_id'=>'required|exists:followups,id']);

        $followup = Followup::findOrFail($request->followup_id);

       
        $followup->update(['is_canceled'=>1]);
        $this->logActivity(
            $followup,
            $request,
            'canceled',
            'تم إلغاء المتابعة بواسطة '.$this->actorName($request)
        );

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
            ->with('seller:id,name,ID_image')
            ->with('createdBy:id,name,type')
            ->with(['activityLogs' => function ($query) {
                $query->with('user:id,name,type')->orderBy('created_at', 'desc');
            }])->
            findOrFail($request->followup_id);

            if ($followup->admin_only && $request->user()?->type !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.followup_not_found'),
                ], 200);
            }

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
                'activity_logs' => $followup->activityLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'description' => $log->description,
                        'actor_name' => $log->user?->name,
                        'actor_type' => $log->user?->type,
                        'changes' => $log->changes,
                        'created_at' => $log->created_at?->format('Y-m-d H:i'),
                    ];
                })->values(),
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
