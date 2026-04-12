<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RewardsApi extends Controller
{
       public function store(Request $request){
        try{
            $data = $request->validate([
                'reward' => ['required', 'string', 'max:255'],
                'employee_id' => ['required', 'exists:employee_details,id'],
                'price'=>'nullable|numeric|min:1',
    
            ]);

            $reward = Reward::create($data);
            Logs::createLog('اضافة مكافأة جديدة',$request->reward.' '.'للموظف'.' '.$reward->employee->user->name,'rewards');


                    return response()->json([
                        'status' => 'success',
                        'message' => __('messages.reward_created'),
                    ], 200);
                }

            catch (ValidationException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => $e->errors()
                ], 200);
            } 
            
                catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.create_data_error'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                    'error' => $e->getMessage()
                ], 200);
            }
        }
}
