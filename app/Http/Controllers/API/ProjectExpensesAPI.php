<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Services\ExpenseBoxAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectExpensesAPI extends Controller
{
    public function addExpenses(Request $request, ExpenseBoxAccessService $access)
    {
        try {
            $data = $request->validate([
                'project_id' => 'required|exists:projects,id',
                'expenses' => 'required|numeric|min:1',
                'box_id' => 'required|integer|exists:boxes,id',
                'expense_date' => 'nullable|date',
                'notes' => 'nullable|string',
            ]);

            if (! $access->canUse($request->user(), (int) $data['box_id'])) {
                throw ValidationException::withMessages(['box_id' => ['الصندوق غير مسموح للموظف أو أن جلسته اليومية مغلقة.']]);
            }

            $expense = DB::transaction(function () use ($data, $request) {
                $box = Box::query()->lockForUpdate()->findOrFail($data['box_id']);
                if ((float) $box->total + 0.0001 < (float) $data['expenses']) {
                    throw ValidationException::withMessages(['expenses' => [__('messages.box_out_of_money')]]);
                }
                $data['currency'] = $box->currency ?: 'شيكل';
                $data['expense_date'] = $data['expense_date'] ?? now()->toDateString();
                $data['created_by'] = $request->user()?->id;
                $box->update(['total' => (float) $box->total - (float) $data['expenses']]);
                $expense = ProjectExpense::create($data);
                BoxLogs::createBoxLog($box, 'مصروف مشروع #'.$expense->project_id, 'minus', (float) $expense->expenses, $expense->notes);

                return $expense;
            });
            Logs::createLog('اضافة مصروف لمشروع ', 'تم اضافة مصروف جديد لمشروع باسم'.' '.$expense->project->name, 'projects');

            return response()->json([
                'status' => 'success',
                'message' => __('messages.expense_created'),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),

            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function projectExpenses(Request $request)
    {
        try {
            $request->validate([
                'project_id' => 'required|exists:projects,id', ]);

            $project = Project::findOrFail($request->project_id);
            $expenses = $project->expenses;
            $total = $project->expenses->sum('expenses');

            return response()->json([
                'status' => 'success',
                'project_expenses' => $expenses,
                'total_expenses' => $total,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.project_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
