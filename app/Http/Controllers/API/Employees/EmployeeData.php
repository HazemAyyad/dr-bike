<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Services\EmployeePointsService;
use App\Support\DashboardSectionBadges;
use App\Support\EmployeeVisibleTasks;
use App\Support\EmployeeWorkingDays;
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

            $employee = EmployeeDetail::where('id', $user->employee->id)
            ->with('user:id,name')
            ->with(['permissions.permission:id,name'])
            ->first(['id', 'user_id', 'number_of_work_hours', 'hour_work_price', 'debts', 'salary', 'weekly_days_off']);

            $employee->permissions = $employee->permissions->map(function ($perm) {
                    return [
                        'id' => $perm->permission->id,
                        'name' => $perm->permission->name,
                    ];
                });
            $employee->unsetRelation('permissions');

            $weeklyOff = EmployeeWorkingDays::weeklyDaysOff($employee);
            $employee['weekly_days_off'] = array_values($weeklyOff);
            $employee['points'] = app(EmployeePointsService::class)->getTotalNetPoints((int) $employee->id);

            $employee['tasks'] = EmployeeVisibleTasks::dashboardPayload($employee->id);
            $employee['today_tasks_summary'] = EmployeeVisibleTasks::todaySummaryForEmployee($employee->id);
            $employee['dashboard_badges'] = DashboardSectionBadges::forUser($user);

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
