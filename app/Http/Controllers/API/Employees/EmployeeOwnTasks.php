<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\API\CommonUse;
use App\Http\Controllers\Controller;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeOwnTasks extends Controller
{


    public function editEmployeeTasksImages(Request $request){
        try{
            $request->validate([
                'employee_task_id'=>'required|integer|exists:employee_tasks,id',

                'employee_img' => ['nullable','array'],
                'employee_img.*' => ['nullable'],

            ]);
            $user = $request->user();
            $employee = $user->employee;

            $task = EmployeeTask::
            findOrFail($request->employee_task_id);
            if($task->employee_id != $employee->id){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.unauthorized'),
                ]);
            }

        $finalEmployeeImages = CommonUse::handleImageUpdate(
            $request,
            'employee_img',
            'EmployeeTasksImages',
            $task->employee_img ?? []
        );

        $task->employee_img = $finalEmployeeImages;
        $task->save();
        // EmployeeTask::where('parent_id',$task->id)
        // ->update(['employee_img' => $finalEmployeeImages]);

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.employee_images_updated'),
        ],200);

        }


    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
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
                'message' => __('messages.something_wrong'),
            ], 200);        }

    
    }



        public function editEmployeeSubTasksImages(Request $request){
        try{
            $request->validate([
                'sub_employee_task_id'=>'required|integer|exists:sub_employee_tasks,id',

                'employee_img' => ['nullable','array'],
                'employee_img.*' => ['nullable'],

            ]);
            $user = $request->user();
            $employee = $user->employee;

            $subTask = EmployeeSubTask::
            findOrFail($request->sub_employee_task_id);
            if($subTask->employeeTask->employee_id != $employee->id){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.unauthorized'),
                ]);
            }

        $finalEmployeeImages = CommonUse::handleImageUpdate(
            $request,
            'employee_img',
            'EmployeeSubTasks/EmployeeImages',
            $subTask->employee_img ?? []
        );

        $subTask->employee_img = $finalEmployeeImages;
        $subTask->save();

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.employee_sub_task_images_updated'),
        ],200);

        }


    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
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
                'message' => __('messages.something_wrong'),
            ], 200);        }

    
    }
}
