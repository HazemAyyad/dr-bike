<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Boxes extends Controller
{
    private function actorVisibleBoxIds(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || $user->type === 'admin' || ! Schema::hasTable('employee_visible_boxes')) {
            Log::debug('boxes.visible_scope.unrestricted', [
                'user_id' => $user?->id,
                'user_type' => $user?->type,
                'has_visible_table' => Schema::hasTable('employee_visible_boxes'),
            ]);
            return null;
        }

        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        $ids = $employee->visibleBoxes()
            ->pluck('boxes.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        Log::debug('boxes.visible_scope.employee', [
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'visible_box_ids' => $ids,
        ]);

        return $ids;
    }

    private function actorCanAccessBox(Request $request, int $boxId): bool
    {
        $visibleIds = $this->actorVisibleBoxIds($request);
        return $visibleIds === null || in_array($boxId, $visibleIds, true);
    }

    public function addBox(Request $request){
     try{
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'total'        => 'required|numeric|min:0',
            'currency' => 'required|string|in:شيكل,دولار,دينار',
        ]);

        if (abs((float) $data['total']) > 0.0001) {
            throw ValidationException::withMessages([
                'total' => ['أنشئ الصندوق برصيد صفر ثم استخدم إضافة رصيد أو تحويل من صندوق آخر.'],
            ]);
        }
        $data['total'] = 0;
        $box = Box::create($data);
        $user = $request->user();
        if ($user?->type === 'employee' && $user->employee && Schema::hasTable('employee_visible_boxes')) {
            $user->employee->visibleBoxes()->syncWithoutDetaching([(int) $box->id]);
        }
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
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
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
            
            DB::transaction(function () use ($request, $data) {
                $box = Box::query()->lockForUpdate()->findOrFail($request->box_id);
                if (! $this->actorCanAccessBox($request, (int) $box->id)) {
                    throw new ModelNotFoundException;
                }
                if (abs((float) $data['total'] - (float) $box->total) > 0.0001) {
                    throw ValidationException::withMessages([
                        'total' => ['لا يمكن تعديل رصيد الصندوق مباشرة. استخدم عملية إضافة/سحب/تحويل.'],
                    ]);
                }
                if ((string) $data['currency'] !== (string) $box->currency) {
                    throw ValidationException::withMessages([
                        'currency' => ['لا يمكن تغيير عملة صندوق بعد إنشائه. أنشئ صندوقًا جديدًا بالعملة المطلوبة.'],
                    ]);
                }
                $box->update([
                    'name' => $data['name'],
                    'is_shown' => $data['is_shown'],
                ]);
            });
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
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
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
                if (! $this->actorCanAccessBox($request, (int) $box->id)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.box_not_found')
                    ], 200);
                }
               $logs = BoxLog::where('box_id', $box->id)
                    ->orWhere('to_box_id', $box->id)
                    ->orWhere('from_box_id', $box->id)
                    ->with('fromBox:id,name,total,type')
                    ->with('toBox:id,name,total,type')
                    ->with('box:id,name,total,type')
                    ->get();         
                $boxDetails =[
                        'box_name'=> $box->name,
                        'box_type'=> $box->type,
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
            $visibleIds = $this->actorVisibleBoxIds(request());
            $boxes = Box::where('is_shown',$condition)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
                ->get();
            Log::debug('boxes.common_data.result', [
                'condition' => $condition,
                'visible_ids' => $visibleIds,
                'result_ids' => $boxes->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'result_names' => $boxes->pluck('name')->all(),
            ]);
            $boxesData = $boxes->map(function($box){ 
                return [
                    'box_id' => $box->id,
                    'box_name' => $box->name,
                    'total_balance' => $box->total,
                    'is_shown' => $box->is_shown,
                    'currency' => $box->currency,
                    'type' => $box->type,
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
            'total' => 'required|numeric|not_in:0',
            'note' => 'nullable|string|max:2000',
            'reason_code' => 'required|string|in:owner_contribution,owner_withdrawal,cash_overage,cash_shortage,accounting_correction',
        ]);
        $total = round((float) $request->total, 4);
        if (abs($total) <= 0.0001) {
            throw ValidationException::withMessages([
                'total' => ['قيمة حركة الصندوق يجب أن تكون أكبر من صفر.'],
            ]);
        }
        $reason = (string) $request->reason_code;
        $increaseReasons = ['owner_contribution', 'cash_overage'];
        $decreaseReasons = ['owner_withdrawal', 'cash_shortage'];
        if (($total > 0 && in_array($reason, $decreaseReasons, true))
            || ($total < 0 && in_array($reason, $increaseReasons, true))) {
            throw ValidationException::withMessages([
                'reason_code' => ['سبب حركة الصندوق لا يتوافق مع اتجاه المبلغ.'],
            ]);
        }
        if (in_array($reason, ['cash_overage', 'cash_shortage', 'accounting_correction'], true)
            && ! $request->filled('note')) {
            throw ValidationException::withMessages(['note' => ['الملاحظة مطلوبة لهذا النوع من التسوية.']]);
        }

        $msg = $total > 0 ? 'added' : 'deduct';
        DB::transaction(function () use ($request, $total, $reason) {
            $box = Box::query()->lockForUpdate()->findOrFail($request->box_id);
            if (! $this->actorCanAccessBox($request, (int) $box->id)) {
                throw new ModelNotFoundException;
            }
            $newBalance = round((float) $box->total + $total, 4);
            if ($newBalance < 0) {
                throw ValidationException::withMessages([
                    'total' => ['رصيد الصندوق غير كافٍ لتنفيذ الحركة.'],
                ]);
            }
            $box->update(['total' => $newBalance]);
            $description = $total > 0 ? 'تم اضافة رصيد للصندوق' : 'تم سحب رصيد من الصندوق';
            BoxLogs::createBoxLog(
                $box,
                $description,
                $total > 0 ? 'add' : 'minus',
                abs($total),
                $request->filled('note') ? (string) $request->note : null,
                $reason,
                $request->user()?->id,
            );
        });
        return response()->json([
            'status'=>'success',
            'message' => __('messages.box_balance_'.$msg.'_successfully'),
    
        ],200);

      }
    catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
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

            DB::transaction(function () use ($request) {
                $ids = [(int) $request->from_box_id, (int) $request->to_box_id];
                sort($ids);
                $boxes = Box::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $fromBox = $boxes->get((int) $request->from_box_id);
                $toBox = $boxes->get((int) $request->to_box_id);
                if (! $fromBox || ! $toBox
                    || ! $this->actorCanAccessBox($request, (int) $fromBox->id)
                    || ! $this->actorCanAccessBox($request, (int) $toBox->id)) {
                    throw new ModelNotFoundException;
                }
                if ($fromBox->currency !== $toBox->currency) {
                    throw ValidationException::withMessages(['to_box_id' => [__('messages.must_be_same_currency')]]);
                }
                $amount = round((float) $request->total, 4);
                if ($amount - (float) $fromBox->total > 0.0001) {
                    throw ValidationException::withMessages(['total' => [__('messages.not_enough')]]);
                }
                $toBox->update(['total' => round((float) $toBox->total + $amount, 4)]);
                $fromBox->update(['total' => round((float) $fromBox->total - $amount, 4)]);
                BoxLogs::createTransferLog(
                    $fromBox,
                    $toBox,
                    'تم نقل رصيد للصندوق',
                    $amount,
                    null,
                    $request->user()?->id,
                );
            });
            return response()->json([
                'status'=>'success',
                'message'=>'balance_transfered',
            ]);


        }

           catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
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

            DB::transaction(function () use ($request) {
                $box = Box::query()->lockForUpdate()->findOrFail($request->box_id);
                if (! $this->actorCanAccessBox($request, (int) $box->id)) {
                    throw new ModelNotFoundException;
                }
                if (abs((float) $box->total) > 0.0001 || $this->boxHasFinancialHistory((int) $box->id)) {
                    throw ValidationException::withMessages([
                        'box_id' => ['لا يمكن حذف صندوق يحتوي على رصيد أو حركات مالية. قم بإخفائه بدلًا من حذفه.'],
                    ]);
                }
                $box->delete();
            });
            return response()->json([
                'status'  => 'success',
                'message' => __('messages.box_deleted'),
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
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

    private function boxHasFinancialHistory(int $boxId): bool
    {
        $references = [
            'box_logs' => ['box_id', 'from_box_id', 'to_box_id'],
            'accounting_journal_lines' => ['box_id'],
            'debt_transactions' => ['box_id'],
            'instant_sales' => ['payment_box_id'],
            'profit_sales' => ['payment_box_id'],
            'purchase_payments' => ['box_id'],
            'expenses' => ['box_id'],
            'incoming_check_boxes' => ['box_id'],
            'outgoing_checks' => ['box_id'],
            'maintenance' => ['payment_box_id'],
            'maintenance_payments' => ['box_id'],
            'sales_orders' => ['payment_box_id', 'delivery_settled_box_id'],
            'sales_order_settlements' => ['box_id'],
            'returns' => ['refund_box_id'],
            'sales_returns' => ['refund_box_id'],
            'assets' => ['box_id'],
            'project_expenses' => ['box_id'],
            'draws' => ['box_id'],
            'deposits' => ['box_id'],
            'salary_payment_batches' => ['box_id'],
            'delivery_company_settlement_batches' => ['box_id'],
            'employee_orders' => ['approved_box_id'],
            'goals' => ['box_id'],
            'purchase_return_refunds' => ['box_id'],
        ];

        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $available = collect($columns)->filter(fn (string $column) => Schema::hasColumn($table, $column));
            if ($available->isEmpty()) {
                continue;
            }
            $exists = DB::table($table)->where(function ($query) use ($available, $boxId) {
                foreach ($available as $column) {
                    $query->orWhere($column, $boxId);
                }
            })->exists();
            if ($exists) {
                return true;
            }
        }

        return false;
    }


}
