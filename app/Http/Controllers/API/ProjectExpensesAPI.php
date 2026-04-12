<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectExpense;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectExpensesAPI extends Controller
{
        public function addExpenses(Request $request){
        try{
            $data = $request->validate([
                'project_id' =>'required|exists:projects,id',
                'expenses' => 'required|numeric|min:1',
                'notes' =>'nullable|string',
            ]);

            $expense = ProjectExpense::create($data);
            Logs::createLog('اضافة مصروف لمشروع ','تم اضافة مصروف جديد لمشروع باسم'.' '.$expense->project->name,'projects');

            return response()->json([
                'status'=>'success',
                'message' => __('messages.expense_created'),
            ],200);

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
                'message' => __('messages.something_wrong')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function projectExpenses(Request $request){
        try{
        $request->validate([
            'project_id' => 'required|exists:projects,id', ]);
   
   
       $project = Project::findOrFail($request->project_id);
       $expenses = $project->expenses;
       $total = $project->expenses->sum('expenses');
       return response()->json([
        'status'=>'success',
        'project_expenses' => $expenses,
        'total_expenses' => $total,
       ],200);
   
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
                'message' => __('messages.project_not_found')
            ], 200);
        }

        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
}
