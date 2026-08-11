<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\EmployeeDetail;
use App\Models\EmployeeOrder;
use App\Services\EmployeeActivityLogger;
use App\Services\EmployeeNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmployeeOrders extends Controller
{
    public function employeeAdvancesByMonth(Request $request, EmployeeDetail $employee)
    {
        try {
            $request->validate([
                'month' => ['required', 'date_format:Y-m'],
            ]);

            $month = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
            $orders = EmployeeOrder::query()
                ->where('employee_id', $employee->id)
                ->where('type', 'loan')
                ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->orderBy('created_at', 'desc')
                ->get();

            $advances = $orders->map(function (EmployeeOrder $order) {
                $created = Carbon::parse($order->created_at);

                return [
                    'id' => $order->id,
                    'status' => $order->status,
                    'amount' => (float) ($order->loan_value ?? 0),
                    'approved_loan_value' => $order->status === 'approved'
                        ? (float) ($order->loan_value ?? 0)
                        : null,
                    'reviewed_at' => $order->status === 'pending'
                        ? null
                        : $order->updated_at?->format('Y-m-d h:i A'),
                    'rejection_reason' => $order->rejection_reason,
                    'approved_box_id' => $order->approved_box_id ? (int) $order->approved_box_id : null,
                    'box_log_id' => $order->box_log_id ? (int) $order->box_log_id : null,
                    'cancellation_reason' => $order->cancellation_reason,
                    'cancelled_at' => $order->cancelled_at?->format('Y-m-d h:i A'),
                    'previous_loan_value' => $order->previous_loan_value,
                    'edited_after_approval_at' => $order->edited_after_approval_at?->format('Y-m-d h:i A'),
                    'can_edit' => $order->type === 'loan' && $order->status === 'approved',
                    'can_cancel' => $order->type === 'loan' && $order->status === 'approved',
                    'day' => $created->format('l'),
                    'date' => $created->toDateString(),
                    'time' => $created->format('h:i A'),
                ];
            })->values();

            $approvedTotal = $orders
                ->filter(fn ($order) => in_array($order->status, ['approved', 'paid'], true))
                ->sum(fn ($order) => (float) ($order->loan_value ?? 0));

            return response()->json([
                'status' => 'success',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->user?->name,
                    ],
                    'month' => $month->format('Y-m'),
                    'advances' => $advances,
                    'total' => (float) $orders->sum(fn ($order) => (float) ($order->loan_value ?? 0)),
                    'approved_total' => (float) $approvedTotal,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }



    public function employeeLoanOrders(){
        return $this->getOrdersByType('loan');
    }
    public function employeeOvertimeOrders(){
        return $this->getOrdersByType('overtime');
    }

    private function getOrdersByType(String $type){
        try{
            $orders = EmployeeOrder::with('employee.user')
                ->where('type',$type)
                ->latest()
                ->get();
            $formatted = $orders->map(function($order) use ($type){
                $employee = $order->employee;
                $images = is_array($employee?->employee_img) ? $employee->employee_img : [];
                $base = [
                    'id' => $order->id,
                    'employee_name' => $employee?->user?->name ?? 'unknown',
                    'employee_img' => ! empty($images) ? 'public/EmployeeImages/'.$images[0] :  'no images',
                    'order_status' => $order->status,
                    'type' => $order->type,
                    'order_date' => $order->created_at->format('Y-m-d'),
                    'can_review' => $order->status === 'pending',
                    'reviewed_at' => $order->status === 'pending'
                        ? null
                        : $order->updated_at?->format('Y-m-d h:i A'),
                    'rejection_reason' => $order->rejection_reason,
                    'approved_box_id' => $order->approved_box_id ? (int) $order->approved_box_id : null,
                    'cancellation_reason' => $order->cancellation_reason,
                    'cancelled_at' => $order->cancelled_at?->format('Y-m-d h:i A'),
                    'previous_loan_value' => $order->previous_loan_value,
                    'edited_after_approval_at' => $order->edited_after_approval_at?->format('Y-m-d h:i A'),
                    'can_edit' => $order->type === 'loan' && $order->status === 'approved',
                    'can_cancel' => $order->type === 'loan' && $order->status === 'approved',

                ];
          
                if($type==='loan'){
                    $base['loan_value'] = $order->loan_value;
                    $base['approved_loan_value'] = $order->status === 'approved'
                        ? $order->loan_value
                        : null;
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
            $request->validate([
                'employee_order_id'=>'required|exists:employee_orders,id',
                'rejection_reason' => 'nullable|string|max:1000',
            ]);

            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            if ($order->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'تمت مراجعة هذا الطلب مسبقاً',
                ], 200);
            }

            $reason = trim((string) $request->input('rejection_reason', ''));
            $payload = ['status'=>$status];
            if ($status === 'rejected') {
                $payload['rejection_reason'] = $reason !== '' ? $reason : null;
            }
            $order->update($payload);

            if ($status === 'rejected' && $order->type === 'loan') {
                try {
                    app(EmployeeNotificationService::class)->notifyLoanRejected($order->fresh(), $reason);
                } catch (\Throwable $e) {
                    Log::error('Employee notification (loan rejected) failed: '.$e->getMessage(), [
                        'employee_order_id' => $order->id,
                    ]);
                }

                app(EmployeeActivityLogger::class)->log(
                    (int) $order->employee_id,
                    $request->user(),
                    'advances',
                    'employee_advance_rejected',
                    'رفض طلب سلفة',
                    'تم رفض طلب سلفة بقيمة '.number_format((float) ($order->loan_value ?? 0), 2, '.', ''),
                    $order->fresh(),
                    (float) ($order->loan_value ?? 0),
                    [
                        'order_id' => (int) $order->id,
                        'amount' => (float) ($order->loan_value ?? 0),
                        'reason' => $reason !== '' ? $reason : null,
                    ]
                );
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

    public function approveLoanRequest(Request $request){
        try{
            $request->validate([
                'employee_order_id'=>'required|exists:employee_orders,id',
                'loan_value' =>'required|numeric|min:1',
                'box_id' => 'nullable|integer|exists:boxes,id',
            ]);

            $order = EmployeeOrder::findOrFail($request->employee_order_id);
            if ($order->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'تمت مراجعة هذا الطلب مسبقاً',
                ], 200);
            }

            $approvedLoanValue = (float) $request->loan_value;
            $employee = null;
            DB::transaction(function () use ($request, $order, $approvedLoanValue, &$employee) {
                $employee = $order->employee;
                $boxId = null;
                $boxLogId = null;

                if ($request->filled('box_id')) {
                    $box = Box::lockForUpdate()->findOrFail((int) $request->box_id);
                    if ((float) ($box->total ?? 0) < $approvedLoanValue) {
                        throw new \InvalidArgumentException(__('messages.box_out_of_money'));
                    }

                    $box->total = (float) ($box->total ?? 0) - $approvedLoanValue;
                    $box->save();

                    $boxLog = BoxLog::create([
                        'box_id' => $box->id,
                        'value' => -$approvedLoanValue,
                        'type' => 'payment',
                        'description' => 'صرف سلفة موظف',
                        'note' => 'صرف سلفة للموظف '.$employee?->user?->name.' بقيمة '.number_format($approvedLoanValue, 2, '.', ''),
                    ]);
                    $boxId = $box->id;
                    $boxLogId = $boxLog->id;
                }

                $order->update([
                    'status'=>'approved',
                    'loan_value'=>$approvedLoanValue,
                    'rejection_reason' => null,
                    'approved_box_id' => $boxId,
                    'box_log_id' => $boxLogId,
                ]);

                $employee->debts += $approvedLoanValue;
                $employee->save();
            });

            try {
                app(EmployeeNotificationService::class)->notifyLoanApproved($order->fresh());
            } catch (\Throwable $e) {
                Log::error('Employee notification (loan approved) failed: '.$e->getMessage(), [
                    'employee_order_id' => $order->id,
                ]);
            }

            $freshOrder = $order->fresh();
            app(EmployeeActivityLogger::class)->log(
                (int) $freshOrder->employee_id,
                $request->user(),
                'advances',
                'employee_advance_approved',
                'قبول طلب سلفة',
                'تم قبول طلب سلفة بقيمة '.number_format($approvedLoanValue, 2, '.', ''),
                $freshOrder,
                $approvedLoanValue,
                [
                    'order_id' => (int) $freshOrder->id,
                    'amount' => $approvedLoanValue,
                    'approved_box_id' => $freshOrder->approved_box_id ? (int) $freshOrder->approved_box_id : null,
                    'box_log_id' => $freshOrder->box_log_id ? (int) $freshOrder->box_log_id : null,
                ]
            );

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
        catch(\InvalidArgumentException $e){
           return response()->json([
            'status'=>'error',
            'message' => $e->getMessage()
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
            ], 200);
        }
    }

    public function createEmployeeAdvance(Request $request, EmployeeDetail $employee)
    {
        try {
            $request->validate([
                'loan_value' => 'required|numeric|min:1',
                'box_id' => 'nullable|integer|exists:boxes,id',
                'order' => 'nullable|string|max:500',
            ]);

            $amount = (float) $request->loan_value;
            $box = null;
            $boxLog = null;
            $order = null;
            DB::transaction(function () use ($request, $employee, $amount, &$box, &$boxLog, &$order) {
                $boxId = null;
                $boxLogId = null;

                if ($request->filled('box_id')) {
                    $box = Box::lockForUpdate()->findOrFail((int) $request->box_id);
                    if ((float) ($box->total ?? 0) < $amount) {
                        throw new \InvalidArgumentException(__('messages.box_out_of_money'));
                    }

                    $box->total = (float) ($box->total ?? 0) - $amount;
                    $box->save();

                    $boxLog = BoxLog::create([
                        'box_id' => $box->id,
                        'value' => -$amount,
                        'type' => 'payment',
                        'description' => 'صرف سلفة موظف',
                        'note' => 'صرف سلفة مباشرة للموظف '.$employee->user?->name.' بقيمة '.number_format($amount, 2, '.', ''),
                    ]);

                    $boxId = $box->id;
                    $boxLogId = $boxLog->id;
                }

                $order = EmployeeOrder::create([
                    'employee_id' => $employee->id,
                    'order' => $request->input('order', 'سلفة مباشرة من الإدارة'),
                    'status' => 'approved',
                    'type' => 'loan',
                    'loan_value' => $amount,
                    'approved_box_id' => $boxId,
                    'box_log_id' => $boxLogId,
                ]);

                $employee->debts = (float) ($employee->debts ?? 0) + $amount;
                $employee->save();
            });

            Logs::createLog(
                'اضافة سلفة موظف',
                'تمت إضافة سلفة مباشرة للموظف '.$employee->user?->name.' بقيمة '.$amount,
                'employees'
            );

            app(EmployeeActivityLogger::class)->log(
                (int) $employee->id,
                $request->user(),
                'advances',
                'employee_advance_created',
                'إضافة سلفة مباشرة',
                'تمت إضافة سلفة مباشرة بقيمة '.number_format($amount, 2, '.', ''),
                $order,
                $amount,
                [
                    'order_id' => (int) $order->id,
                    'amount' => $amount,
                    'approved_box_id' => $box ? (int) $box->id : null,
                    'box_log_id' => $boxLog ? (int) $boxLog->id : null,
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.status_upated'),
                'advance' => [
                    'id' => (int) $order->id,
                    'status' => $order->status,
                    'amount' => $amount,
                    'approved_box_id' => $box ? (int) $box->id : null,
                    'box_log_id' => $boxLog ? (int) $boxLog->id : null,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function cancelApprovedAdvance(Request $request)
    {
        try {
            $request->validate([
                'employee_order_id' => 'required|exists:employee_orders,id',
                'cancellation_reason' => 'nullable|string|max:1000',
            ]);

            $reason = trim((string) $request->input('cancellation_reason', ''));
            $order = EmployeeOrder::with('employee.user')->findOrFail($request->employee_order_id);
            if ($order->type !== 'loan' || $order->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'يمكن إلغاء السلفة المقبولة فقط',
                ], 200);
            }

            $amount = (float) ($order->loan_value ?? 0);
            DB::transaction(function () use ($request, $order, $amount, $reason) {
                $employee = $order->employee()->lockForUpdate()->firstOrFail();
                $employee->debts = max(0, (float) ($employee->debts ?? 0) - $amount);
                $employee->save();

                if ($order->approved_box_id) {
                    $box = Box::lockForUpdate()->find($order->approved_box_id);
                    if ($box) {
                        $box->total = (float) ($box->total ?? 0) + $amount;
                        $box->save();

                        BoxLog::create([
                            'box_id' => $box->id,
                            'value' => $amount,
                            'type' => 'income',
                            'description' => 'إلغاء سلفة موظف',
                            'note' => 'إرجاع مبلغ سلفة ملغاة للموظف '.$employee->user?->name.' بقيمة '.number_format($amount, 2, '.', ''),
                        ]);
                    }
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancelled_by' => $request->user()?->id,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason !== '' ? $reason : null,
                ]);
            });

            $freshOrder = $order->fresh('employee.user');

            try {
                app(EmployeeNotificationService::class)->notifyLoanCancelled($freshOrder, $reason);
            } catch (\Throwable $e) {
                Log::error('Employee notification (loan cancelled) failed: '.$e->getMessage(), [
                    'employee_order_id' => $order->id,
                ]);
            }

            app(EmployeeActivityLogger::class)->log(
                (int) $freshOrder->employee_id,
                $request->user(),
                'advances',
                'employee_advance_cancelled',
                'إلغاء سلفة',
                'تم إلغاء سلفة بقيمة '.number_format($amount, 2, '.', ''),
                $freshOrder,
                -$amount,
                [
                    'order_id' => (int) $freshOrder->id,
                    'amount' => $amount,
                    'reason' => $reason !== '' ? $reason : null,
                    'approved_box_id' => $freshOrder->approved_box_id ? (int) $freshOrder->approved_box_id : null,
                ]
            );

            Logs::createLog(
                'إلغاء سلفة موظف',
                'تم إلغاء سلفة للموظف '.$freshOrder->employee?->user?->name.' بقيمة '.$amount,
                'employees'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.status_upated'),
                'advance' => [
                    'id' => (int) $freshOrder->id,
                    'status' => $freshOrder->status,
                    'amount' => (float) ($freshOrder->loan_value ?? 0),
                    'cancellation_reason' => $freshOrder->cancellation_reason,
                    'cancelled_at' => $freshOrder->cancelled_at?->toIso8601String(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function updateApprovedAdvance(Request $request)
    {
        try {
            $request->validate([
                'employee_order_id' => 'required|exists:employee_orders,id',
                'loan_value' => 'required|numeric|min:1',
                'order' => 'nullable|string|max:500',
            ]);

            $order = EmployeeOrder::with('employee.user')->findOrFail($request->employee_order_id);
            if ($order->type !== 'loan' || $order->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'يمكن تعديل السلفة المقبولة فقط',
                ], 200);
            }

            $oldAmount = (float) ($order->loan_value ?? 0);
            $newAmount = (float) $request->loan_value;
            $difference = $newAmount - $oldAmount;

            DB::transaction(function () use ($request, $order, $oldAmount, $newAmount, $difference) {
                $employee = $order->employee()->lockForUpdate()->firstOrFail();

                if ($order->approved_box_id && abs($difference) > 0.0001) {
                    $box = Box::lockForUpdate()->find($order->approved_box_id);
                    if ($box) {
                        if ($difference > 0 && (float) ($box->total ?? 0) < $difference) {
                            throw new \InvalidArgumentException(__('messages.box_out_of_money'));
                        }

                        $box->total = (float) ($box->total ?? 0) - $difference;
                        $box->save();

                        BoxLog::create([
                            'box_id' => $box->id,
                            'value' => -$difference,
                            'type' => $difference > 0 ? 'payment' : 'income',
                            'description' => 'تعديل سلفة موظف',
                            'note' => 'تعديل سلفة الموظف '.$employee->user?->name.' من '.number_format($oldAmount, 2, '.', '').' إلى '.number_format($newAmount, 2, '.', ''),
                        ]);
                    }
                }

                $employee->debts = max(0, (float) ($employee->debts ?? 0) + $difference);
                $employee->save();

                $payload = [
                    'loan_value' => $newAmount,
                    'previous_loan_value' => $oldAmount,
                    'edited_after_approval_by' => $request->user()?->id,
                    'edited_after_approval_at' => now(),
                ];
                if ($request->filled('order')) {
                    $payload['order'] = $request->order;
                }

                $order->update($payload);
            });

            $freshOrder = $order->fresh('employee.user');

            try {
                app(EmployeeNotificationService::class)->notifyLoanUpdated($freshOrder, $oldAmount, $newAmount);
            } catch (\Throwable $e) {
                Log::error('Employee notification (loan updated) failed: '.$e->getMessage(), [
                    'employee_order_id' => $order->id,
                ]);
            }

            app(EmployeeActivityLogger::class)->log(
                (int) $freshOrder->employee_id,
                $request->user(),
                'advances',
                'employee_advance_updated_after_approval',
                'تعديل سلفة بعد الموافقة',
                'تم تعديل السلفة من '.number_format($oldAmount, 2, '.', '').' إلى '.number_format($newAmount, 2, '.', ''),
                $freshOrder,
                $difference,
                [
                    'order_id' => (int) $freshOrder->id,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                    'difference' => $difference,
                    'approved_box_id' => $freshOrder->approved_box_id ? (int) $freshOrder->approved_box_id : null,
                ]
            );

            Logs::createLog(
                'تعديل سلفة موظف',
                'تم تعديل سلفة للموظف '.$freshOrder->employee?->user?->name.' من '.$oldAmount.' إلى '.$newAmount,
                'employees'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.status_upated'),
                'advance' => [
                    'id' => (int) $freshOrder->id,
                    'status' => $freshOrder->status,
                    'amount' => (float) ($freshOrder->loan_value ?? 0),
                    'previous_loan_value' => (float) ($freshOrder->previous_loan_value ?? 0),
                    'edited_after_approval_at' => $freshOrder->edited_after_approval_at?->toIso8601String(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
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
            if ($order->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'تمت مراجعة هذا الطلب مسبقاً',
                ], 200);
            }

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
