<?php

namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyTokenMail;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Models\UserSession;
use App\Models\VerifyToken;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Authentication extends Controller
{
    public function register(Request $request)
    {
       
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email'=>'required|string|unique:users,email',
                'password' => 'required|string|confirmed',
            ]);
        

            $userSession = UserSession::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.registration_success'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),

                'errors' => $e->errors(),
            ], 200);

        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

     public function sendCodeToEmail(Request $request)
    {
        try {
            $data = $request->validate([
                'email' => 'required|string|email|unique:users,email',
            ]);

            $validToken = random_int(1000, 9999);

            $get_token = new VerifyToken();
            $get_token->token = $validToken;
            $get_token->email = $data['email'];
            $get_token->save();

            Mail::to($data['email'])->send(new VerifyTokenMail($data['email'], $validToken));

            return response()->json([
                'status' => 'success',

                'message' => __('messages.otp_sent'),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }


    public function verifySentToken(Request $request)
    {
        try {
            $data = $request->validate([
                'otp_code' => 'required|numeric',
                'email' => 'required|email',
            ]);

            $verifyToken = VerifyToken::where('token', $data['otp_code'])
                ->where('email', $data['email'])
                ->first();

            if (!$verifyToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.otp_invalid'),
                ], 200);
            }

            $verifyToken->is_activated = 1;
            $verifyToken->save();

            $sessionUser = UserSession::where('email', $data['email'])->first();

            if (!$sessionUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.otp_invalid'),
                ], 200);
            }

            $user = User::create([
                'name' => $sessionUser->name,
                'email' => $sessionUser->email,
                'password' => $sessionUser->password,
                'type' => 'admin',
            ]);

            $verifyToken->delete();
            $sessionUser->delete();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.otp_verified'),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
              
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // for returning permissions of employee if the user is an employee
    private function permissions($employee){
        try{

            
            $employeePermissions = $employee->permissions->map(function($permission){

                return [
                    "permission_id" => $permission->permission->id,
                    "permission_name" => $permission->permission->name,
                    "permission_name_en" => $permission->permission->name_en,

                ];
            });
           unset($employee->permissions); // removes from memory/response


            return $employeePermissions;
        }

        catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    private function allEmployeesPermissions(){
        try{
            $employees = EmployeeDetail::with('user:id,name')
            ->get(['id','user_id']);

            $allPermissions = [];
            foreach($employees as $employee){
                $permissions = $employee->permissions;
                if(!$permissions->isEmpty()){

                    $employeePermissions = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->user->name,
                        'permissions' => $this->permissions($employee),
                    ];
                    // $formatted = $permissions->map(function($permission){
                    //     return [
                    //         'employee_id' => $permission->employee_id,
                    //         'employee_name' => $permission->employee->user->name,
                    //         'permission_id' => $permission->permission_id,
                    //         "permission_name" => $permission->permission->name,
                    //         "permission_name_en" => $permission->permission->name_en,
                    //                 ];
                    // });
                    $allPermissions[] = $employeePermissions;
                }
            }
            return $allPermissions;
        }

        catch (QueryException $e) {
            return response(['status' => 'error',
             'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function login(Request $request)
    {
        try {
            $fields = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
                'fcm_token' => 'required|string',

            ]);


             if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_credentials')], 200);
            }

                $user = User::where('email',$request->email)->first();
                $user->fcm_token = $request->fcm_token;
                $user->save();
                $token = $user->createToken('myapptoken', ['*'], now()->addWeek())->plainTextToken;

                $response = [
                    'status' => 'success',
                    'user' => $user,
                    'token' => $token,
                ];

                if ($user->type === 'employee') {
                    $employee = $user->employee;
                    $employee->employee_img = $employee->employee_img 
                        ? 'public/EmployeeImages/'.$employee->employee_img[0] 
                        : null;
                
                    $employee->document_img = $employee->document_img 
                        ? 'public/EmployeeDocumetImages/'.$employee->document_img[0] 
                        : null;

                    $response['employee_permissions'] = $this->permissions($user->employee);

                }



                return response()->json($response, 200);


        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                 'status' => 'error',
           
                'message' => __('messages.login_error'),
            ], 200);
        }
    }


     public function logout(Request $request)
    {
        try {
            $token = PersonalAccessToken::findToken($request->bearerToken());

            if ($token && !$token->isExpired()) {
                $request->user()->tokens()->delete();
                return response()->json([
                    'status' => 'success',

                    'message' => __('messages.logout_success')],
                     200);
            }

            return response()->json(['message' => __('messages.expired_token')], 401);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.logout_failed'),
            ], 200);
        }
    }
    // for authed users
    public function changePassword(Request $request)
    {
        try {
            $token = PersonalAccessToken::findToken($request->bearerToken());

            if (!$token || $token->isExpired()) {
                return response()->json([
                    'status' => 'error',

                    'message' => __('messages.expired_token')], 200);
            }

            $data = $request->validate([
                'old_password' => 'required',
                'password' => 'required|string|confirmed'
            ]);

            $user = $request->user();

            if (!Hash::check($data['old_password'], $user->password)) {
                return response()->json([
                    'status' => 'error',

                    'message' => __('messages.old_password_mismatch')], 200);
            }

            $user->update([
                'password' => Hash::make($data['password'])
            ]);

            return response()->json([
                'status' => 'success',

                'message' => __('messages.password_updated')], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    

    //forgot password
    // send reset password email link that includes token
    public function sendResetLinkEmail(Request $request)
    {
    try {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $code = random_int(1000, 9999);

        PasswordResetCode::updateOrCreate(
            ['email' => $request->email],
            ['token' => $code]
        );

            Mail::to($request['email'])->send(new ResetPasswordMail($request['email'], $code));


        return response()->json([
            'status' => 'success',
            'message' => __('messages.reset_code_sent')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
            'errors' => $e->errors()
        ], 200);
    }
    
    catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.reset_code_failed'),
        ], 200); }
    catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
                    'error' => $e->getMessage() // Shows the raw SQL/database error message

        ], 200);
    }
    }
    // reset the passsword
    public function reset(Request $request)
    {
    try {
        $request->validate([
            'email'    => 'required|email|exists:users,email',
            'token'    => 'required|digits:4',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = PasswordResetCode::where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.invalid_token'),
            ], 200);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete token after use
        $record->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('messages.password_reset_success'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
            'errors' => $e->errors(),
        ], 200);
    } 
        catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.reset_failed'),
        ], 200); }
    
    catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
        ], 200);
    }
}

    public function me(Request $request){
        try {
            $token = PersonalAccessToken::findToken($request->bearerToken());

            if (!$token || $token->isExpired()) {
                return response()->json([
                    'status' => 'error',

                    'message' => __('messages.expired_token')], 200);
            }
            $user = $request->user();

                $response = [
                    'status' => 'success',
                    'user' => $user,
                ];
            if ($user->type === 'employee') {
                    $employee = $user->employee;
                    $employee->employee_img = $employee->employee_img 
                        ? 'public/EmployeeImages/'.$employee->employee_img[0] 
                        : null;
                
                    $employee->document_img = $employee->document_img 
                        ? 'public/EmployeeDocumetImages/'.$employee->document_img[0] 
                        : null;

                    $response['employee_permissions'] = $this->permissions($user->employee);

                }
                return response()->json($response, 200);

    }
     catch(\Exception $e){
            return response()->json([
                'status'=>'error',
                'message'=> __('messages.something_wrong'),
            ],200);
    }

}

public function quickRegister(Request $request){
 
        
            $data = $request->validate([
                'name'=>'required|string',
                'email' => 'required|email',
                'password' => 'required|string|confirmed',

            ]);

      
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'type' => 'admin',
            ]);


}
}