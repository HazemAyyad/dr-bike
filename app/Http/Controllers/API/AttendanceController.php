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
    $codeText = Str::random(16);

    // Generate QR as PNG
    $qrImage = QrCode::format('png')
        ->size(300)
        ->generate($codeText);

    // Create folder if not exists
    $folderPath = public_path('qr');
    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0777, true);
    }

    // Create unique filename for history
    $fileName = 'attendance_qr_'.now()->format('Ymd_His').'_'.Str::random(6).'.png';
    $filePath = $folderPath . '/' . $fileName;

    // Save file to public/qr
    file_put_contents($filePath, $qrImage);

    // Store in DB (history row)
    AttendaceQr::create([
        'code_text' => $codeText,
        'file_name' => $fileName,
    ]);


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