<?php


namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Punishment;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PunishmentsApi extends Controller
{
    public function store(Request $request){
        try{
            $data = $request->validate([
                'punishment' => ['required', 'string', 'max:255'],
                'employee_id' => ['required', 'exists:employee_details,id'],
                'price'=>'nullable|numeric|min:1',

    
            ]);

            $punishment = Punishment::create($data);
            Logs::createLog('اضافة عقوبة جديدة',$request->punishment.' '.'للموظف'.' '.$punishment->employee->user->name,'punishments');


                    return response()->json([
                        'status' => 'success',
                        'message' => __('messages.punishment_created'),
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
