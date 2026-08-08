<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendaceQr;
use App\Models\EmployeeDetail;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Services\AttendanceSalaryService;
use App\Support\AttendanceWorkDateResolver;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    private function addLabelToQrPng(string $pngBytes, string $label): string
    {
        // Best-effort: if GD isn't available, return original image.
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            return $pngBytes;
        }

        $src = @imagecreatefromstring($pngBytes);
        if ($src === false) {
            return $pngBytes;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $pad = 16; // padding around
        $labelHeight = 42; // space for label

        $canvas = imagecreatetruecolor($w + ($pad * 2), $h + $labelHeight + ($pad * 2));
        if ($canvas === false) {
            imagedestroy($src);
            return $pngBytes;
        }

        // white background
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagefilledrectangle($canvas, 0, 0, imagesx($canvas), imagesy($canvas), $white);

        // place qr
        imagecopy($canvas, $src, $pad, $pad, 0, 0, $w, $h);

        // simple built-in font text (no TTF dependency)
        $font = 3;
        $textW = imagefontwidth($font) * strlen($label);
        $textX = max($pad, (int) ((imagesx($canvas) - $textW) / 2));
        $textY = $pad + $h + 12;
        imagestring($canvas, $font, $textX, $textY, $label, $black);

        ob_start();
        imagepng($canvas);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($canvas);

        return $out !== '' ? $out : $pngBytes;
    }

    public function generateQr()
    {
        try {
            $codeText = Str::random(16);

            $qrImage = QrCode::format('png')
                ->size(300)
                ->generate($codeText);
            $label = now()->format('Y-m-d H:i');
            $qrImage = $this->addLabelToQrPng($qrImage, $label);

            $folderPath = public_path('qr');
            if (! file_exists($folderPath)) {
                mkdir($folderPath, 0777, true);
            }

            $fileName = 'attendance_qr_'.now()->format('Ymd_His').'_'.Str::random(6).'.png';
            $filePath = $folderPath.'/'.$fileName;

            file_put_contents($filePath, $qrImage);

            $record = AttendaceQr::create([
                'code_text' => $codeText,
                'file_name' => $fileName,
            ]);

            return response()->json([
                'status' => 'success',
                'code_text' => $codeText,
                'qr_image_url' => 'public/qr/'.$fileName,
                'created_at' => $record->created_at?->toIso8601String(),
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    public function scanQr(Request $request)
    {
        try {
            $request->validate(['qr_data' => 'required|string']);
            $scannedCode = $request->input('qr_data');
            $storedCode = AttendaceQr::query()->latest()->value('code_text');
            if ($storedCode === null || $storedCode === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_qr'),
                ], 200);
            }
            if ($scannedCode !== $storedCode) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_qr'),
                ], 200);
            }

            $user = $request->user();
            $employee_id = $user->employee->id;
            $employee = EmployeeDetail::findOrFail($employee_id);
            $scanAt = now();
            $today = AttendanceWorkDateResolver::workDateForPossibleCheckout((int) $employee_id, $scanAt);
            $salaryService = app(AttendanceSalaryService::class);

            $scans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employee_id)
                ->whereDate('work_date', $today)
                ->orderBy('id')
                ->get();

            $nextIsIn = $scans->isEmpty() || $scans->last()->direction === 'out';

            $attendance = EmployeeAttendance::firstOrNew([
                'employee_id' => $employee_id,
                'date' => $today,
            ]);

            if ($nextIsIn) {
                $scan = EmployeeAttendanceScan::create([
                    'employee_id' => $employee_id,
                    'work_date' => $today,
                    'scanned_at' => $scanAt,
                    'direction' => 'in',
                    'source' => 'qr',
                    'server_received_at' => $scanAt,
                ]);

                if (! $attendance->exists || $attendance->arrived_at === null) {
                    $attendance->arrived_at = $scanAt->toTimeString();
                }
                $attendance->left_at = null;
                $attendance->worked_minutes = EmployeeAttendanceScan::computeWorkedMinutes(
                    EmployeeAttendanceScan::query()
                        ->where('employee_id', $employee_id)
                        ->whereDate('work_date', $today)
                        ->orderBy('id')
                        ->get()
                );
                $daily = $salaryService->calculateDailyOvertime($employee, (int) ($attendance->worked_minutes ?? 0));
                $attendance->required_minutes = $daily['required_minutes'];
                $attendance->normal_minutes = $daily['normal_minutes'];
                $attendance->overtime_minutes = $daily['overtime_minutes'];
                $attendance->save();

                app(\App\Services\EmployeeActivityLogger::class)->log(
                    $employee_id,
                    $user,
                    'attendance',
                    'attendance_check_in',
                    'تسجيل دخول دوام',
                    'سجل الموظف دخول دوام من QR',
                    $attendance->fresh(),
                    null,
                    [
                        'work_date' => $today,
                        'scan_id' => (int) $scan->id,
                        'source' => 'qr',
                        'scanned_at' => $scanAt->toIso8601String(),
                        'arrived_at' => $attendance->arrived_at,
                        'worked_minutes' => (int) ($attendance->worked_minutes ?? 0),
                    ]
                );

                try {
                    $attendance->refresh();
                    app(\App\Services\AdminNotificationService::class)->notifyEmployeeLogin(
                        $employee,
                        (int) $attendance->id,
                        'qr',
                        $scanAt->toIso8601String()
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Admin notification (employee login): '.$e->getMessage());
                }

                $salary = $salaryService->calculateSalary(
                    $employee,
                    (int) ($attendance->normal_minutes ?? 0),
                    (int) ($attendance->overtime_minutes ?? 0)
                );

                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.arrival_time'),
                    'scan' => 'in',
                    'day_worked_minutes' => $attendance->worked_minutes,

                    // Helpful projections (stored row may still be incomplete while mid-shift)
                    'worked_hours' => $salaryService->formatHours((int) ($attendance->worked_minutes ?? 0)),
                    'required_hours' => $salaryService->formatHours((int) ($attendance->required_minutes ?? 0)),
                    'normal_hours' => $salaryService->formatHours((int) ($attendance->normal_minutes ?? 0)),
                    'overtime_hours' => $salaryService->formatHours((int) ($attendance->overtime_minutes ?? 0)),
                    'normal_salary' => number_format((float) $salary['normal_salary'], 2, '.', ''),
                    'overtime_salary' => number_format((float) $salary['overtime_salary'], 2, '.', ''),
                    'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
                ], 200);
            }

            if ($scans->isEmpty() || $scans->last()->direction !== 'in') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.must_check_in_first'),
                ], 200);
            }

            $lastIn = $scans->last();
            $segmentMinutes = max(0, Carbon::parse($lastIn->scanned_at)->diffInMinutes($scanAt));

            $scan = EmployeeAttendanceScan::create([
                'employee_id' => $employee_id,
                'work_date' => $today,
                'scanned_at' => $scanAt,
                'direction' => 'out',
                'source' => 'qr',
                'server_received_at' => $scanAt,
            ]);

            $allScans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employee_id)
                ->whereDate('work_date', $today)
                ->orderBy('id')
                ->get();

            $totalWorked = EmployeeAttendanceScan::computeWorkedMinutes($allScans);
            $attendance->worked_minutes = $totalWorked;
            $attendance->left_at = $scanAt->toTimeString();
            $daily = $salaryService->calculateDailyOvertime($employee, (int) $totalWorked);
            $attendance->required_minutes = $daily['required_minutes'];
            $attendance->normal_minutes = $daily['normal_minutes'];
            $attendance->overtime_minutes = $daily['overtime_minutes'];
            $attendance->save();

            $calculatedOvertime = (int) ($daily['overtime_minutes'] ?? 0);
            if ($calculatedOvertime > 0) {
                app(\App\Services\EmployeeAttendanceOvertimeService::class)->applyCheckoutOvertimePolicy(
                    $attendance,
                    $employee,
                    'qr',
                    $calculatedOvertime
                );
                $attendance->refresh();
            }

            app(\App\Services\EmployeeActivityLogger::class)->log(
                $employee_id,
                $user,
                'attendance',
                'attendance_check_out',
                'تسجيل خروج دوام',
                'سجل الموظف خروج دوام من QR',
                $attendance->fresh(),
                null,
                [
                    'work_date' => $today,
                    'scan_id' => (int) $scan->id,
                    'source' => 'qr',
                    'scanned_at' => $scanAt->toIso8601String(),
                    'left_at' => $attendance->left_at,
                    'segment_minutes' => $segmentMinutes,
                    'day_worked_minutes' => (int) $totalWorked,
                    'normal_minutes' => (int) ($attendance->normal_minutes ?? 0),
                    'overtime_minutes' => (int) ($attendance->overtime_minutes ?? 0),
                ]
            );

            try {
                $notifier = app(\App\Services\AdminNotificationService::class);
                $logoutTime = $scanAt->toIso8601String();
                $notifier->notifyEmployeeLogout(
                    $employee,
                    (int) $attendance->id,
                    $logoutTime,
                    'qr',
                    false
                );
                $pending = \App\Support\EmployeePendingTasksForToday::forEmployee($employee_id);
                $notifier->notifyEmployeeLogoutWithPendingTasks(
                    $employee,
                    (int) $attendance->id,
                    $pending,
                    $logoutTime
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Admin notification (checkout pending tasks): '.$e->getMessage());
            }

            $salary = $salaryService->calculateSalary(
                $employee,
                (int) ($attendance->normal_minutes ?? 0),
                (int) ($attendance->overtime_minutes ?? 0)
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.departure_time'),
                'scan' => 'out',
                'segment_minutes' => $segmentMinutes,
                'day_worked_minutes' => $totalWorked,
                // Backward-compatible key (salary is no longer mutated here)
                'updated_salary' => $employee->salary,

                // New overtime + salary fields (safe strings / numbers)
                'worked_hours' => $salaryService->formatHours((int) ($attendance->worked_minutes ?? 0)),
                'required_hours' => $salaryService->formatHours((int) ($attendance->required_minutes ?? 0)),
                'normal_hours' => $salaryService->formatHours((int) ($attendance->normal_minutes ?? 0)),
                'overtime_hours' => $salaryService->formatHours((int) ($attendance->overtime_minutes ?? 0)),
                'normal_salary' => number_format((float) $salary['normal_salary'], 2, '.', ''),
                'overtime_salary' => number_format((float) $salary['overtime_salary'], 2, '.', ''),
                'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    public function qrHistory(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            if ($perPage <= 0 || $perPage > 100) {
                $perPage = 20;
            }

            $page = (int) $request->input('page', 1);
            if ($page <= 0) {
                $page = 1;
            }

            $paginator = AttendaceQr::query()
                ->orderByDesc('id')
                ->paginate($perPage, ['id', 'code_text', 'file_name', 'created_at'], 'page', $page);

            $rows = $paginator->getCollection()->map(function (AttendaceQr $row) {
                return [
                    'id' => (int) $row->id,
                    'code_text' => (string) ($row->code_text ?? ''),
                    'qr_image_url' => $row->file_name ? 'public/qr/'.$row->file_name : null,
                    'created_at' => $row->created_at ? $row->created_at->toISOString() : null,
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'history' => $rows,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
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
}
