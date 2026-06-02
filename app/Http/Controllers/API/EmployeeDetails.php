<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeDetailResource;
use App\Mail\NewEmployeeAccountMail;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Models\EmployeeOrder;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Models\Reward;
use App\Models\User;
use App\Services\AttendanceSalaryService;
use App\Services\EmployeePointsService;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeDetails extends Controller
{
    /**
     * @return string[]
     */
    private function normalizeWeeklyDaysOff($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $out = [];
        foreach ($value as $v) {
            if (! is_string($v)) {
                continue;
            }
            $day = strtolower(trim($v));
            if (in_array($day, $allowed, true)) {
                $out[] = $day;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttendanceDayFinancialFields(?EmployeeAttendance $legacyAttendance, EmployeeDetail $employee, ?int $overriddenWorkedMinutes = null): array
    {
        /** @var AttendanceSalaryService $salaryService */
        $salaryService = app(AttendanceSalaryService::class);

        $contractRequiredMinutes = ($salaryService->calculateDailyOvertime($employee, 0))['required_minutes'];

        $workedMinutes = 0;
        if ($legacyAttendance !== null) {
            $workedMinutes = (int) ($legacyAttendance->worked_minutes ?? 0);
        }
        if ($overriddenWorkedMinutes !== null) {
            $workedMinutes = max(0, (int) $overriddenWorkedMinutes);
        }

        $requiredMinutes = $contractRequiredMinutes;
        $normalMinutes = 0;
        $overtimeMinutes = 0;

        // Prefer persisted snapshots when present; otherwise synthesize split from scans/totals live.
        if ($legacyAttendance && $legacyAttendance->normal_minutes !== null && $legacyAttendance->overtime_minutes !== null) {
            if ($legacyAttendance->required_minutes !== null && $legacyAttendance->required_minutes > 0) {
                $requiredMinutes = (int) $legacyAttendance->required_minutes;
            }
            $normalMinutes = (int) ($legacyAttendance->normal_minutes ?? 0);
            $overtimeMinutes = (int) ($legacyAttendance->overtime_minutes ?? 0);
        } else {
            $derived = $salaryService->calculateDailyOvertime($employee, (int) $workedMinutes);
            $requiredMinutes = (int) $derived['required_minutes'];
            $normalMinutes = (int) $derived['normal_minutes'];
            $overtimeMinutes = (int) $derived['overtime_minutes'];
        }

        $salary = $salaryService->calculateSalary($employee, (int) $normalMinutes, (int) $overtimeMinutes);

        return [
            'worked_hours' => $salaryService->formatHours((int) $workedMinutes),
            'required_hours' => $salaryService->formatHours((int) $requiredMinutes),
            'normal_hours' => $salaryService->formatHours((int) $normalMinutes),
            'overtime_hours' => $salaryService->formatHours((int) $overtimeMinutes),
            'normal_salary' => number_format((float) $salary['normal_salary'], 2, '.', ''),
            'overtime_salary' => number_format((float) $salary['overtime_salary'], 2, '.', ''),
            'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
        ];
    }


    

     public function employeesList()
    {
        try {
            $employees = EmployeeDetail::with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->get(['id', 'hour_work_price', 'points', 'user_id','employee_img', 'start_work_time']);

            $now = Carbon::now();
            $pointsService = app(EmployeePointsService::class);
            $summaries = $pointsService->getMonthlySummaryMany(
                $employees->pluck('id')->all(),
                (int) $now->year,
                (int) $now->month,
            );

            $formatted = $employees->map(function ($employee) use ($summaries) {
                $statuses = $this->getAttendanceStatuses($employee->id, $employee->start_work_time);
                $summary = $summaries[$employee->id] ?? [
                    'earned_points' => 0,
                    'deducted_points' => 0,
                    'net_points' => 0,
                    'reward_amount' => 0.0,
                    'reward_rule_id' => null,
                    'reward_status_label' => null,
                    'reward_status_color' => null,
                ];

                return [
                    'id' => $employee->id,
                    'employee_name' => $employee->user?->name,
                    'hour_work_price' => $employee->hour_work_price,
                    'points' => $employee->points,
                    'points_summary' => [
                        'earned_points' => (int) $summary['earned_points'],
                        'deducted_points' => (int) $summary['deducted_points'],
                        'net_points' => (int) $summary['net_points'],
                        'reward_amount' => number_format((float) $summary['reward_amount'], 2, '.', ''),
                        'reward_rule_id' => $summary['reward_rule_id'],
                        'reward_status_label' => $summary['reward_status_label'],
                        'reward_status_color' => $summary['reward_status_color'],
                    ],
                    'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',
                    'has_attended_today' => $statuses['has_attended_today'],
                    'is_working_now' => $statuses['is_working_now'],
                    'is_came_on_time' => $statuses['is_came_on_time'],
                ];
            });

            return response()->json(['status' => 'success',
             'employees' => $formatted,

             ]
             , 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

     public function workingTimes()
    {
        try {
            $employees = EmployeeDetail::with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->get(['user_id', 'id', 'start_work_time', 'end_work_time', 'number_of_work_hours','employee_img']);

            $formatted = $employees->map(function ($employee) {
                $statuses = $this->getAttendanceStatuses($employee->id, $employee->start_work_time);
                return [
                    'id' => $employee->id,
                    'user_name' => $employee->user?->name,
                    'start_work_time' => \Carbon\Carbon::parse($employee->start_work_time)->format('g:i A'),
                    'end_work_time' => \Carbon\Carbon::parse($employee->end_work_time)->format('g:i A'),
                    'number_of_work_hours' => $employee->number_of_work_hours,
                    'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',
                    'has_attended_today' => $statuses['has_attended_today'],
                    'is_working_now' => $statuses['is_working_now'],
                    'is_came_on_time' => $statuses['is_came_on_time'],
                ];
            });

            return response()->json(['status' => 'success',
             'working_times' => $formatted,

             ]
             , 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

   public function financialDues()
    {
        try {
            $employees = EmployeeDetail::with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->get(['id', 'salary', 'debts', 'user_id','employee_img']);

            $formatted = $employees->map(function ($employee) {
                return [
                    'id' => $employee->id,
                    'user_name' => $employee->user?->name,
                    'salary' => $employee->salary,
                    'debts' => $employee->debts,
                    'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',

                ];
            });

            return response()->json(['status' => 'success',
             'financial_dues' => $formatted,

             ]
             , 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    private function getAttendanceStatuses($employeeId, $startWorkTime) {
        $today = Carbon::now()->toDateString();
        $scans = EmployeeAttendanceScan::where('employee_id', $employeeId)
                    ->whereDate('work_date', $today)
                    ->orderBy('id', 'asc')   // ترتيب حسب ID لضمان الترتيب الصحيح
                    ->get();
        
        $has_attended_today = false;
        $is_working_now = false;
        $is_came_on_time = false;

        if ($scans->isNotEmpty()) {
            $has_attended_today = true;
            $firstScanIn = $scans->where('direction', 'in')->first();
            
            $lastScan = $scans->last();
            if ($lastScan && strtolower(trim($lastScan->direction)) === 'in') {
                $is_working_now = true;
            }

            if ($firstScanIn && $startWorkTime) {
                $startTimeStr = $today . ' ' . $startWorkTime;
                $startTime = Carbon::parse($startTimeStr);
                $firstScanTime = Carbon::parse($firstScanIn->scanned_at);
                
                if ($firstScanTime->lessThanOrEqualTo($startTime->addMinutes(15))) {
                    $is_came_on_time = true;
                }
            }
        } else {
            $legacy = EmployeeAttendance::where('employee_id', $employeeId)
                        ->whereDate('date', $today)
                        ->first();
            if ($legacy) {
                $has_attended_today = true;
                if ($legacy->arrived_at && ($legacy->left_at === null || $legacy->left_at === '00:00:00' || trim($legacy->left_at) === '')) {
                    $is_working_now = true;
                }
                if ($legacy->arrived_at && $startWorkTime) {
                    $startTimeStr = $today . ' ' . $startWorkTime;
                    $startTime = Carbon::parse($startTimeStr);
                    $firstScanTime = Carbon::parse($legacy->arrived_at);
                    if ($firstScanTime->lessThanOrEqualTo($startTime->addMinutes(15))) {
                        $is_came_on_time = true;
                    }
                }
            }
        }

        return [
            'has_attended_today' => $has_attended_today,
            'is_working_now' => $is_working_now,
            'is_came_on_time' => $is_came_on_time,
        ];
    }

private function getEmployeeFinancialData($employeeId)
{
    $employee = EmployeeDetail::with('user:id,name')->findOrFail($employeeId);
    $pointsRevenue = ($employee->points / 50) * $employee->hour_work_price;

    return [
        'employee_id' => $employee->id,
        'employee_name' => $employee->user->name,
        'salary' => $employee->salary,
        'debts' => $employee->debts,
        'points' => $employee->points,
        'hour_work_price' => $employee->hour_work_price,
        'total_work_hours' => $employee->total_work_hours,
        'number_of_work_hours' => $employee->number_of_work_hours,
        'points_revenue' => $pointsRevenue,
        'total' => round(($employee->salary + $pointsRevenue) - $employee->debts),
    ];
}

private function getEmployeeAdvancesData(EmployeeDetail $employee, Carbon $month): array
{
    $start = $month->copy()->startOfMonth();
    $end = $month->copy()->endOfMonth();

    $orders = EmployeeOrder::query()
        ->where('employee_id', $employee->id)
        ->where('type', 'loan')
        ->whereBetween('created_at', [$start, $end])
        ->orderBy('created_at', 'desc')
        ->get();

    $advances = $orders->map(function (EmployeeOrder $order) {
        $created = Carbon::parse($order->created_at);

        return [
            'id' => $order->id,
            'status' => $order->status,
            'amount' => (float) ($order->loan_value ?? 0),
            'day' => $created->format('l'),
            'date' => $created->toDateString(),
            'time' => $created->format('h:i A'),
        ];
    })->values();

    $approvedTotal = $orders
        ->filter(fn ($order) => in_array($order->status, ['approved', 'paid'], true))
        ->sum(fn ($order) => (float) ($order->loan_value ?? 0));

    return [
        'employee' => [
            'id' => $employee->id,
            'name' => $employee->user?->name,
        ],
        'month' => $month->format('Y-m'),
        'advances' => $advances,
        'total' => (float) $orders->sum(fn ($order) => (float) ($order->loan_value ?? 0)),
        'approved_total' => (float) $approvedTotal,
    ];
}

private function getEmployeeMonthlyFinancialData($employeeId, ?string $monthValue = null, ?string $dateValue = null): array
{
    $employee = EmployeeDetail::with('user:id,name')->findOrFail($employeeId);

    /** @var AttendanceSalaryService $salaryService */
    $salaryService = app(AttendanceSalaryService::class);

    $selectedDate = null;
    if ($dateValue) {
        $selectedDate = Carbon::createFromFormat('Y-m-d', $dateValue)->startOfDay();
    }

    $month = $selectedDate
        ? $selectedDate->copy()->startOfMonth()
        : ($monthValue
            ? Carbon::createFromFormat('Y-m', $monthValue)->startOfMonth()
            : Carbon::now()->startOfMonth());

    $isDayView = $selectedDate !== null;

    if ($isDayView) {
        $start = $selectedDate->copy()->startOfDay();
        $end = $selectedDate->copy()->endOfDay();
    } else {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
    }

    $workedMinutes = $salaryService->sumWorkedMinutesBetween($employee->id, $start, $end);
    $salaryRow = $salaryService->buildAttendanceReportRow(
        $employee,
        $start,
        $end,
        $workedMinutes,
        (int) $month->month,
        (int) $month->year
    );

    $scanDates = EmployeeAttendanceScan::query()
        ->where('employee_id', $employee->id)
        ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
        ->distinct()
        ->pluck('work_date')
        ->map(fn ($date) => $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString());

    $legacyDates = EmployeeAttendance::query()
        ->where('employee_id', $employee->id)
        ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
        ->where('worked_minutes', '>', 0)
        ->pluck('date')
        ->map(fn ($date) => $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString());

    $attendanceDates = $scanDates->merge($legacyDates)->unique()->values();
    $lateDays = 0;
    $delayMinutes = 0;

    foreach ($attendanceDates as $dateStr) {
        $firstScan = EmployeeAttendanceScan::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $dateStr)
            ->where('direction', 'in')
            ->orderBy('scanned_at')
            ->first();

        $firstCheckIn = $firstScan?->scanned_at;
        if (! $firstCheckIn) {
            $legacy = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $dateStr)
                ->first();
            if ($legacy?->arrived_at) {
                $firstCheckIn = Carbon::parse($dateStr.' '.$legacy->arrived_at);
            }
        }

        if ($employee->start_work_time && $firstCheckIn) {
            $allowedStart = Carbon::parse($dateStr.' '.$employee->start_work_time)->addMinutes(15);
            if (Carbon::parse($firstCheckIn)->gt($allowedStart)) {
                $lateDays++;
                $delayMinutes += $allowedStart->diffInMinutes(Carbon::parse($firstCheckIn));
            }
        }
    }

    $rewardAmount = (float) ($salaryRow['reward_amount'] ?? 0);

    if ($isDayView) {
        $advancesTotal = 0.0;
    } else {
        $advancesData = $this->getEmployeeAdvancesData($employee, $month);
        $advancesTotal = (float) $advancesData['approved_total'];
    }

    $grossEntitlement = (float) ($salaryRow['total_salary'] ?? 0) + $rewardAmount;
    $finalNet = $grossEntitlement - $advancesTotal;

    $selectedMonthLabel = $isDayView
        ? $selectedDate->format('l, F j, Y')
        : $month->format('F Y');

    return array_merge($this->getEmployeeFinancialData($employeeId), [
        'view' => $isDayView ? 'day' : 'month',
        'month' => $month->format('Y-m'),
        'selected_date' => $isDayView ? $selectedDate->toDateString() : null,
        'selected_month' => $selectedMonthLabel,
        'base_salary' => $employee->salary !== null ? number_format((float) $employee->salary, 2, '.', '') : null,
        'attendance_days' => $attendanceDates->count(),
        'absent_days' => max(0, (int) $salaryRow['required_working_days'] - $attendanceDates->count()),
        'late_days' => $lateDays,
        'delay_minutes' => $delayMinutes,
        'delay_hours' => $salaryService->formatHours((int) $delayMinutes),
        'overtime_hours' => $salaryRow['overtime_hours'] ?? '0.00',
        'overtime_salary' => $salaryRow['overtime_salary'] ?? '0.00',
        'normal_salary' => $salaryRow['normal_salary'] ?? '0.00',
        'period_salary' => $salaryRow['total_salary'] ?? '0.00',
        'deductions' => number_format($advancesTotal, 2, '.', ''),
        'bonuses' => number_format($rewardAmount, 2, '.', ''),
        'additions' => number_format($rewardAmount, 2, '.', ''),
        'advances' => number_format($advancesTotal, 2, '.', ''),
        'gross_entitlement' => number_format($grossEntitlement, 2, '.', ''),
        'final_net_entitlement' => number_format($finalNet, 2, '.', ''),
        'total' => number_format($finalNet, 2, '.', ''),
        'attendance_summary' => $salaryRow,
    ]);
}


    public function showFinancialDetails(Request $request)
{
    try {
        $request->validate([
            'employee_id' => 'required|exists:employee_details,id',
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $data = $this->getEmployeeMonthlyFinancialData(
            $request->employee_id,
            $request->month,
            $request->date
        );
        $employee = EmployeeDetail::findOrFail($request->employee_id);
        return response()->json([
            'status'=>'success',
            'financial_details' => $data,
            'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',

        ],200);

    } catch (ValidationException $e) {
        return response([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response([
            'status' => 'error',
            'message' => __('messages.employee_not_found')
        ], 200);
    } catch (\Exception $e) {
        return response([
            'status' => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
}

    public function paySalary(Request $request){
     try{
        $request->validate([
            'employee_id' => 'required|exists:employee_details,id',
            'salary_to_pay' =>'required|numeric|min:1',
        ]);

        $employee = EmployeeDetail::findOrFail($request->employee_id);
        $data = $this->getEmployeeFinancialData($employee->id);
        $salary_to_pay = $request->salary_to_pay;


        if($data['total'] <=0){
            $employee->update([
                'total_work_hours' => 0,
                'salary' => 0 ,
                'points' => 0,
                'debts' => ($data['debts'] - ($data['salary'] + $data['pointsRevenue'])) + $request->salary_to_pay,
            ]);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.salary_paid')
            ],200);
        }
        
            $employee->update([
                'debts'=> 0 ,
                'total_work_hours' => 0,
                'salary' => 0 ,
                'points' => 0,
            ]); 
            $employee->debts -= ($data['total'] - $salary_to_pay);
            $employee->save();

            return response()->json([
                'status'=>'success',
                'message' => __('messages.salary_paid')
            ],200);
        
     }

        catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.something_wrong')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }

}
    public function addEmployee(Request $request){
        try {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => [
                        'required',
                        'regex:/^\+\d{3}\ \d{9}$/',
                        'unique:users,phone',
                    ],
            'sub_phone' => [
                        'nullable',
                        'regex:/^\+\d{3}\ \d{9}$/',
                        'different:phone', // Must not be the same as phone
                    ],
            'hour_work_price' => ['required','numeric','min:0'],
            'overtime_work_price' => ['required','numeric','min:0'],
            'number_of_work_hours'=> ['required','integer','min:1'],
            'start_work_time' => ['required', 'date_format:H:i'],
            'employee_img' => ['nullable','array'],
            'employee_img.*' => ['image'],

            'document_img.*' => ['nullable','array'],
            'document_img.*' => ['image'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],

            'weekly_days_off' => ['nullable', 'array'],
            'weekly_days_off.*' => ['in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],

            // Fingerprint (optional)
            'fingerprint_enabled' => ['nullable', 'boolean'],
            'device_user_id' => ['nullable', 'string', 'max:120'],

        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'type'=>'employee',
            'phone' => $data['phone'],
            'sub_phone' => $data['sub_phone']?? null,

        ]);

        Mail::to($user->email)->send(new NewEmployeeAccountMail($user->email, $data['password']));

        $employeeImage = $this->uploadImages($request, 'employee_img' , 'EmployeeImages');
        $documentImage =  $this->uploadImages($request, 'document_img' , 'EmployeeDocumetImages');
       
        $start = Carbon::createFromFormat('H:i', $data['start_work_time']);
        $end = $start->copy()->addHours($data['number_of_work_hours']);
        $endTime = $end->format('H:i'); // Will match TIME format in DB
      
        $employee = EmployeeDetail::create([
            'user_id' => $user->id,
            'hour_work_price' => $data['hour_work_price'],
            'overtime_work_price' => $data['overtime_work_price'],
            'number_of_work_hours' => $data['number_of_work_hours'],
            'start_work_time' => $data['start_work_time'],
            'end_work_time' => $endTime,
            'weekly_days_off' => $this->normalizeWeeklyDaysOff($data['weekly_days_off'] ?? null),
            'employee_img' => $employeeImage,
            'document_img' => $documentImage,
            'fingerprint_enabled' => (bool) ($data['fingerprint_enabled'] ?? false),
            'device_user_id' => $data['device_user_id'] ?? null,

        ]);


        if (!empty($data['permissions'])) {
            foreach ($data['permissions'] as $permissionId) {
                EmployeePermission::create([
                    'employee_id' => $employee->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
       
        Logs::createLog('اضافة موظف جديد','اضافة الموظف'.' '.$request->name,'employees');

        return response([
            'status' => 'success',
            'message' => __('messages.employee_created_successfully'),
            'employee_id' => $employee->id,
        ], 200);
        } catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.create_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.failed_to_create_employee')], 200);
        }    
    }

    private function uploadImages(Request $request,String $field, String $path){
        $data = [];
         if ($request->hasFile($field)) {
            foreach($request->file($field) as $file){
                
                $imageName = $file->getClientOriginalName();

                $destinationPath = public_path($path); 
                if (!file_exists($destinationPath . '/' . $imageName)) {

                $file->move(public_path($path), $imageName);
                }
                $data[] = $imageName;

        }
      }
        return $data;
    }


    public function editEmployee(Request $request)
    {
        try{
            $request->validate(['employee_id' => ['required', 'exists:employee_details,id'],
        ]);
        $employee = EmployeeDetail::findOrFail($request->employee_id);
        $userId = $employee->user_id;
        $data = $request->validate([
            'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($userId),
                    ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                        'required',
                        'regex:/^\+\d{3}\ \d{9}$/',
                         Rule::unique('users', 'phone')->ignore($userId),
                    ],
            'sub_phone' => [
                        'nullable',
                        'regex:/^\+\d{3}\ \d{9}$/', ],    
            'hour_work_price' => ['required', 'numeric', 'min:0'],
            'overtime_work_price' => ['required', 'numeric', 'min:0'],
            'number_of_work_hours' => ['required', 'integer', 'min:0'],
            'start_work_time' => ['required', 'string', 'max:255'],

            'employee_img' => ['nullable','array'],
            'employee_img.*' => ['nullable'],

            'document_img.*' => ['nullable','array'],
            'document_img.*' => ['nullable'],


            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],

            'weekly_days_off' => ['nullable', 'array'],
            'weekly_days_off.*' => ['in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],

            // Fingerprint (optional)
            'fingerprint_enabled' => ['nullable', 'boolean'],
            'device_user_id' => ['nullable', 'string', 'max:120'],
        ]);
    
        $employee = EmployeeDetail::findOrFail($request['employee_id']);
        $user = $employee->user;
    
        // Exclude 'name' and 'employee_id' from the update data
        $updateData = Arr::except($data, ['name','phone','sub_phone']);

        $start = Carbon::createFromFormat('H:i', $updateData['start_work_time']);
        $end = $start->copy()->addHours($updateData['number_of_work_hours']);
        $updateData['end_work_time'] = $end->format('H:i'); 
        if (array_key_exists('weekly_days_off', $updateData)) {
            $updateData['weekly_days_off'] = $this->normalizeWeeklyDaysOff($updateData['weekly_days_off']);
        }

        $finalEmployeeImages = CommonUse::handleImageUpdate(
            $request,
            'employee_img',
            'EmployeeImages',
            $employee->employee_img ?? []
        );

        $finalDocumentImages = CommonUse::handleImageUpdate(
            $request,
            'document_img',
            'EmployeeDocumetImages',
            $employee->document_img ?? []
        );
        
        $finalData = array_merge($updateData,
        ['employee_img'=> $finalEmployeeImages],
        ['document_img'=> $finalDocumentImages]);
        // Update employee record
        $employee->update($finalData);
        $user->update([
             'email'=> $request->email,

            'name'=>$request->name,
            'phone'=>$request->phone,
            'sub_phone'=>$request->sub_phone,

        ]);

        $existingPermissionIds = EmployeePermission::where('employee_id', $employee->id)
            ->pluck('permission_id')
            ->toArray();

        $newPermissionIds = $request->input('permissions', []); // array or empty array if nothing selected

        $toAdd = array_diff($newPermissionIds, $existingPermissionIds);
        $toDelete = array_diff($existingPermissionIds, $newPermissionIds);

        // Delete unchecked permissions
        if (!empty($toDelete)) {
            EmployeePermission::where('employee_id', $employee->id)
                ->whereIn('permission_id', $toDelete)
                ->delete();
        }

        // Add newly checked permissions
         if (!empty($toAdd)) {
        foreach ($toAdd as $permissionId) {
            EmployeePermission::create([
                'employee_id' => $employee->id,
                'permission_id' => $permissionId,
            ]);
        }
    }

            Logs::createLog('تعديل بيانات موظف ','تعديل بيانات الموظف'.' '. $request->name,'employees');

            return response(['status' => 'success', 'message' => __('messages.employee_updated_successfully')], 200);
        
        } catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.update_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.failed_to_update_employee')], 200);
        }
    }


    /**
     * Soft delete an employee (and their linked user account). All
     * related records — attendance, points logs, tasks, orders, etc. —
     * stay intact in the database so historical reports keep working.
     * The employee simply disappears from active listings.
     */
    public function deleteEmployee(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => ['required', 'integer', 'exists:employee_details,id'],
            ]);

            $employee = EmployeeDetail::with('user')->findOrFail($request->employee_id);
            $employeeName = $employee->user?->name ?? '—';

            DB::transaction(function () use ($employee) {
                if ($employee->user) {
                    $employee->user->delete();
                }
                $employee->delete();
            });

            Logs::createLog(
                'حذف موظف',
                'تم حذف الموظف '.$employeeName,
                'employees',
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_deleted_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_not_found'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_delete_employee'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_delete_employee'),
            ], 200);
        }
    }


    // retrieve the permissions in the system
    public function allPermissions(){
        try{
            $permissions = Permission::get(['id','name','name_en']);
            return response()->json([
                'status' => 'success',
                'permissions of the system' => $permissions], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }        
    
    }

    // retrieve employee details and permissions
    public function getEmployeePermissions(Request $request){

        try{
            $request->validate([
                'employee_id'=>'required|exists:employee_details,id',
            ]);

            $employee = EmployeeDetail::findOrFail($request->employee_id);

            $employeePermissions = $employee->permissions->map(function($permission){

                return [
                    "permission_id" => $permission->permission->id,
                    "permission_name" => $permission->permission->name,
                    "permission_name_en" => $permission->permission->name_en,

                ];
            });

            $employeeRewardsAndPunishments = $employee->rewards->map(function($reward){
                return [
                    'points'=> $reward->points??0,
                    'notes' => $reward->notes?? 'no notes',
                    'type' => $reward->type??'unknown',
                ];
            });

            return response()->json(['status'=>'success',
            'employee_details' => new EmployeeDetailResource($employee),


            'permissions'=>$employeePermissions,
            'rewards_and_punishments' => $employeeRewardsAndPunishments,
        
        ],200);
        
        
        }

         catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }


    }


    



    // add points (reward)
    private function changePoints(Request $request,String $type){
        try{
            $request->validate([
                'employee_id' => ['required', 'exists:employee_details,id'],
                'points' =>'required|numeric|min:1',
                'notes' => 'nullable|string',
        ]);
        $employee = EmployeeDetail::findOrFail($request->employee_id);
        if($type==='add'){
            $employee->points += $request->points;

            Logs::createLog('اضافة نقاط','تم اضافة نقاط بعدد'
            .' '.$request->points.' '.'للموظف'.' '.$employee->user->name,'employees');
         }

        elseif($type==='minus'){
            $employee->points -= $request->points;

            Logs::createLog('خصم نقاط','تم خصم نقاط بعدد'
            .' '.$request->points.' '.'للموظف'.' '.$employee->user->name,'employees');
         }

         $employee->save();

         Reward::create([
            'employee_id' => $request->employee_id,
            'points' => $request->points,
            'notes' => $request->notes,
            'type' => $type,
         ]);
         return response()->json([
            'status'=>'success',
            'message' => __('messages.points_updated'),
         ]);

    }

       catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.update_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.failed_to_update_employee')], 200);
        }


    }


    public function addPoints(Request $request){
        return $this->changePoints($request,'add');
    }

    public function minusPoints(Request $request){
        return $this->changePoints($request,'minus');
    }



    // get report of employee data and attendance times
    public function employeeReportData(Request $request){
        try{
            $request->validate([
                'employee_id'=>'required|integer|exists:employee_details,id',
                'from_date' => 'required|date',
                'to_date' => 'required|date',

            ]);
            $employee = EmployeeDetail::with('user:id,name')->findOrFail($request->employee_id);
            $month = Carbon::parse($request->from_date)->startOfMonth();
            $attendances = $employee->attendances()
                ->whereBetween('date', [
                    Carbon::parse($request->from_date)->toDateString(),
                    Carbon::parse($request->to_date)->toDateString(),
                ])
                ->get();
        $rewards = $employee->rewards()
            ->whereBetween('created_at', [
                Carbon::parse($request->from_date)->startOfDay(),
                Carbon::parse($request->to_date)->endOfDay(),
            ])
            ->get();
            $financialData = $this->getEmployeeMonthlyFinancialData($employee->id, $month->format('Y-m'));
            $advancesData = $this->getEmployeeAdvancesData($employee, $month);
       // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.employee-report', [
            'attendances' => $attendances,
            'financialData' => $financialData,
            'rewards'=>$rewards,
            'advancesData' => $advancesData,
            'month' => $month->format('F Y'),

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

        // 🔹 Load fixed HTML into PDF
        $pdf = Pdf::loadHTML($reportHtml);

        return $pdf->download('employee-report.pdf');
        }

     catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.something_wrong')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    /**
     * سجل دوام الموظف (مسحات دخول/خروج) للعرض على الأدمن.
     */
    public function employeeAttendanceHistory(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|integer|exists:employee_details,id',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
            ]);

            $employee = EmployeeDetail::with('user:id,name')->findOrFail($request->employee_id);

            $from = $request->filled('from_date')
                ? Carbon::parse($request->from_date)->startOfDay()
                : now()->subDays(30)->startOfDay();
            $to = $request->filled('to_date')
                ? Carbon::parse($request->to_date)->endOfDay()
                : now()->endOfDay();

            $payload = $this->buildAttendanceHistoryPayload($employee, $from, $to);

            return response()->json(array_merge(['status' => 'success'], $payload), 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * سجل دوام المستخدم الحالي (موظف التطبيق).
     */
    public function employeeMyAttendanceHistory(Request $request)
    {
        try {
            $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
            ]);

            $empRow = $request->user()->employee;
            if (! $empRow) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.employee_not_found'),
                ], 200);
            }

            $employee = EmployeeDetail::with('user:id,name')->findOrFail($empRow->id);

            $from = $request->filled('from_date')
                ? Carbon::parse($request->from_date)->startOfDay()
                : now()->subDays(30)->startOfDay();
            $to = $request->filled('to_date')
                ? Carbon::parse($request->to_date)->endOfDay()
                : now()->endOfDay();

            $payload = $this->buildAttendanceHistoryPayload($employee, $from, $to);

            return response()->json(array_merge(['status' => 'success'], $payload), 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * @return array{employee: array<string, mixed>, days: array<int, array<string, mixed>>}
     */
    private function buildAttendanceHistoryPayload(EmployeeDetail $employee, Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $scanDates = EmployeeAttendanceScan::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$fromStr, $toStr])
            ->distinct()
            ->pluck('work_date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->format('Y-m-d') : (string) $d);

        $attDates = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$fromStr, $toStr])
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->format('Y-m-d') : (string) $d);

        $allDates = $scanDates->merge($attDates)->unique()->sort()->values();

        /** @var AttendanceSalaryService $salaryService */
        $salaryService = app(AttendanceSalaryService::class);
        $expectedMinutes = (int) (($salaryService->calculateDailyOvertime($employee, 0))['required_minutes']);

        $days = [];
        foreach ($allDates as $dateStr) {
            $dayScans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $dateStr)
                ->orderBy('id')
                ->get();

            $legacy = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $dateStr)
                ->first();

            if ($dayScans->isEmpty() && ! $legacy) {
                continue;
            }

            $workedMinutes = 0;
            $awayMinutes = 0;
            $segments = [];
            $scansOut = [];
            $firstCheckIn = null;
            $lastCheckOut = null;
            $currentlyIn = false;

            if ($dayScans->isNotEmpty()) {
                $workedMinutes = EmployeeAttendanceScan::computeWorkedMinutes($dayScans);
                $awayMinutes = EmployeeAttendanceScan::computeAwayMinutes($dayScans);

                foreach ($dayScans as $s) {
                    $scansOut[] = [
                        'at' => $s->scanned_at->toIso8601String(),
                        'direction' => $s->direction,
                    ];
                }

                $firstInScan = $dayScans->firstWhere('direction', 'in');
                $firstCheckIn = $firstInScan?->scanned_at;

                $lastOutScan = $dayScans->where('direction', 'out')->last();
                $lastCheckOut = $lastOutScan?->scanned_at;

                $lastScan = $dayScans->last();
                $currentlyIn = $lastScan && $lastScan->direction === 'in';

                $pendingIn = null;
                foreach ($dayScans as $s) {
                    if ($s->direction === 'in') {
                        $pendingIn = $s;
                    } elseif ($s->direction === 'out' && $pendingIn !== null) {
                        $segments[] = [
                            'check_in_at' => $pendingIn->scanned_at->toIso8601String(),
                            'check_out_at' => $s->scanned_at->toIso8601String(),
                            'worked_minutes' => Carbon::parse($pendingIn->scanned_at)->diffInMinutes(Carbon::parse($s->scanned_at)),
                        ];
                        $pendingIn = null;
                    }
                }
                if ($pendingIn !== null) {
                    $segments[] = [
                        'check_in_at' => $pendingIn->scanned_at->toIso8601String(),
                        'check_out_at' => null,
                        'worked_minutes' => null,
                        'open' => true,
                    ];
                }
            } elseif ($legacy) {
                $workedMinutes = (int) ($legacy->worked_minutes ?? 0);
                if ($legacy->arrived_at) {
                    $firstCheckIn = Carbon::parse($dateStr.' '.$legacy->arrived_at);
                }
                if ($legacy->left_at) {
                    $lastCheckOut = Carbon::parse($dateStr.' '.$legacy->left_at);
                }
                $currentlyIn = $legacy->arrived_at && ! $legacy->left_at;
                if ($legacy->arrived_at && $legacy->left_at) {
                    $segments[] = [
                        'check_in_at' => Carbon::parse($dateStr.' '.$legacy->arrived_at)->toIso8601String(),
                        'check_out_at' => Carbon::parse($dateStr.' '.$legacy->left_at)->toIso8601String(),
                        'worked_minutes' => $workedMinutes,
                    ];
                }
            }

            $onTime = null;
            if ($employee->start_work_time && $firstCheckIn) {
                $scheduledStart = Carbon::parse($dateStr.' '.$employee->start_work_time);
                $onTime = $firstCheckIn->lte($scheduledStart);
            }

            $financialBaseAttendance = $legacy;
            // If scans exist but the daily row isn't flushed yet (should be rare),
            // still compute projections from live totals.
            $financial = $this->buildAttendanceDayFinancialFields($financialBaseAttendance, $employee, (int) $workedMinutes);

            // ── حساب الأوفر تايم بشكل صحيح ── (legacy KPI: minutes after scheduled end)
            // Kept as an extra field so old dashboards don't lose context.
            $scheduleOvertimeMinutes = 0;
            if ($employee->end_work_time) {
                $scheduledEnd = Carbon::parse($dateStr.' '.$employee->end_work_time);

                $lastMoment = null;
                if ($currentlyIn) {
                    $lastMoment = Carbon::now();
                } elseif ($lastCheckOut) {
                    $lastMoment = Carbon::parse($lastCheckOut);
                }

                if ($lastMoment && $lastMoment->gt($scheduledEnd)) {
                    $scheduleOvertimeMinutes = (int) $scheduledEnd->diffInMinutes($lastMoment);
                }
            }

            $days[] = array_merge([
                'date' => $dateStr,
                'first_check_in' => $firstCheckIn?->toIso8601String(),
                'last_check_out' => $lastCheckOut?->toIso8601String(),
                'currently_in' => $currentlyIn,
                'worked_minutes' => $workedMinutes,
                'away_minutes' => $awayMinutes,
                'expected_work_minutes' => $expectedMinutes,
                'on_time' => $onTime,
                // Back-compat: old meaning (after scheduled end). New contract-based overtime is in *_hours fields.
                'overtime_minutes' => $scheduleOvertimeMinutes,
                'contract_overtime_minutes' => (int) round(((float) ($financial['overtime_hours'] ?? 0)) * 60),
                'segments' => $segments,
                'scans' => $scansOut,
            ], $financial);
        }

        $summaryMonth = $to->month === $from->month && $to->year === $from->year
            ? $from->copy()
            : $to->copy();

        $periodMonthly = $salaryService->calculateMonthlyOvertime(
            $employee,
            $summaryMonth->copy()->startOfMonth(),
            null
        );

        // If the filtered range doesn't cover the full calendar month used for `monthly_*`, prorate required minutes for `range_*`.
        $monthDays = max(1, (int) $summaryMonth->daysInMonth);
        $overlapStart = max($summaryMonth->copy()->startOfMonth()->startOfDay(), $from->copy()->startOfDay());
        $overlapEnd = min($summaryMonth->copy()->endOfMonth()->startOfDay(), $to->copy()->startOfDay());
        $overlapDays = ($overlapEnd->lt($overlapStart)) ? 0 : ((int) $overlapStart->diffInDays($overlapEnd) + 1);
        $proration = $monthDays > 0 ? min(1, $overlapDays / $monthDays) : 1;

        $monthlyWorkedInRange = (int) EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$fromStr, $toStr])
            ->sum('worked_minutes');
        $monthlyRequiredProrated = (int) round($periodMonthly['monthly_required_minutes'] * $proration);
        $monthlyOvertimeProrated = max(0, $monthlyWorkedInRange - $monthlyRequiredProrated);

        $monthlySalary = $salaryService->calculateSalary(
            $employee,
            max(0, min($monthlyWorkedInRange, $monthlyRequiredProrated)),
            max(0, $monthlyOvertimeProrated)
        );

        $monthlyNormalMinutes = max(0, min((int) $periodMonthly['monthly_worked_minutes'], (int) $periodMonthly['monthly_required_minutes']));
        $monthlyOverMinutes = max(0, (int) $periodMonthly['monthly_overtime_minutes']);
        $monthlySalaryFull = $salaryService->calculateSalary($employee, $monthlyNormalMinutes, $monthlyOverMinutes);

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user?->name,
                'start_work_time' => $employee->start_work_time,
                'number_of_work_hours' => $employee->number_of_work_hours,
                'weekly_days_off' => collect(is_array($employee->weekly_days_off) ? $employee->weekly_days_off : [])
                    ->filter(fn ($v) => is_string($v))
                    ->map(fn ($v) => strtolower(trim($v)))
                    ->unique()
                    ->values()
                    ->all(),
            ],
            'monthly_summary' => array_merge([
                'month' => $summaryMonth->format('Y-m'),
                'month_start' => $summaryMonth->copy()->startOfMonth()->toDateString(),
                'month_end' => $summaryMonth->copy()->endOfMonth()->toDateString(),
                'weekly_days_off' => is_array($employee->weekly_days_off) ? array_values($employee->weekly_days_off) : [],
                'required_work_days_in_month' => $periodMonthly['required_work_days_in_month'],
                'monthly_working_days_count' => $periodMonthly['required_work_days_in_month'],
                'monthly_worked_minutes' => $periodMonthly['monthly_worked_minutes'],
                'monthly_required_minutes' => $periodMonthly['monthly_required_minutes'],
                'monthly_overtime_minutes' => $periodMonthly['monthly_overtime_minutes'],
                // Range-aware projections (helps when filtering partial months without breaking callers)
                'range_from' => $fromStr,
                'range_to' => $toStr,
                'range_worked_minutes' => max(0, $monthlyWorkedInRange),
                'range_required_minutes' => max(0, $monthlyRequiredProrated),
                'range_overtime_minutes' => max(0, (int) $monthlyOvertimeProrated),
                'range_worked_hours' => $salaryService->formatHours(max(0, $monthlyWorkedInRange)),
                'range_required_hours' => $salaryService->formatHours(max(0, $monthlyRequiredProrated)),
                'range_normal_hours' => $salaryService->formatHours(max(0, min($monthlyWorkedInRange, $monthlyRequiredProrated))),
                'range_overtime_hours' => $salaryService->formatHours(max(0, (int) $monthlyOvertimeProrated)),
                'range_normal_salary' => number_format((float) $monthlySalary['normal_salary'], 2, '.', ''),
                'range_overtime_salary' => number_format((float) $monthlySalary['overtime_salary'], 2, '.', ''),
                'range_total_salary' => number_format((float) $monthlySalary['total_salary'], 2, '.', ''),
            ], [
                'monthly_worked_hours' => $salaryService->formatHours((int) $periodMonthly['monthly_worked_minutes']),
                'monthly_required_hours' => $salaryService->formatHours((int) $periodMonthly['monthly_required_minutes']),
                'monthly_normal_hours' => $salaryService->formatHours(min(
                    (int) $periodMonthly['monthly_worked_minutes'],
                    (int) $periodMonthly['monthly_required_minutes']
                )),
                'monthly_overtime_hours' => $salaryService->formatHours((int) $periodMonthly['monthly_overtime_minutes']),
                'normal_salary' => number_format((float) $monthlySalaryFull['normal_salary'], 2, '.', ''),
                'overtime_salary' => number_format((float) $monthlySalaryFull['overtime_salary'], 2, '.', ''),
                'total_salary' => number_format((float) $monthlySalaryFull['total_salary'], 2, '.', ''),
            ]),
            'days' => $days,
        ];
    }

    public function viewTest(){
        $permissions = Permission::all();
        $employee = EmployeeDetail::findOrFail(2);

        $permissionIds = EmployeePermission::where('employee_id', $employee->id)
            ->pluck('permission_id')
            ->toArray();
        return view('test',compact('permissions','employee','permissionIds'));
    }




}
