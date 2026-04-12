<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeTasks extends Controller
{


//     private function getTasks($status){
//    try {
//         $tasks = EmployeeTask::with('employee')
//             ->where('status', $status)
//             ->where('is_canceled', 0)
//             ->get();

//         // $today = now();
//         // $todayDayName = strtolower($today->format('l')); // e.g. "monday"
//         // $todayDayOfMonth = (int)$today->format('d'); // e.g. 15

//         // // Filter based on recurrence
//         // $filtered = $tasks->filter(function ($task) use ($todayDayName, $todayDayOfMonth) {
//         //     $recurrence = $task->task_recurrence;
//         //     $times = $task->task_recurrence_time ?? [];

//         //     if ($recurrence === 'noRepeat') {
//         //         // Non-recurring: show only if created today or as per your logic
//         //         return true;
//         //     }

//         //     if ($recurrence === 'daily') {
//         //         return true; // Every day
//         //     }

//         //     if ($recurrence === 'weekly') {
//         //         // Match today's day name
//         //         return in_array($todayDayName, $times);
//         //     }

//         //     if ($recurrence === 'monthly') {
//         //         // Match today's date (e.g., 15)
//         //         return in_array($todayDayOfMonth, array_map('intval', $times));
//         //     }

//         //     return false;
//         // });

//         $formatted = $tasks->map(function ($task) {
//             return [
//                 'task_id' => $task->id,
//                 'task_name' => $task->name,
//                 'employee_id' => $task->employee_id,
//                 'employee_name' => $task->employee->user->name ?? 'unknown',
//                 'start_time' => $task->start_time,
//                 'end_time' => $task->end_time,
//                 'is_canceled' => $task->is_canceled,
//                 'employee_img' => $task->employee_img
//                     ? 'public/EmployeeTasksImages/' . $task->employee_img[0]
//                     : 'no employee image',
//                 'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
//                     ? 'public/AdminEmployeeTasksImages/' . $task->admin_img[0]
//                     : 'no admin image',
//                 'audio' => $task->audio
//                     ? 'public/employeeTasksAudio/' . $task->audio
//                     : 'no audio',
//                 'parent_id' => $task->parent_id,
//             ];
//         });//->values();

//             return $formatted;

//     } catch (QueryException $e) {
//         return response([
//             'status' => 'error',
//             'message' => __('messages.retrieve_data_error'),
//         ], 200);
//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => 'error',
//             'message' => __('messages.something_wrong'),
//         ], 200);
//     }
//   }


private function getTasks($status)
{
    try {
        $tasks = EmployeeTask::with('employee')
            ->where('status', $status)
            ->where('is_canceled', 0)
            ->get();

       $filtered = $tasks->filter(function ($task) {
            $recurrence = $task->task_recurrence;
            $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
            $dayName = strtolower(\Carbon\Carbon::parse($task->start_time)->format('l'));
            $dayOfMonth = (int) \Carbon\Carbon::parse($task->start_time)->format('d');

            switch ($recurrence) {
                case 'noRepeat':
                    return true; // no restriction

                case 'daily':
                    return true; // appears every day

                case 'weekly':
                    // only if today's weekday is included in recurrence time
                    return in_array($dayName, $times);

                case 'monthly':
                    // only if today's date matches the recurrence time number
                    return in_array((string)$dayOfMonth, $times);

                default:
                    return false;
            }
        });

        $formatted = $filtered->map(function ($task) {
            return [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'employee_id' => $task->employee_id,
                'employee_name' => $task->employee->user->name ?? 'unknown',
                'start_time' => $task->start_time,
                'end_time' => $task->end_time,
                'is_canceled' => $task->is_canceled,
                'employee_img' => $task->employee_img
                    ? 'public/EmployeeTasksImages/' . $task->employee_img[0]
                    : 'no employee image',
                'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
                    ? 'public/AdminEmployeeTasksImages/' . $task->admin_img[0]
                    : 'no admin image',
                'audio' => $task->audio
                    ? 'public/employeeTasksAudio/' . $task->audio
                    : 'no audio',
                'parent_id' => $task->parent_id,
            ];
        })->values();

        return $formatted;
    } catch (QueryException $e) {
        return response([
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

    public function completedTasks(){

        $formatted = $this->getTasks('completed');

        return response([
            'status' => 'success',
            'completed employee tasks'=>$formatted],200);       
    }

    public function ongoingTasks()
    {

         $formatted = $this->getTasks('ongoing');


            return response()->json([
            'status' => 'success',
            'ongoing employee tasks'=>$formatted],200); 

    } 


    public function canceledTasks()
    {
       try{
        $tasks = EmployeeTask::with('employee')
        ->where('is_canceled',1)
        ->get();

       
        // $today = now();
        // $todayDayName = strtolower($today->format('l')); // e.g. "monday"
        // $todayDayOfMonth = (int)$today->format('d'); // e.g. 15

        // // Filter based on recurrence
        // $filtered = $tasks->filter(function ($task) use ($todayDayName, $todayDayOfMonth) {
        //     $recurrence = $task->task_recurrence;
        //     $times = $task->task_recurrence_time ?? [];

        //     if ($recurrence === 'noRepeat') {
        //         // Non-recurring: show only if created today or as per your logic
        //         return true;
        //     }

        //     if ($recurrence === 'daily') {
        //         return true; // Every day
        //     }

        //     if ($recurrence === 'weekly') {
        //         // Match today's day name
        //         return in_array($todayDayName, $times);
        //     }

        //     if ($recurrence === 'monthly') {
        //         // Match today's date (e.g., 15)
        //         return in_array($todayDayOfMonth, array_map('intval', $times));
        //     }

        //     return false;
        // });

        $formatted = $tasks->map(function ($task) {
            return [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'employee_id' => $task->employee_id,
                'employee_name' => $task->employee->user->name ?? 'unknown',
                'start_time' => $task->start_time,
                'end_time' => $task->end_time,
                'is_canceled' => $task->is_canceled,
                'employee_img' => $task->employee_img
                    ? 'public/EmployeeTasksImages/' . $task->employee_img[0]
                    : 'no employee image',
                'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
                    ? 'public/AdminEmployeeTasksImages/' . $task->admin_img[0]
                    : 'no admin image',
                'audio' => $task->audio
                    ? 'public/employeeTasksAudio/' . $task->audio
                    : 'no audio',
                'parent_id' => $task->parent_id,
            ];
        });//->values();

            return response([
            'status' => 'success',
            'canceled employee tasks'=>$formatted],200); 
    

    }

    catch(QueryException $e){
               return response([
                'status'=>'error',
                'message' => __('messages.retrieve_data_error'),
            ],200);
        }
    catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);        }
    }

    
    public function cancelEmployeeTask(Request $request){
        try{
        $request->validate(['employee_task_id'=>'required|exists:employee_tasks,id']);

        $ongoingTask = EmployeeTask::findOrFail($request->employee_task_id);
       
        $ongoingTask->update(['is_canceled'=>1]);
        Logs::createLog('الغاء مهمة موظف',' الغاء مهمة موظف باسم'.' '.$ongoingTask->name
        .' '.'التابعة للموظف'.' '. $ongoingTask->employee->user->name
        
        ,'employee_tasks');
            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled')],200);
        
    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);}

    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }    
    catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_task'),
            ], 200);        }
}

//     public function restoreEmployeeTask(Request $request){
//         try{
//         $request->validate(['employee_task_id'=>'required|exists:employee_tasks,id']);

//         $ongoingTask = EmployeeTask::findOrFail($request->employee_task_id);
       
//         $ongoingTask->update(['is_canceled'=>0]);
//         Logs::createLog('استعادة مهمة موظف','تم استعادة مهمة موظف باسم'.' '.$ongoingTask->name,'employee_tasks');

//             return response()->json([
//                 'status' => 'success',
//                 'message' => __('messages.employee_task_restored')],200);
        
//     }

//     catch (ValidationException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.validation_failed'),
//             ], 200);}

//     catch (ModelNotFoundException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.employee_task_not_found')], 200);
//         }    
//     catch (\Exception $e) {
//              return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.failed_to_restore_task'),
//             ], 200);        }
// }


    public function cancelEmployeeTaskWithRepetition(Request $request){
        try{
        $request->validate(['employee_task_id'=>'required|exists:employee_tasks,id']);

        $ongoingTask = EmployeeTask::findOrFail($request->employee_task_id);

        $ongoingTask->update(['is_canceled'=>1]);
        
        if(!$ongoingTask->parent_id){
            EmployeeTask::where('parent_id', $ongoingTask->id)
            ->update(['is_canceled' => 1]);    
            }
            else{
               $parent = EmployeeTask::where('id', $ongoingTask->parent_id)->first();
               $parent->update(['is_canceled' => 1]);
               EmployeeTask::where('parent_id', $parent->id)->update(['is_canceled' => 1]);

            } 


        Logs::createLog('الغاء مهمة مع التكرار',' الغاء مهمة موظف مع التكرار باسم'.' '.$ongoingTask->name
        
        .' '.'التابعة للموظف'.' '.$ongoingTask->employee->user->name
        
        ,
        'employee_tasks');
            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled')],200);
        
    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);}

    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }    
    catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_task'),
            ], 200);        }
}



// public static function createHelper(Model $task, string $recurrence): void
// {
//     $recurrenceCounts = [
//         'daily' => 29,   // 30 total (main + 29 repeats)
//         'weekly' => 3,   // 4 total (main + 3 repeats)
//         'monthly' => 0,  // 3 total (main + 2 repeats)
//         'noRepeat' => 0,
//     ];

//     $count = $recurrenceCounts[$recurrence] ?? 0;
//     $start = \Carbon\Carbon::parse($task->start_time);
//     $end = \Carbon\Carbon::parse($task->end_time);

//     for ($i = 1; $i <= $count; $i++) {
//         $newStart = $start->copy();
//         $newEnd = $end->copy();

//         //  Adjust time based on recurrence type
//         switch ($recurrence) {
//             case 'daily':
//                 $newStart->addDays($i);
//                 $newEnd->addDays($i);
//                 break;
//             case 'weekly':
//                 $newStart->addWeeks($i);
//                 $newEnd->addWeeks($i);
//                 break;
//             case 'monthly':
//                 $newStart->addMonths($i);
//                 $newEnd->addMonths($i);
//                 break;
//         }

//         //  Duplicate record
//         $data = $task->replicate()->toArray();
//         $data['parent_id'] = $task->id;
//         $data['start_time'] = $newStart->format('Y-m-d H:i:s');
//         $data['end_time'] = $newEnd->format('Y-m-d H:i:s');

//         $task::create($data);
//     }
// }

// public static function createHelper(Model $task, string $recurrence): void
// {
//     $start = Carbon::parse($task->start_time);
//     $end = Carbon::parse($task->end_time);
//     $recurrenceDays = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];

//     if ($end->lessThanOrEqualTo($start)) {
//         return; // invalid range
//     }

//     // We'll move a cursor through time
//     $current = $start->copy();

//     while ($current->lessThanOrEqualTo($end)) {
//         switch ($recurrence) {
//             case 'daily':
//                 // Add 1 day each loop
//                 $current->addDay();

//                 if ($current->greaterThan($end)) break 2;

//                 self::duplicateTask($task, $current, $end);
//                 break;

//             case 'weekly':
//                 // For weekly, we go week by week and create for each chosen day
//                 $weekStart = $current->copy()->startOfWeek(); // beginning of current week

//                 foreach ($recurrenceDays as $day) {
//                     $dayCarbon = Carbon::parse($weekStart)->next(strtolower($day));

//                     // Only create if before end date
//                     if ($dayCarbon->greaterThan($end)) continue;

//                     // Skip if before start_time (first week edge case)
//                     if ($dayCarbon->lessThanOrEqualTo($start)) continue;

//                     self::duplicateTask($task, $dayCarbon, $end);
//                 }

//                 // Move to next week
//                 $current->addWeek();
//                 break;

//             case 'monthly':
//                  $current->addMonth();

//                 if ($current->greaterThan($end)) break 2;

//                 self::duplicateTask($task, $current, $end);
//                 break;

//             default:
//                 return; // noRepeat or invalid
//         }
//     }
// }

public static function createHelper(Model $task, string $recurrence): void
{
    $start = Carbon::parse($task->start_time);
    $end = Carbon::parse($task->end_time);
    $recurrenceDays = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];

    if ($end->lessThan($start)) {
        return; // invalid range
    }

    $current = $start->copy();

    while ($current->lessThanOrEqualTo($end)) {
        switch ($recurrence) {
            case 'daily':
                $current->addDay();
                if ($current->greaterThan($end)) break 2;
                self::duplicateTask($task, $current, $end);
                break;

            case 'weekly':
                // We'll check all recurrence days within the current week
                $weekStart = $current->copy()->startOfWeek();

                foreach ($recurrenceDays as $day) {
                    $dayCarbon = Carbon::parse($weekStart)->next(strtolower($day));

                    if ($dayCarbon->lessThan($weekStart)) {
                        $dayCarbon = $weekStart->copy();
                    }

                    if ($dayCarbon->betweenIncluded($start, $end)) {
                        self::duplicateTask($task, $dayCarbon, $end);
                    }
                }

                // Move to next week
                $current->addWeek();
                break;

            case 'monthly':
                $current->addMonth();
                if ($current->greaterThan($end)) break 2;
                self::duplicateTask($task, $current, $end);
                break;

            default:
                return;
        }
    }

    // ✅ Handle special case: ensure an instance on the END DATE if it matches recurrence
    // if ($recurrence === 'weekly' && in_array(strtolower($end->format('l')), $recurrenceDays)) {
    //     // check if not already created at end date
    //     $exists = $task->whereDate('start_time', $end->format('Y-m-d'))
    //                    ->where('parent_id', $task->id)
    //                    ->exists();
    //     if (!$exists) {
    //         self::duplicateTask($task, $end, $end);
    //     }
    // }
}


/**
 * Helper: Duplicate a task to a new date
 */
protected static function duplicateTask(Model $task, Carbon $newStart, Carbon $mainEnd): void
{
    $data = $task->replicate()->toArray();
    $data['parent_id'] = $task->id;
    $data['start_time'] = $newStart->format('Y-m-d H:i:s');
    $data['end_time'] = $mainEnd->format('Y-m-d H:i:s'); // always same as main
    $newTask= $task::create($data);

    $subtasks = $task->subTasks()->get();

    foreach ($subtasks as $subtask) {
            $subData = $subtask->replicate()->toArray();
            $subData['employee_task_id'] = $newTask->id; // link to new recurrent task
            EmployeeSubTask::create($subData);
        }
}



    public static function mediaHelper(Request $request ,String $imgPath){
        $adminImages=[];
        if ($request->hasFile('admin_img')) {
            foreach($request->file('admin_img') as $image){
                    $imageName = $image->getClientOriginalName();
                    $destinationPath = public_path($imgPath); 
                    $image->move(public_path($imgPath), $imageName);    
                    $adminImages[] = $imageName;
            }

   }

        return $adminImages;
    }

    public function createEmployeeTask(Request $request){
        try{
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'employee_id' => ['required','exists:employee_details,id'],
            'points' => ['required', 'integer', 'min:0'],
            'start_time' => ['required', 'date', 'before_or_equal:end_time'],
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],
            'task_recurrence' => ['required', 'string','in:noRepeat,daily,weekly,monthly'],
          
            'task_recurrence_time' => [
                'nullable',
                'array',
               // 'required_unless:task_recurrence,noRepeat',
            ],
            'task_recurrence_time.*' => [
                'required','string',
               // 'required_unless:task_recurrence,noRepeat',
            ],

            'audio' => 'nullable|file',
            'sub_employee_tasks' =>['nullable', 'array'],
            'sub_employee_tasks.*.name' => ['required', 'string', 'max:255'],
            'sub_employee_tasks.*.description' => ['nullable', 'string'],
            'sub_employee_tasks.*.is_forced_to_upload_img' => ['boolean','in:0,1'],
            'sub_employee_tasks.*.admin_subtask__img' => ['nullable', 'array'],
            'sub_employee_tasks.*.admin_subtask__img.*' => ['required', 'image'],



            'admin_img' => ['nullable','array'],
            'admin_img.*' => ['required', 'image'],


        ]);

        $data['not_shown_for_employee'] = $request->boolean('not_shown_for_employee');
        $data['is_forced_to_upload_img'] = $request->boolean('is_forced_to_upload_img');
        
        if ($request->hasFile('audio')) {
            $audio = $request->file('audio');
            $audioName = $audio->getClientOriginalName();
            $audio->move(public_path('employeeTasksAudio'), $audioName);

            $data['audio'] = $audioName;
        }

       $adminImages= $this->mediaHelper($request,'AdminEmployeeTasksImages');
       $data['admin_img'] = $adminImages;

        if($request->task_recurrence === 'daily'){
            $data['task_recurrence_time'] = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        }
        elseif($request->task_recurrence === 'monthly'){
            // Automatically get the day of the month from start_time
            $startDay = (int) \Carbon\Carbon::parse($request->start_time)->format('d');
            $data['task_recurrence_time'] = [(string) $startDay];
        }


        elseif($request->task_recurrence === 'weekly'){
            if(!$request->task_recurrence_time){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.enter_recurrence_time')
                ],200);
            }
        }
        $employeeTask = EmployeeTask::create($data);

        if ($request->has('sub_employee_tasks')) {
                foreach ($request->sub_employee_tasks as $index => $subTask) {
                        $subImagesNames = [];

                        // Check if THIS subtask has an image
                        if ($request->hasFile("sub_employee_tasks.$index.admin_subtask__img")) {

                            foreach($request->file("sub_employee_tasks.$index.admin_subtask__img") as $file){
                                $fullName = $file->getClientOriginalName();
                                $file->move(public_path('EmployeeSubTasks/AdminImages/'), $fullName);
                                $subImagesNames[] = $fullName;
                                   }
                        }

                        EmployeeSubTask::create([
                            'name' => $subTask['name'],
                            'description' => $subTask['description'] ?? null,
                            'employee_task_id' => $employeeTask->id,
                            'is_forced_to_upload_img' => $subTask['is_forced_to_upload_img'] ?? 0,
                            'admin_img' => $subImagesNames,
                        ]);
                    }
        }

        $this->createHelper($employeeTask,$request->task_recurrence);

        Logs::createLog('اضافة مهمة موظف','تم اضافة مهمة موظف باسم'.' '.$employeeTask->name
        
        .' '.'تابعة للموظف'.' '.$employeeTask->employee->user->name
        
        ,'employee_tasks');


            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_created_successfully')],200);
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
                'message' => __('messages.failed_to_create_task'),
            ], 200);        }
    }

    public function showEmployeeTaskDetails(Request $request){
        try{
        $request->validate(['employee_task_id'=>['required','exists:employee_tasks,id']]);

        $employeeTask = EmployeeTask
        ::with('subTasks')->findOrFail($request->employee_task_id);
    
        $employeeTask->subTasks->transform(function ($subTask) {
        if ($subTask->admin_img) {
                    $subTask->admin_img = collect($subTask->admin_img)->map(function ($img) {
                        return 'public/EmployeeSubTasks/AdminImages/' . $img;
                      })->toArray();
                    
                    

                }
        if ($subTask->employee_img) {
                $subTask->employee_img = collect($subTask->employee_img)->map(function ($empImg) {
                    return 'public/EmployeeSubTasks/EmployeeImages/' . $empImg;
                })->toArray();
            }
                return $subTask;
            });
            $employeeTask->makeHidden(['admin_img','employee_img','audio']);
            $taskData = $employeeTask->toArray(); // all fields of the task
            $taskData['employee_name'] = $employeeTask->employee->user->name; // add only employee name
            $taskData['admin_img'] =
                $employeeTask->admin_img
                ? collect($employeeTask->admin_img)->map(fn($img) => 'public/AdminEmployeeTasksImages/'.$img)->toArray()
                : 'no images';
            
            $taskData['employee_img'] = 
                $employeeTask->employee_img
                ? collect($employeeTask->employee_img)->map(fn($img) => 'public/EmployeeTasksImages/'.$img)->toArray()
                : 'no images';
            
            $taskData['audio'] = $employeeTask->audio? 'public/employeeTasksAudio/'.$employeeTask->audio : 'no audio';

            return response([
                'status' => 'success',
                'employee_task'=>$taskData,

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

       catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_fetch_task_details'),
            ], 200);        }

    }


public function updateEmployeeTask(Request $request)
{
    try {
        $data = $request->validate([
            'employee_task_id'=>['required','exists:employee_tasks,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'employee_id' => ['required','exists:employee_details,id'],
            'points' => ['required', 'integer', 'min:0'],
            'start_time' => ['required', 'date', 'before_or_equal:end_time'],
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],
            'task_recurrence' => ['required', 'string','in:noRepeat,daily,weekly,monthly'],

            'task_recurrence_time' => [
                'nullable',
                'array',
               // 'required_unless:task_recurrence,noRepeat',
            ],
            'task_recurrence_time.*' => [
                
                'required','string',
                //'required_unless:task_recurrence,noRepeat',
            ],
            'admin_img' => ['nullable', 'array'],
            'admin_img.*' => ['nullable'],

            'sub_employee_tasks' => ['nullable', 'array'],
            'sub_employee_tasks.*.id' => ['nullable', 'exists:sub_employee_tasks,id'],
            'sub_employee_tasks.*.name' => ['nullable', 'string', 'max:255'],
            'sub_employee_tasks.*.description' => ['nullable', 'string'],
            'sub_employee_tasks.*.is_forced_to_upload_img' => ['nullable','boolean','in:0,1'],
            'sub_employee_tasks.*.admin_subtask__img' => ['nullable', 'array'],
            'sub_employee_tasks.*.admin_subtask__img.*' => ['required', 'image'],
 
            'audio' => 'nullable',

        ]);

        // ✅ Always update the parent if the task is a recurrence
        $employeeTask = EmployeeTask::findOrFail($request->employee_task_id);
        if ($employeeTask->parent_id) {
            $employeeTask = EmployeeTask::findOrFail($employeeTask->parent_id);
        }

        $finalData = $request->except(['employee_task_id','sub_employee_tasks']);
        $finalData['not_shown_for_employee'] = $request->boolean('not_shown_for_employee');
        $finalData['is_forced_to_upload_img'] = $request->boolean('is_forced_to_upload_img');


        if($request->task_recurrence === 'daily'){
            $finalData['task_recurrence_time'] = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        }
        elseif($request->task_recurrence === 'monthly'){
            // Automatically get the day of the month from start_time
            $startDay = (int) \Carbon\Carbon::parse($request->start_time)->format('d');
            $finalData['task_recurrence_time'] = [(string) $startDay];
        }


        elseif($request->task_recurrence === 'weekly'){
            if(!$request->task_recurrence_time){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.enter_recurrence_time')
                ],200);
            }
        }

       // $finalData['admin_img'] = CommonUse::handleImageUpdate($request,'admin_img','AdminEmployeeTasksImages',$employeeTask->admin_img??[]);
        $oldRecurrence = $employeeTask->task_recurrence;

        $adminUpdatedImages = CommonUse::handleImageUpdate($request,'admin_img','AdminEmployeeTasksImages',$employeeTask->admin_img);
        $finalData['admin_img'] = $adminUpdatedImages;

        if($request->audio){
            if(is_string($request->audio)){
                $finalData['audio'] = $employeeTask->audio??null;
            }
            elseif($request->hasFile('audio')){
                $audio = $request->file('audio');
                $audioName = $audio->getClientOriginalName();
                $audio->move(public_path('employeeTasksAudio'), $audioName);

                $finalData['audio'] = $audioName;
  
            }
        }

        $employeeTask->update($finalData);


        

        if ($request->has('sub_employee_tasks')) {

            $empT = EmployeeTask::findOrFail($request->employee_task_id);
            
                $existingSubTaskIds = $empT->subTasks()->pluck('id')->toArray();
                $sentSubTasks = $data['sub_employee_tasks'];
                $keepIds = [];

                foreach ($sentSubTasks as $index => $subTaskData) {
                    if (isset($subTaskData['id'])) {
                        // Existing subtask 

                            $keepIds[] = $subTaskData['id'];
                        }
                    else {
                        $subImagesNames = [];
                        if ($request->hasFile("sub_employee_tasks.$index.admin_subtask__img")) {
                            foreach ($request->file("sub_employee_tasks.$index.admin_subtask__img") as $file) {
                                $fullName = $file->getClientOriginalName();
                                $file->move(public_path('EmployeeSubTasks/AdminImages/'), $fullName);
                                $subImagesNames[] = $fullName;
                            }
                        }
                        // New subtask → create
                        $newSubTask = EmployeeSubTask::create([
                            'employee_task_id' => $empT->id,
                            'name' => $subTaskData['name'],
                            'description' => $subTaskData['description'] ?? null,
                            'is_forced_to_upload_img' => $subTaskData['is_forced_to_upload_img'],
                            'admin_img' => $subImagesNames,

                        ]);
                        $keepIds[] = $newSubTask->id;
                    }
                }

                // Delete subtasks not included
                $deleteIds = array_diff($existingSubTaskIds, $keepIds);
                if (!empty($deleteIds)) {
                    EmployeeSubTask::whereIn('id', $deleteIds)->delete();
                }

              $updatedSubTasks = $empT->subTasks()->get();

              if(!$empT->parent_id){
                // // ✅ Replicate the updated subtasks to all recurrences
                // $recurrenceTasks = EmployeeTask::where('parent_id', $empT->id)->get();

                // foreach ($recurrenceTasks as $childTask) {
                //     // Delete old subtasks for that recurrence
                //     EmployeeSubTask::where('employee_task_id', $childTask->id)->delete();

                //     // Recreate new ones identical to the parent's subtasks
                //     foreach ($updatedSubTasks as $subTask) {
                //         $newSub = $subTask->replicate()->toArray();
                //         $newSub['employee_task_id'] = $childTask->id;
                //         EmployeeSubTask::create($newSub);
                //     }
                // }
            }
            else{
                $parent = EmployeeTask::findOrFail($empT->parent_id);
                $parent->subTasks()->delete();
                foreach ($updatedSubTasks as $subTask) {
                        $newSub = $subTask->replicate()->toArray();
                        $newSub['employee_task_id'] = $parent->id;
                        EmployeeSubTask::create($newSub);
                    }  
                // $children = EmployeeTask::whereNotIn('id',[$empT->id])->where('parent_id',$parent->id)->get();
                // foreach($children as $child){
                //     $child->subTasks()->delete();
                //     foreach ($updatedSubTasks as $subTask) {
                //         $newSub = $subTask->replicate()->toArray();
                //         $newSub['employee_task_id'] = $child->id;
                //         EmployeeSubTask::create($newSub);
                //     }                      
                // }
            }
        }


            //delete old children first
        EmployeeTask::where('parent_id', $employeeTask->id)->delete();

            // Create new recurrence children
        $this->createHelper($employeeTask, $employeeTask->task_recurrence);
        Logs::createLog(
            'تعديل مهمة موظف',
            'تم تعديل مهمة الموظف باسم ' . $employeeTask->name
            .' '.'التابعة للموظف'.' '.$employeeTask->employee->user->name
             
            ,
            'employee_tasks'
        );

        return response()->json([
            'status' => 'success',
            'message' => __('messages.employee_task_updated_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
            'errors' => $e->errors()
        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
                        'e'=>$e->getMessage(),

        ], 200);

    } catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.update_data_error'),
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
                        'e'=>$e->getMessage(),

        ], 200);
    }
}

    public function changeEmployeeTaskToCompleted(Request $request){
        try{
        $request->validate([
            'employee_task_id'=>'required|exists:employee_tasks,id',
        ]);

        $task = EmployeeTask::findOrFail($request->employee_task_id);
        $hasIncomplete = $task->subTasks()
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasIncomplete) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.can_not_complete_employee_task'),
            ], 200);
        }
       
        if($task->is_forced_to_upload_img && !$task->employee_img){
                return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_image_required'),
            ], 200);
            }
        
        $task->update(['status'=>'completed']);
        $employee = $task->employee;
        $pointsToAdd = $task->points + $employee->points;

        $employee->update(['points'=> $pointsToAdd]);
        Logs::createLog('اكمال مهمة موظف','اكمال مهمة موظف باسم'.' '.$task->name
        
        .' '.'التابعة للموظف'.' '.$task->employee->user->name, 
        'employee_tasks');

        return response()->json([
            'status' => 'success',
            'message' => __('messages.task_completed'),

        ], 200);
      }
         catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

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
            'message' => __('messages.unexpected_error'),
        ], 200);
    }
    }


        // for employe to do it
    public function changeSubTaskToCompleted(Request $request){
        try{
        $request->validate([
            'sub_task_id'=>'required|exists:sub_employee_tasks,id',
        ]);

        $subTask = EmployeeSubTask::findOrFail($request->sub_task_id);
        if($subTask->employeeTask->employee_id != auth()->user()->employee->id){
           return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200);
        }

        if($subTask->is_forced_to_upload_img && !$subTask->employee_img){
                return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_image_required'),
            ], 200);
            }
        
        $allSubTasks = EmployeeSubTask::where('employee_task_id',$subTask->employee_task_id)
        ->whereNotIn('id',[$request->sub_task_id])
        ->where('status', '!=', 'completed')
        ->exists();


        if(!$allSubTasks){
            $employeeTask = EmployeeTask::findOrFail($subTask->employee_task_id);
                   
            if($employeeTask->is_forced_to_upload_img && !$employeeTask->employee_img){
                    return response()->json([
                    'status' => 'error',
                    'message' => __('messages.employee_image_required'),
                ], 200);
            }
            $subTask->update(['status'=>'completed']);
            $employeeTask->update(['status'=>'completed']);
           
            $employee = $employeeTask->employee;
            $pointsToAdd = $employeeTask->points + $employee->points;

            $employeeTask->employee->update(['points'=> $pointsToAdd]);
        }

        else{
          $subTask->update(['status'=>'completed']);

        }

        Logs::createLog('اكمال مهمة موظف فرعية','اكمال مهمة موظف فرعية باسم'.' '.$subTask->name
        
        .' '.'التابعة للمهمة الرئيسية باسم'.' '.$subTask->employeeTask->name
        
        ,'employee_tasks');

            return response()->json([
            'status' => 'success',
            'message' => __('messages.task_completed'),

        ], 200);
      }
         catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

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
            'message' => __('messages.unexpected_error'),
        ], 200);
    }
    }

}




//     public function getCompletedTasks()
// {
//     $tasks = EmployeeTask::with('user')
//         ->where('status', 'completed')
//         ->get(['id', 'name', 'user_id', 'start_time', 'end_time']);
    

//     dd($tasks);
    
//     }