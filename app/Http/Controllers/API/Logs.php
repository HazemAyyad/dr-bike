<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\Expense;
use App\Models\Log;
use App\Models\Product;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Logs extends Controller
{
    public static function createLog($name,$description,$type){
        Log::create([
            'name'=>$name,
            'description' =>$description,
            'type'=>$type
        ]);

    }



    private function respondWithLogs(callable $queryCallback)
{
    try {
        $logs = $queryCallback();

        return response()->json([
            'status' => 'success',
            'logs' => $logs,
        ], 200);
    } catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.retrieve_data_error'),
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
        ], 200);
    }
}


 public function getAllLogs()
{
    return $this->respondWithLogs(function () {
        return Log::where('is_canceled',0)->get();
    });
}

public function getEmployeesLogs()
{
    return $this->respondWithLogs(function () {
        return Log::where('type', 'employees')
        ->where('is_canceled',0)->get();
    });

}
  public function cancelLog(Request $request){
    try {
      $request->validate(['log_id'=>'required|exists:logs,id']);
      $log = Log::findOrFail($request->log_id);
      $log->update(['is_canceled'=>1]);
        return response()->json([
            'status' => 'success',
            'message' => __('messages.log_cancelled'),
        ], 200);
    } 
    
     catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    
    catch (QueryException $e) {
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


  public function showLog(Request $request){
       try {
      $request->validate(['log_id'=>'required|exists:logs,id']);
      $log = Log::findOrFail($request->log_id);
        return response()->json([
            'status' => 'success',
            'log_details' => $log,
        ], 200);
    } 
    
     catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    
    catch (QueryException $e) {
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
    
    // admin home page data 
    public function homeData(){
        try{

        $totalDebtsWeOwe = Debt::where('type','we owe')
        ->where('status','unpaid')
        ->sum('total'); // ديون علينا
        $totalDebtsOwedToUs = Debt::where('type','owed to us')
        ->where('status','unpaid')
        ->sum('total'); // ديون لنا
   
        $totalProducts = Product::count();
        $numberOfEmployees = EmployeeDetail::count(); // عدد الموظفين
        $totalCompletedTasks = EmployeeTask::where('status', 'completed')
            ->where('parent_id', NULL)
            ->count();
        $totalIncompletedTasks = EmployeeTask::where('status','!=', 'completed')
            ->where('parent_id', NULL)
            ->count();
        $totalExpenses = Expense::sum('price'); // اجمالي المصاريف

     return response()->json([
            'status'=>'success',
            'data' => [
                'total_debts_we_owe' => $totalDebtsWeOwe,
                'total_debts_owed_to_us' => $totalDebtsOwedToUs,
                'total_products' => $totalProducts,
                'number_of_employees' => $numberOfEmployees,
                'total_expenses' => $totalExpenses,
                'total_completed_tasks' => $totalCompletedTasks,
                'total_incompleted_tasks' => $totalIncompletedTasks,
            ],
        ],200);
        }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
        }
}
