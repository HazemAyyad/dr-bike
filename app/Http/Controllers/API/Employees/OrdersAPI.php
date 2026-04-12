<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\API\Logs;
use App\Http\Controllers\Controller;
use App\Models\EmployeeOrder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrdersAPI extends Controller
{
public function createOverTimeOrder(Request $request){
    try{


        $data = $request->validate([
            'overtime_value'=>'required|integer|min:1',
        ]);

        $user = $request->user();
        EmployeeOrder::create([
            'employee_id' => $user->employee->id,
            'type' => 'overtime',
            'overtime_value' => $request->overtime_value,
            
        ]);
    
        Logs::createLog('انشاء طلب اوفر تايم',' انشاء طلب اوفر تايم لموظف باسم'.' '.$user->name
        .' '.'بعدد ساعات'.' '.$request->overtime_value
        ,'employees');
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.employee_order_created')],200);
            
        }


        catch (ValidationException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => $e->errors()

                ], 200);
            }

        catch (QueryException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')], 200);
            }    
        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')
                ], 200);        }

}

public function createLoanOrder(Request $request){
    try{


        $data = $request->validate([
            'loan_value'=>'required|integer|min:1',
        ]);

        $user = $request->user();
        EmployeeOrder::create([
            'employee_id' => $user->employee->id,
            'type' => 'loan',
            'loan_value' => $request->loan_value,
            
        ]);
    
        Logs::createLog('انشاء طلب سلفة ',' انشاء طلب سلفة  لموظف باسم'.' '.$user->name
        .' '.'بقيمة '.' '.$request->loan_value
        ,'employees');
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.employee_order_created')],200);
            
        }


        catch (ValidationException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => $e->errors()

                ], 200);
            }

        catch (QueryException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')], 200);
            }    
        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')
                ], 200);        }

}

    // get logged in employee orders
    public function getMyOrders(Request $request){
        try{

            $user = $request->user();
            $employee = $user->employee;
            $orders = $employee->orders;
            $formatted = $orders->map(function($order){
                return [
                'id'=> $order->id,
                'type'=> $order->type,
                'status' => $order->status,
                'overtime_value'=> $order->overtime_value?? null,
                'loan_value' => $order->loan_value?? null,
                'extra_work_hours' => $order->extra_work_hours?? null,
                'created_at' => $order->created_at->format('Y-m-d'),
    
                ];
            });

            return response()->json([
                'status'=>'success',
                'orders' => $formatted,
            ],200);

        }

        catch (QueryException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')], 200);
            }    
        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong')
                ], 200);        }
    }
}
