<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SpecialTask;
use App\Models\SubTask;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SpecialTasks extends Controller
{

    public $specialTaskAdminImagesPath = 'SpecialTasksAdminImages';
    public $specialTaskAudiosPath = 'SpecialTasksAudio';
    public $subSpecialTaskAdminImagesPath = 'SupSpecialTasksAdminImages';

    // private function commonGetData($status , callable $query){
    //        try {
    //         $tasks = $query();
    //         $today = now();
    //         $todayDayName = strtolower($today->format('l')); // e.g. "monday"
    //         $todayDayOfMonth = (int)$today->format('d'); // e.g. 15

    //         // Filter based on recurrence
    //         $filtered = $tasks->filter(function ($task) use ($todayDayName, $todayDayOfMonth) {
    //             $recurrence = $task->task_recurrence;
    //             $times = $task->task_recurrence_time ?? [];

    //             if ($recurrence === 'noRepeat') {
    //                 // Non-recurring: show only if created today or as per your logic
    //                 return true;
    //             }

    //             if ($recurrence === 'daily') {
    //                 return true; // Every day
    //             }

    //             if ($recurrence === 'weekly') {
    //                 // Match today's day name
    //                 return in_array($todayDayName, $times);
    //             }

    //             if ($recurrence === 'monthly') {
    //                 // Match today's date (e.g., 15)
    //                 return in_array($todayDayOfMonth, array_map('intval', $times));
    //             }

    //             return false;
    //         })->values();
    //         return response()->json([
    //             'status' => 'success',
    //             $status.'_tasks' => $filtered,
    //         ], 200);
    //     }         catch(QueryException $e){
    //         return response([
    //             'status'=>'error',
    //             'message' => __('messages.retrieve_data_error'),
    //         ],200);
    //     }
        
    //     catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.something_wrong'),
    //             'error' => $e->getMessage()
    //         ], 200);
    //     }

    // }

        private function commonGetData($status ,$name, callable $query){
           try {
            $tasks = $query();
 
            // Filter based on recurrence
           $filtered = $tasks->filter(function ($task) {
            $recurrence = $task->task_recurrence;
            $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
            $dayName = strtolower(\Carbon\Carbon::parse($task->start_date)->format('l'));
            $dayOfMonth = (int) \Carbon\Carbon::parse($task->start_date)->format('d');

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
        })->values();


            return response()->json([
                'status' => 'success',
                $name.'_tasks' => $filtered,
            ], 200);
        }         catch(QueryException $e){
            return response([
                'status'=>'error',
                'message' => __('messages.retrieve_data_error'),
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
    public function completedSpecialTasks()
    {
        return $this->commonGetData('completed','completed', function(){
            return SpecialTask::where('status', 'completed')
               // ->where('parent_id',null)
                ->where('is_canceled',0)
                ->get(['id', 'name', 'start_date', 'end_date','is_canceled','status','task_recurrence','task_recurrence_time']);

        });
    }


public function ongoingSpecialTasks()
{
    return $this->commonGetData('ongoing','ongoing', function() {
        $now = now();

 

        // Return only those still ongoing and not expired
        return SpecialTask::where('status', 'ongoing')
            //->whereNull('parent_id')
            ->where('is_canceled',0)
            ->where('end_date', '>=', $now)
            ->get(['id', 'name', 'start_date', 'end_date', 'is_canceled', 'status','task_recurrence','task_recurrence_time']);
    });
}


    public function noDateTasks()
    {
        return $this->commonGetData('ongoing','no_date', function() {
            $now = now();

            return SpecialTask::where(function($query) use ($now) {
                           $query->where('end_date', '<', $now)
                            ->where('status', '!=', 'completed');
                })
                //->whereNull('parent_id')
                ->where('is_canceled',0)

                ->get(['id', 'name', 'start_date', 'end_date', 'is_canceled', 'status','task_recurrence','task_recurrence_time']);

                    });
    }

     public function canceledSpecialTasks()
    {
        try {
            $tasks = SpecialTask::where('is_canceled',1)
               // ->where('parent_id',null)
                ->get(['id', 'name', 'start_date', 'end_date','status','task_recurrence','task_recurrence_time']);

        // Filter based on recurrence
          $filtered = $tasks->filter(function ($task) {
            $recurrence = $task->task_recurrence;
            $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
            $dayName = strtolower(\Carbon\Carbon::parse($task->start_date)->format('l'));
            $dayOfMonth = (int) \Carbon\Carbon::parse($task->start_date)->format('d');

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
        })->values();

            return response()->json([
                'status' => 'success',
                'canceled_tasks' => $filtered,
            ], 200);
        }         catch(QueryException $e){
            return response([
                'status'=>'error',
                'message' => __('messages.retrieve_data_error'),
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


 public function cancelSpecialTask(Request $request)
{
    return $this->cancelSpecialTaskHandler($request, false);
}

public function cancelSpecialTaskWithRepition(Request $request)
{
    return $this->cancelSpecialTaskHandler($request, true);
}

private function cancelSpecialTaskHandler(Request $request, bool $withRepetition)
{
    try {
        $data = $request->validate([
            'special_task_id' => 'required|exists:special_tasks,id',
        ]);

        $task = SpecialTask::findOrFail($data['special_task_id']);

        $task->update(['is_canceled' => 1]);

        if ($withRepetition) {
            if(!$task->parent_id){
               SpecialTask::where('parent_id', $task->id)->update(['is_canceled' => 1]);
            }
            else{
               $parent = SpecialTask::where('id', $task->parent_id)->first();
               $parent->update(['is_canceled' => 1]);

               SpecialTask::where('parent_id', $parent->id)->update(['is_canceled' => 1]);

            } 
            Logs::createLog(
                'الغاء مهمة خاصة مع التكرار',
                'الغاء مهمة خاصة مع التكرار باسم ' . $task->name,
                'special_tasks'
            );
        } else {
            Logs::createLog(
                'الغاء مهمة خاصة',
                'الغاء مهمة خاصة باسم ' . $task->name,
                'special_tasks'
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => __('messages.task_canceled'),
        ], 200);
    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
        ], 200);
    }
}

//   public function restoreSpecialTask(Request $request)
//     {
//         try {
//             $data = $request->validate([
//                 'special_task_id' => 'required|exists:special_tasks,id',
//             ]);

//             $task = SpecialTask::findOrFail($data['special_task_id']);
//             $task->update(['is_canceled'=>0]);
//             Logs::createLog('استعادة مهمة خاصة','استعادة مهمة خاصة باسم'.' '.$task->name,'special_tasks');


//             return response()->json([
//                 'status' => 'success',
//                 'message' => __('messages.task_restored'),
//             ], 200);
//         } catch (ValidationException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.validation_failed'),
//             ], 200);
//         }
        
//         catch (ModelNotFoundException $e) {
//         return response()->json([
//             'status' => 'error',
//             'message' => __('messages.task_not_found'),
//         ], 200);
//     }
//         catch (\Exception $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.something_wrong'),
//             ], 200);
//         }
//     }

// private function createHelper(Model $task, string $recurrence): void
// {
//     $recurrenceCounts = [
//         'daily' => 29,   // 30 total (main + 29 repeats)
//         'weekly' => 3,   // 4 total (main + 3 repeats)
//         'monthly' => 0,  // 3 total (main + 2 repeats)
//         'noRepeat' => 0,
//     ];

//     $count = $recurrenceCounts[$recurrence] ?? 0;
//     $start = \Carbon\Carbon::parse($task->start_date);
//     $end = \Carbon\Carbon::parse($task->end_date);

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
//         $data['start_date'] = $newStart->format('Y-m-d H:i:s');
//         $data['end_date'] = $newEnd->format('Y-m-d H:i:s');

//         $task::create($data);
//     }
// }

public static function createHelper(Model $task, string $recurrence): void
{
    $start = Carbon::parse($task->start_date);
    $end = Carbon::parse($task->end_date);
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

    // // ✅ Handle special case: ensure an instance on the END DATE if it matches recurrence
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
    $data['start_date'] = $newStart->format('Y-m-d H:i:s');
    $data['end_date'] = $mainEnd->format('Y-m-d H:i:s'); // always same as main
    $newTask = $task::create($data);

    $subtasks = $task->subTasks()->get();

    foreach ($subtasks as $subtask) {
            $subData = $subtask->replicate()->toArray();
            $subData['special_task_id'] = $newTask->id; // link to new recurrent task
            SubTask::create($subData);
        }
}

public function createSpecialTask(Request $request){
    try{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
        'notes' => ['nullable', 'string'],
        'admin_img' => ['nullable', 'array'],
        'admin_img.*' => ['required', 'image'],

        'start_date' => ['required', 'date', 'before_or_equal:end_date'],
        'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        'task_recurrence' => ['required', 'string','in:noRepeat,daily,weekly,monthly'],
            'task_recurrence_time' => [
                'nullable',
                'array',
               // 'required_unless:task_recurrence,noRepeat',
            ],
            'task_recurrence_time.*' => [
               'required', 'string',
               // 'required_unless:task_recurrence,noRepeat',
            ],

        'audio' => 'nullable|file',

        'sub_special_tasks' => ['nullable', 'array'],
        'sub_special_tasks.*.name' => ['required', 'string', 'max:255'],
        'sub_special_tasks.*.description' => ['nullable', 'string'],
        'sub_special_tasks.*.admin_subtask_img' => ['nullable', 'array'],
        'sub_special_tasks.*.admin_subtask_img.*' => ['required', 'image'],

        'sub_special_tasks.*.force_employee_to_add_img_for_sub_task' => ['required','in:1,0'],

    ]);

        $data['not_shown_for_employee'] = $request->boolean('not_shown_for_employee');
        $data['force_employee_to_add_img'] = $request->boolean('force_employee_to_add_img');
       if($request->task_recurrence === 'daily'){
            $data['task_recurrence_time'] = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        }
        elseif($request->task_recurrence === 'monthly'){
            // Automatically get the day of the month from start_time
            $startDay = (int) \Carbon\Carbon::parse($request->start_date)->format('d');
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
        if ($request->hasFile('audio')) {
            $audio = $request->file('audio');
            $audioName = $audio->getClientOriginalName();
            $audio->move(public_path($this->specialTaskAudiosPath), $audioName);

            $data['audio'] = $audioName;
        }
        $adminImages = EmployeeTasks::mediaHelper(
            $request,
            $this->specialTaskAdminImagesPath,
        );
        $data['admin_img'] = $adminImages;
        $specialTask = SpecialTask::create($data);



if ($request->has('sub_special_tasks')) {
    foreach ($request->sub_special_tasks as $index => $subTask) {
        $subImagesNames = [];

        $subTaskData = [
            'name' => $subTask['name'],
            'description' => $subTask['description'] ?? null,
            'special_task_id' => $specialTask->id,
            'force_employee_to_add_img_for_sub_task' => $subTask['force_employee_to_add_img_for_sub_task'],
        ];

        // Check if an image was uploaded for this subtask
        if ($request->hasFile("sub_special_tasks.$index.admin_subtask__img")) {
            foreach($request->file("sub_special_tasks.$index.admin_subtask__img") as $image){
            $imageName = $image->getClientOriginalName();

            $destinationPath = public_path($this->subSpecialTaskAdminImagesPath);
            if (!file_exists($destinationPath . '/' . $imageName)) {
                $image->move($destinationPath, $imageName);
            }

            $subImagesNames[] = $imageName; // Make sure this column exists in your DB
        }
       }
       $subTaskData['admin_img'] = $subImagesNames;

        SubTask::create($subTaskData);
    }
}

        $this->createHelper($specialTask,$request->task_recurrence);

    Logs::createLog('اضافة مهمة خاصة','اضافة مهمة خاصة باسم'.' '.$specialTask->name,'special_tasks');


            return response()->json([
                'status' => 'success',
                'message' => __('messages.task_created'),
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
            ], 200);
        }

    
}

    public function showSpecialTaskDetails(Request $request){
    try {
        $data = $request->validate([
            'special_task_id' => 'required|exists:special_tasks,id',
        ]);

    $task = SpecialTask::with('subTasks')
   // ->select('id', 'name', 'description', 'admin_img', 'task_recurrence', 'task_recurrence_time')
    ->findOrFail($request->special_task_id);

    $task->admin_img = array_map(function($img){
        return 'public/'.$this->specialTaskAdminImagesPath.'/'.$img;
    }, $task->admin_img ?? []);

    $task->subTasks->transform(function ($subTask) {
        if ($subTask->admin_img) {
                    $subTask->admin_img = collect($subTask->admin_img)->map(function ($img) {
                        return 'public/'.$this->subSpecialTaskAdminImagesPath.'/'.$img;
                         })->toArray();
                }
                return $subTask;
            });
    $task['start_time'] = $task->start_date;
    $task['end_time'] = $task->end_date;
    $task['audio'] = $task->audio? 'public/'.$this->specialTaskAudiosPath.'/'.$task->audio:'no audio';

    $task->makeHidden(['start_date','end_date']);
        return response()->json([
            'status' => 'success',
            'special_task' => $task,


        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    } catch (\Exception $e) {
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
            'sub_task_id'=>'required|exists:sub_tasks,id',
        ]);

        $subTask = SubTask::findOrFail($request->sub_task_id);
        $subTask->update(['status'=>'completed']);

        Logs::createLog('اكمال مهمة خاصة فرعية','اكمال مهمة خاصة فرعية باسم'.' '.$subTask->name,'special_tasks');

        
        $allSubTasks = SubTask::where('special_task_id',$subTask->special_task_id)
        ->where('status', '!=', 'completed')
        ->exists();
        if(!$allSubTasks){
            $specialTask = SpecialTask::findOrFail($subTask->special_task_id);
            $specialTask->update(['status'=>'completed']);

        }
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
    public function changeSpecialTaskToCompleted(Request $request){
        try{
        $request->validate([
            'special_task_id'=>'required|exists:special_tasks,id',
        ]);

        $task = SpecialTask::findOrFail($request->special_task_id);
        $hasIncomplete = $task->subTasks()
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasIncomplete) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.can_not_complete_special_task'),
            ], 200);
        }
        $task->update(['status'=>'completed']);
        Logs::createLog('اكمال مهمة خاصة','اكمال مهمة خاصة باسم'.' '.$task->name,'special_tasks');

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
    // change end date
    public function transerTask(Request $request){
        try{
            $request->validate([
                'special_task_id'=>'required|exists:special_tasks,id',
                'end_date' => ['required', 'date'],

            ]);
        $task = SpecialTask::findOrFail($request->special_task_id);
        $endDate = Carbon::parse($request->end_date);
        $startDate = Carbon::parse($task->start_date);

        if ($endDate->lt($startDate)) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.end_date_before_start'),
            ], 400);
        }
        $task->update(['end_date'=>$request->end_date]);
        if(!$task->parent_id){
            SpecialTask::where('parent_id',$task->id)->delete();
            $this->createHelper($task,$task->task_recurrence);
        }
        Logs::createLog('نقل مهمة خاصة','تم نقل مهمة خاصة باسم'.' '.$task->name
        .' '.'لتاريخ'.$request->end_date,'special_tasks');

        return response()->json([
            'status'=>'success',
            'message' => __('messages.task_transfered'),
        ],200);

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
            'error' => $e->errors(),
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




public function updateTask(Request $request)
{
    try {
        $data = $request->validate([
            'special_task_id'=>['required','exists:special_tasks,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
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

            'sub_special_tasks' => ['nullable', 'array'],
            'sub_special_tasks.*.id' => ['nullable', 'exists:sub_tasks,id'],
            'sub_special_tasks.*.name' => ['nullable', 'string', 'max:255'],
            'sub_special_tasks.*.description' => ['nullable', 'string'],
            'sub_special_tasks.*.admin_subtask_img' => ['nullable', 'array'],
            'sub_special_tasks.*.admin_subtask_img.*' => ['required', 'image'],
 
            'audio' => 'nullable',

        ]);

        // ✅ Always update the parent if the task is a recurrence
        $specialTask = SpecialTask::findOrFail($request->special_task_id);
        if ($specialTask->parent_id) {
            $specialTask = SpecialTask::findOrFail($specialTask->parent_id);
        }

        $finalData = $request->except(['special_task_id','sub_special_tasks']);

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


        $adminUpdatedImages = CommonUse::handleImageUpdate($request,'admin_img',$this->specialTaskAdminImagesPath,$specialTask->admin_img);
        $finalData['admin_img'] = $adminUpdatedImages;

        if($request->audio){
            if(is_string($request->audio)){
                $finalData['audio'] = $specialTask->audio??null;
            }
            elseif($request->hasFile('audio')){
                $audio = $request->file('audio');
                $audioName = $audio->getClientOriginalName();
                $audio->move(public_path($this->specialTaskAudiosPath), $audioName);

                $finalData['audio'] = $audioName;
  
            }
        }

        $specialTask->update($finalData);


        if ($request->has('sub_special_tasks')) {

            $empT = SpecialTask::findOrFail($request->special_task_id);
            
                $existingSubTaskIds = $empT->subTasks()->pluck('id')->toArray();
                $sentSubTasks = $data['sub_special_tasks'];
                $keepIds = [];

                foreach ($sentSubTasks as $index => $subTaskData) {
                    if (isset($subTaskData['id'])) {
                        // Existing subtask 

                            $keepIds[] = $subTaskData['id'];
                        }
                    else {
                        $subImagesNames = [];
                        if ($request->hasFile("sub_special_tasks.$index.admin_subtask_img")) {
                            foreach ($request->file("sub_special_tasks.$index.admin_subtask_img") as $file) {
                                $fullName = $file->getClientOriginalName();
                                $file->move(public_path($this->subSpecialTaskAdminImagesPath.'/'), $fullName);
                                $subImagesNames[] = $fullName;
                            }
                        }
                        // New subtask → create
                        $newSubTask = SubTask::create([
                            'special_task_id' => $empT->id,
                            'name' => $subTaskData['name'],
                            'description' => $subTaskData['description'] ?? null,
                            'admin_img' => $subImagesNames,

                        ]);
                        $keepIds[] = $newSubTask->id;
                    }
                }

                // Delete subtasks not included
                $deleteIds = array_diff($existingSubTaskIds, $keepIds);
                if (!empty($deleteIds)) {
                    SubTask::whereIn('id', $deleteIds)->delete();
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
                $parent = SpecialTask::findOrFail($empT->parent_id);
                $parent->subTasks()->delete();
                foreach ($updatedSubTasks as $subTask) {
                        $newSub = $subTask->replicate()->toArray();
                        $newSub['special_task_id'] = $parent->id;
                        SubTask::create($newSub);
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
        SpecialTask::where('parent_id', $specialTask->id)->delete();

            // Create new recurrence children
        $this->createHelper($specialTask, $specialTask->task_recurrence);
        Logs::createLog(
            'تعديل مهمة خاصة',
            'تم تعديل مهمة خاصة باسم ' .' '. $specialTask->name
             
            ,
            'special_tasks'
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

}