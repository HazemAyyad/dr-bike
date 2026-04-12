<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class EmployeeData extends Controller
{
    public function getEmployeeData(Request $request){
        try{

            $user = $request->user();

            $employee = EmployeeDetail::where('id',$user->employee->id)
            ->with('user:id,name')
            ->with([
                    'tasks' => function ($q) {
                        //$q->whereNull('parent_id')
                        $q->where(function ($query) {
                                    $query->where('not_shown_for_employee', false) // show if false
                                            ->orWhere(function ($sub) {
                                                $sub->where('not_shown_for_employee', true)
                                                      ->whereDate('start_time', '<=', now()->toDateString());
                                            });
                                })
                ->select('id', 'employee_id', 'name', 'start_time', 'end_time', 'status', 'task_recurrence', 'task_recurrence_time');
                    }
                ])  
              ->with(['permissions.permission:id,name'])
            
           
            ->first(['id','user_id','number_of_work_hours','hour_work_price','debts','salary','points']);

            $employee->permissions = $employee->permissions->map(function ($perm) {
                    return [
                        'id' => $perm->permission->id,
                        'name' => $perm->permission->name,
                    ];
                });
            $employee->unsetRelation('permissions');

            // Recurrence filtering logic
       $filteredTasks = $employee->tasks->filter(function ($task) {
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
        })->values();

            $employee['tasks'] = $filteredTasks;
            $employee->unsetRelation('tasks');

            return response()->json([
                'status' => 'success',
                'employee_details' => $employee,
            ],200);
           
        }

         catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    //attendance and work hours
    // public function getAttendanceDetails(Request $request){
    //     try{

    //         $user = $request->user();
    //         $employee = $user->employee;
    //         $attendances = $employee->attendances;
    //         $formatted = $attendances->map(function($attendance){
    //             return [
    //                 'date' => $attendance->date? $attendance->date : $attendance->created_at->format('Y-m-d'),
    //                 'arrival_time' => $attendance->arrived_at? 
    //                 Carbon::createFromFormat('H:i:s', $attendance->arrived_at)->format('h:i A'):'no time stored',
    //                 'leaving_time' => $attendance->left_at? 
    //                 Carbon::createFromFormat('H:i:s', $attendance->left_at)->format('h:i A'):'no time stored',
    //                 'worked_hours' => $attendance->worked_minutes? ($attendance->worked_minutes/60):'no time stored',
    //             ];
    //         });
    //         return response()->json([
    //             'status'=>'success',
    //             'data' => $formatted,
    //         ]);
    //     }

    //     catch (ModelNotFoundException $e) {
    //         return response(['status' => 'error',
    //          'message' => __('messages.retrieve_data_error')], 200);
    //     }
    //     catch (QueryException $e) {
    //         return response(['status' => 'error',
    //          'message' => __('messages.retrieve_data_error')], 200);
    //     } catch (\Exception $e) {
    //         return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
    //     }
    // }

    //attendance details as a report
    public function attendanceReport(Request $request){
        try{

            $user = $request->user();
            $employee = $user->employee;
            $attendances = $employee->attendances;
                   // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.employee-attendance', [
            'attendances' => $attendances,
        ])->render();

        // 🔹 Fix Arabic text
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(
                substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
            );
            $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }

        $pdf = Pdf::loadHTML($reportHtml);

        return $pdf->download('employee-attendance.pdf');
        }

        catch (ModelNotFoundException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        }
        catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
    }

