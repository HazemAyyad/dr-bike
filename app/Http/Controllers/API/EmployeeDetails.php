<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeDetailResource;
use App\Mail\NewEmployeeAccountMail;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Models\Reward;
use App\Models\User;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeDetails extends Controller
{

    

     public function employeesList()
    {
        try {
            $employees = EmployeeDetail::with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->get(['id', 'hour_work_price', 'points', 'user_id','employee_img']);

            $formatted = $employees->map(function ($employee) {
                return [
                    'id' => $employee->id,
                    'employee_name' => $employee->user?->name,
                    'hour_work_price' => $employee->hour_work_price,
                    'points' => $employee->points,
                    'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',
                    
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
                return [
                    'id' => $employee->id,
                    'user_name' => $employee->user?->name,
                    'start_work_time' => \Carbon\Carbon::parse($employee->start_work_time)->format('g:i A'),
                    'end_work_time' => \Carbon\Carbon::parse($employee->end_work_time)->format('g:i A'),
                    'number_of_work_hours' => $employee->number_of_work_hours,
                    'employee_img' => $employee->employee_img? 'public/EmployeeImages/'.$employee->employee_img[0] : 'no images',

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


    public function showFinancialDetails(Request $request)
{
    try {
        $request->validate([
            'employee_id' => 'required|exists:employee_details,id',
        ]);

        $data = $this->getEmployeeFinancialData($request->employee_id);
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
            'employee_img' => $employeeImage,
            'document_img' => $documentImage,

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

        return response(['status' => 'success', 'message' => __('messages.employee_created_successfully')], 200);
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
        ]);
    
        $employee = EmployeeDetail::findOrFail($request['employee_id']);
        $user = $employee->user;
    
        // Exclude 'name' and 'employee_id' from the update data
        $updateData = Arr::except($data, ['name','phone','sub_phone']);

        $start = Carbon::createFromFormat('H:i', $updateData['start_work_time']);
        $end = $start->copy()->addHours($updateData['number_of_work_hours']);
        $updateData['end_work_time'] = $end->format('H:i'); 

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
            $employee = EmployeeDetail::findOrFail($request->employee_id);
            $attendances = $employee->attendances()
                // ->when($request->from_date, function ($q) use ($request) {
                //     $q->whereDate('created_at', '>=', $request->from_date);
                // })
                // ->when($request->to_date, function ($q) use ($request) {
                //     $q->whereDate('created_at', '<=', $request->to_date);
                // })
                ->get();
        $rewards = $employee->rewards()->get();
            $financialData = $this->getEmployeeFinancialData($employee->id);
       // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.employee-report', [
            'attendances' => $attendances,
            'financialData' => $financialData,
            'rewards'=>$rewards,

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

    public function viewTest(){
        $permissions = Permission::all();
        $employee = EmployeeDetail::findOrFail(2);

        $permissionIds = EmployeePermission::where('employee_id', $employee->id)
            ->pluck('permission_id')
            ->toArray();
        return view('test',compact('permissions','employee','permissionIds'));
    }




}
