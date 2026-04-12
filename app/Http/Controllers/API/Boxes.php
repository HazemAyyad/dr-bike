<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Boxes extends Controller
{

    public function addBox(Request $request){
     try{
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'total'        => 'required|numeric|min:0',
            'currency' => 'required|string',
        ]);
    
        $box = Box::create($data);
        Logs::createLog('اضافة صندوق جديد',' تم اضافة صندوق جديد باسم'.' '.$request->name,
        'boxes');
    
            return response()->json([
                'status'  => 'success',
                'message' => __('messages.box_created_successfully')
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
                'message' => __('messages.failed_to_create_box')
            ], 200);
        }


}

    public function editBox(Request $request){
        try{
            $data = $request->validate([
                'box_id' =>'required|exists:boxes,id',
                'name'         => 'required|string|max:255',
                'total'        => 'required|numeric|min:0',
                'is_shown'     => 'required|in:0,1',
                'currency' => 'required|string',
            ]);
            
            $box = Box::findOrFail($request->box_id);
            $box->update($data);
            Logs::createLog('تعديل صندوق ',' تم تعديل بيانات صندوق  باسم'.' '.$request->name,
        'boxes');

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.box_updated_successfully')
            ], 200);
    }

     catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.box_not_found')
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.update_data_error')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_update_box')
            ], 200);
        }

  }

    public function showBox(Request $request){
        try{
            $request->validate([
                'box_id' =>'required|exists:boxes,id']);
                $box = Box::findOrFail($request->box_id);
               $logs = BoxLog::where('box_id', $box->id)
                    ->orWhere('to_box_id', $box->id)
                    ->orWhere('from_box_id', $box->id)
                    ->with('fromBox:id,name,total')
                    ->with('toBox:id,name,total')
                    ->with('box:id,name,total')
                    ->get();         
                $boxDetails =[
                        'box_name'=> $box->name,
                        'box_currency'=> $box->currency,
                        'totla_balance'=> $box->total,
                        'is_shown'=> $box->is_shown,
                        'box_logs' => $logs,
                    ];
                    
            return response()->json([
                'status'  => 'success',
                'box details' => $boxDetails
            ],200);        

            }
            catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed')
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.box_not_found')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_load_box')
            ], 200);
        }
    }

    private function commonData($condition){
        try{
            $boxes = Box::where('is_shown',$condition)->get();
            $boxesData = $boxes->map(function($box){ 
                return [
                    'box_id' => $box->id,
                    'box_name' => $box->name,
                    'total_balance' => $box->total,
                    'is_shown' => $box->is_shown,
                    'currency' => $box->currency,
                ];
            });
        
            return response()->json([
                'status' => 'success',
                'boxes'  => $boxesData
            ], 200);
        

      }

      catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_load_boxes')
            ], 200);
        }
    }
    public function getShownBoxes(){
       return $this->commonData(1);

    }

    public function getHiddentBoxes(){
         return $this->commonData(0);
 
    }

    public function addBalance(Request $request){
        try{
        $request->validate([
            'box_id' => 'required|exists:boxes,id',
            'total' => 'required|numeric',
        ]);

        $box = Box::findOrFail($request->box_id);
        $box->update(['total' => $box->total + $request->total]);
        $msg = 'added';
        if($request->total >0){

                 BoxLogs::createBoxLog($box,'تم اضافة رصيد للصندوق','add',$request->total);
                 $msg = 'added';
                }
        elseif($request->total<0){
                BoxLogs::createBoxLog($box,'تم سحب رصيد من الصندوق','minus',$request->total);
                $msg = 'deduct';

        }
        return response()->json([
            'status'=>'success',
            'message' => __('messages.box_balance_'.$msg.'_successfully'),
    
        ],200);

      }
    catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.box_not_found')
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.update_data_error')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_update_box')
            ], 200);
        }


    }

    public function transferBalance(Request $request){
        try{
            $request->validate([
                'from_box_id' => 'required|exists:boxes,id',
                'to_box_id' => 'required|exists:boxes,id',
                'total' => 'required|numeric|min:1',

            ]);

        // Check if both boxes are the same
            if ($request->from_box_id == $request->to_box_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cannot_transfer_same_box'),
                ], 200);
            }

            $fromBox = Box::findOrFail($request->from_box_id);
            $toBox = Box::findOrFail($request->to_box_id);
            if($fromBox->currency !== $toBox->currency){
                return response()->json([
                    'status'=>'error',
                    'message' => __('messages.must_be_same_currency'),
                ],200);                
            }
            if($request->total > $fromBox->total){
                return response()->json([
                    'status'=>'error',
                    'message' => __('messages.not_enough'),
                ],200);
            }

            $toBox->update(['total' => $toBox->total + $request->total]);
            $fromBox->update(['total' => $fromBox->total - $request->total]);
            BoxLogs::createTransferLog($fromBox,$toBox,'تم نقل رصيد للصندوق',$request->total);
            return response()->json([
                'status'=>'success',
                'message'=>'balance_transfered',
            ]);


        }

           catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.box_not_found')
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.update_data_error')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.failed_to_update_box')
            ], 200);
        }

        
    }


    public function deleteBox(Request $request){
        try{

            $request->validate(['box_id'=>'required|integer|exists:boxes,id']);

            Box::findOrFail($request->box_id)->delete();
            return response()->json([
                'status'  => 'success',
                'message' => __('messages.box_deleted'),
            ], 200);
        }
             catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.box_not_found')
            ], 200);

        } catch (QueryException $e) {
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
