<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendaceQr;
use App\Models\EmployeeDetail;
use App\Models\EmployeeAttendance;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;


class AttendanceController extends Controller
{

public function generateQr()
{
    try{
    $codeText = Str::random(16); // Random string instead of fixed

    // Generate QR as PNG
    $qrImage = QrCode::format('png')
        ->size(300)
        ->generate($codeText);

    // Create folder if not exists
    $folderPath = public_path('qr');
    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0777, true);
    }

    // Create unique filename
    $fileName = 'attendance_qr.png';
    $filePath = $folderPath . '/' . $fileName;

    // Save file to public/qr
    file_put_contents($filePath, $qrImage);

    // Store in DB
    $qr = AttendaceQr::first();

    if ($qr) {
        $qr->update([
            'code_text' => $codeText,
            'file_name' => $fileName,
        ]);
    } else {
        AttendaceQr::create([
            'code_text' => $codeText,
            'file_name' => $fileName,
        ]);
    }


    // Return response
    return response()->json([
        'status' => 'success',
        'code_text' => $codeText,
        'qr_image_url' => 'public/qr/'.$fileName,
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
                    'message' => __('messages.something_wrong'),
                    'error' => $e->getMessage()
                ], 200);
            }

}   

public function scanQr(Request $request)
{
    try{
    
    $request->validate(['qr_data'=>'required|string']);
    $scannedCode = $request->input('qr_data'); // comes from the QR scanner
    $storedCode = AttendaceQr::first()->code_text;
    if ($scannedCode !== $storedCode) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.invalid_qr'),
        ], 200);
    }

    $user = $request->user();
    $employee_id = $user->employee->id;
    $employee = EmployeeDetail::findOrFail($employee_id);
    $today = now()->toDateString();

    $attendance = EmployeeAttendance::where('employee_id', $employee_id)
        ->where('date', $today)
        ->first();


    if (!$attendance) {
        // First scan: arrival
        $arrivalTime = now();
        $leftTime = $arrivalTime->copy()->addHours($employee->number_of_work_hours);
        $workedMinutes = $employee->number_of_work_hours * 60;

        EmployeeAttendance::create([
            'employee_id' => $employee->id,
            'date' => $today,
            'arrived_at' => now()->toTimeString(),
            'worked_minutes' => $workedMinutes,
        ]);
        
        $employee->total_work_hours += $employee->number_of_work_hours;
        $employee->salary += $employee->number_of_work_hours * $employee->hour_work_price;
        $employee->save();
        return response()->json([
            'status' => 'success',
            'message' => __('messages.arrival_time'),
        ],200);
    }

    if ($attendance && !$attendance->left_at) {
        // Second scan: departure
        $attendance->left_at = now()->toTimeString();

        $start = Carbon::createFromTimeString($attendance->arrived_at);
        $end = Carbon::createFromTimeString($attendance->left_at);
        $workedMinutes = $end->diffInMinutes($start);
        $attendance->worked_minutes = $workedMinutes;
        $attendance->save();

        if(($workedMinutes / 60) < $employee->number_of_work_hours){
            $diff = $employee->number_of_work_hours - ($workedMinutes / 60);
            $employee->total_work_hours -= $diff;
            $employee->save();
            $employee->salary = $employee->total_work_hours * $employee->hour_work_price;
            $employee->save();
        }


        return response()->json([
            'status' => 'success',
            'message' => __('messages.departure_time'),
            'worked_minutes' => $workedMinutes,
            'updated_salary' => $employee->salary,
        ],200);
    }

    
    return response()->json([
        'status' => 'error',
        'message' => __('messages.already_scanned'),
    ], 200);
}
          catch (ValidationException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => $e->errors()
                ], 200);
            } 
            catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
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
                    'error' => $e->getMessage()
                ], 200);
            }
}
}