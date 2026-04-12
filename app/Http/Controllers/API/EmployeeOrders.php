<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeOrders extends Controller
{



    public function employeeLoanOrders(){
        return $this->getOrdersByType('loan');
    }
    public function employeeOvertimeOrders(){
        return $this->getOrdersByType('overtime');
    }

    private function getOrdersByType(String $type){
        try{
            $orders = EmployeeOrder::where('type',$type)->get();
            $formatted = $orders->map(function($order) use ($type){
                $base = [
                    'id' => $order->id,
                    'employee_name' => $order->employee->user->name?? 'unkonwn',
                    'employee_img' => $order->employee->employee_img? 'public/EmployeeImages/'.$order->employee->employee_img[0]:  'no images',
                    'order_status' => $order->status,
                    'type' => $order->type,
                    'order_date' => $order->created_at->format('Y-m-d'),

                ];
          
                if($type==='loan'){
                    $base['loan_value'] = $order->loan_value;
                }
                elseif($type==='overtime'){
                        if($order->overtime_value !== null){
                               $base['overtime_value'] = $order->overtime_value; }
                         elseif($order->extra_work_hours !== null){
                                $base['extra_work_hours'] = $order->extra_work_hours; }


                }
                return $base;
          
            });

                return response()->json([
                'status' => 'success',
                'employee_orders' => $formatted,

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

    private function commonShowOrder(Request $request, $type){
        try{
            $request->validate(['employee_order_id'=>'required|exists:employee_orders,id']);

            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            $formatted = [
                'employee_name' => $order->employee->user->name,
                'order_date' => $order->created_at->format('Y-m-d'),

            ];
            if($type ==='loan'){
                $formatted['loan_value'] = $order->loan_value?? 'no value';
            }
            elseif($type==='overtime'){
                if($order->overtime_value !== null){
                     $formatted['overtime_value'] = $order->overtime_value?? 'no value'; }
                elseif($order->extra_work_hours !== null){
                     $formatted['extra_work_hours'] = $order->extra_work_hours; }

            }

                return response()->json([
                'status' => 'success',
                $type.'_order' => $formatted],200);
        
      }
        
     catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }
    catch (ModelNotFoundException $e) {
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

    public function showLoanOrder(Request $request){
       return $this->commonShowOrder($request,'loan');
    }

    public function showOvertimeOrder(Request $request){
       return $this->commonShowOrder($request,'overtime');
    }

    //for reject
    private function common(Request $request,$status){
        try{
            $request->validate(['employee_order_id'=>'required|exists:employee_orders,id']);

            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            $order->update(['status'=>$status]);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.status_upated')
            ],200);
        }
        catch(ModelNotFoundException $e){
           return response()->json([
            'status'=>'error',
            'message' => __('messages.something_wrong')
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

    public function approveLoanRequest(Request $request){
        try{
            $request->validate([
                'employee_order_id'=>'required|exists:employee_orders,id',
                'loan_value' =>'required|numeric|min:1',
            ]);

            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            $order->update(['status'=>'approved','loan_value'=>$request->loan_value]);

            $employee = $order->employee;
            $employee->debts += $request->loan_value;
            $employee->save();

        Logs::createLog('قبول طلب سلفة ',' تم قبول طلب سلفة  لموظف باسم'.' '.$employee->user->name
        .' '.'بقيمة '.' '.$request->loan_value
        ,'employees');
            return response()->json([
                'status'=>'success',
                'message' => __('messages.status_upated')
            ],200);
        }
        catch(ModelNotFoundException $e){
           return response()->json([
            'status'=>'error',
            'message' => __('messages.something_wrong')
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


    public function approveOvertimeRequest(Request $request){
        try{
            $request->validate([
                'employee_order_id'=>'required|exists:employee_orders,id',
                'extra_work_hours' =>'nullable|numeric|min:1',
                'overtime_value' =>'nullable|numeric|min:1',

            ]);

            if($request->filled('extra_work_hours') && $request->filled('overtime_value')){
            return response()->json([
                'status'=>'error',
                'message' => __('messages.only_one_extra_hours')
            ],200);         
           }

            if(!$request->filled('extra_work_hours') && !$request->filled('overtime_value')){
            return response()->json([
                'status'=>'error',
                'message' => __('messages.one_extra_hours_should_filled')
            ],200);         
           }
            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            $employee = $order->employee;
            if($request->filled('overtime_value')){
                $order->update(['status'=>'approved','overtime_value'=>$request->overtime_value]);
                $employee->total_work_hours += $request->overtime_value;
                $employee->salary += $request->overtime_value * $employee->overtime_work_price;
                $employee->save();
                Logs::createLog('قبول طلب اوفر تايم ',' تم قبول طلب اوفر تايم  لموظف باسم'.' '.$employee->user->name
                .' '.'بقيمة '.' '.$request->overtime_value
                ,'employees');
            }
            elseif($request->filled('extra_work_hours')){
                $order->update(['status'=>'approved','extra_work_hours'=>$request->extra_work_hours]);
                $employee->total_work_hours += $request->extra_work_hours;
                $employee->salary += $request->extra_work_hours * $employee->hour_work_price;
                $employee->save();
                Logs::createLog('قبول طلب ساعات اضافية ',' تم قبول طلب ساعات اضافية  لموظف باسم'.' '.$employee->user->name
                .' '.'بقيمة '.' '.$request->extra_work_hours
                ,'employees');
            }
            return response()->json([
                'status'=>'success',
                'message' => __('messages.status_upated')
            ],200);
        }
        catch(ModelNotFoundException $e){
           return response()->json([
            'status'=>'error',
            'message' => __('messages.something_wrong')
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




    public function reject(Request $request){
        return $this->common($request,'rejected');
    }

}
