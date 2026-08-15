<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyTokenMail;
use App\Models\AdminDeviceToken;
use App\Models\AppSetting;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDetail;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Models\UserSession;
use App\Models\VerifyToken;
use App\Services\AdminNotificationService;
use App\Services\EmployeePointsService;
use App\Support\EmployeePendingTasksForToday;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
class Authentication extends Controller
{
    public function register(Request $request)
    {

        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => 'required|string|unique:users,email',
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

        } catch (\Exception $e) {
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

            $get_token = new VerifyToken;
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
                'errors' => $e->errors(),
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

            if (! $verifyToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.otp_invalid'),
                ], 200);
            }

            $verifyToken->is_activated = 1;
            $verifyToken->save();

            $sessionUser = UserSession::where('email', $data['email'])->first();

            if (! $sessionUser) {
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
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // for returning permissions of employee if the user is an employee
    private function permissions($employee)
    {
        try {

            $employeePermissions = $employee->permissions->map(function ($permission) {

                return [
                    'permission_id' => $permission->permission->id,
                    'permission_name' => $permission->permission->name,
                    'permission_name_en' => $permission->permission->name_en,

                ];
            });
            unset($employee->permissions); // removes from memory/response

            return $employeePermissions;
        } catch (QueryException $e) {
            return response(['status' => 'error',
                'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    private function allEmployeesPermissions()
    {
        try {
            $employees = EmployeeDetail::with('user:id,name')
                ->get(['id', 'user_id']);

            $allPermissions = [];
            foreach ($employees as $employee) {
                $permissions = $employee->permissions;
                if (! $permissions->isEmpty()) {

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
        } catch (QueryException $e) {
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

            if (! Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_credentials')], 200);
            }

            $user = User::where('email', $request->email)->first();

            if ($user->is_blocked) {
                Auth::logout();

                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.account_blocked'),
                ], 200);
            }

            if ($user->type === 'employee' && (bool) ($user->employee?->is_suspended ?? false)) {
                Auth::logout();

                return response()->json([
                    'status' => 'error',
                    'message' => 'تم تعطيل حسابك مؤقتاً، تواصل مع الإدارة.',
                ], 200);
            }

            $fcm = trim((string) $request->fcm_token);
            if ($fcm !== '' && $fcm !== 'no_token') {
                $user->forceFill(['fcm_token' => $fcm])->save();
            }
            $token = $user->createToken('myapptoken', ['*'], now()->addWeek())->plainTextToken;

            $response = [
                'status' => 'success',
                'user' => $user,
                'token' => $token,
            ];

            if ($user->type === 'employee') {
                $employee = $user->employee;
                if (! $employee || (bool) ($employee->is_suspended ?? false)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'تم تعطيل حسابك مؤقتاً، تواصل مع الإدارة.',
                    ], 200);
                }
                $employee->employee_img = $employee->employee_img
                    ? 'public/EmployeeImages/'.$employee->employee_img[0]
                    : null;

                $employee->document_img = $employee->document_img
                    ? 'public/EmployeeDocumetImages/'.$employee->document_img[0]
                    : null;
                $employee->setAttribute(
                    'points',
                    app(EmployeePointsService::class)->getTotalNetPoints((int) $employee->id)
                );

                $response['employee_permissions'] = $this->permissions($user->employee);

            }

            return response()->json($response, 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.login_error'),
            ], 200);
        }
    }

    /**
     * تحديث توكن FCM بعد تسجيل الدخول (مثلاً أول تشغيل قبل جاهزية التوكن).
     */
    public function updateFcmToken(Request $request)
    {
        try {
            $data = $request->validate([
                'fcm_token' => 'required|string|max:512',
                'platform' => 'nullable|string|max:32',
                'device_name' => 'nullable|string|max:255',
            ]);

            $fcm = trim($data['fcm_token']);
            if ($fcm === '' || $fcm === 'no_token') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'skipped',
                ], 200);
            }

            $user = $request->user();
            $tokenName = (string) ($user?->currentAccessToken()?->name ?? '');
            if (str_starts_with($tokenName, 'impersonation-')) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'skipped_impersonation',
                ], 200);
            }

            $user->forceFill(['fcm_token' => $fcm])->save();

            if ($user->type === 'admin') {
                AdminDeviceToken::query()->updateOrCreate(
                    ['fcm_token' => $fcm],
                    [
                        'user_id' => $user->id,
                        'platform' => $data['platform'] ?? null,
                        'device_name' => $data['device_name'] ?? null,
                        'last_seen_at' => now(),
                    ]
                );
            }

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.logout_failed'),
                ], 401);
            }

            if ($user->type === 'employee' && $user->employee) {
                try {
                    $emp = $user->employee;
                    $pending = EmployeePendingTasksForToday::forEmployee((int) $emp->id);
                    $attendance = EmployeeAttendance::query()
                        ->where('employee_id', $emp->id)
                        ->whereDate('date', now()->toDateString())
                        ->first();
                    app(AdminNotificationService::class)->notifyEmployeeLogoutWithPendingTasks(
                        $emp,
                        $attendance !== null ? (int) $attendance->id : null,
                        $pending,
                        now()->toIso8601String()
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Admin notification (employee app logout): '.$e->getMessage());
                }
            }

            $token = $user->currentAccessToken();
            if ($token !== null) {
                $token->delete();
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.logout_success'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.logout_failed'),
            ], 500);
        }
    }

    // for authed users
    public function changePassword(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.expired_token'),
                ], 200);
            }

            $data = $request->validate([
                'old_password' => 'required',
                'password' => 'required|string|confirmed',
            ]);

            if (! Hash::check($data['old_password'], $user->password)) {
                return response()->json([
                    'status' => 'error',

                    'message' => __('messages.old_password_mismatch')], 200);
            }

            $user->update([
                'password' => Hash::make($data['password']),
            ]);

            return response()->json([
                'status' => 'success',

                'message' => __('messages.password_updated')], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',

                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    // forgot password
    // send reset password email link that includes token
    public function sendResetLinkEmail(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $code = random_int(1000, 9999);

            $user = User::where('email', $request->email)->firstOrFail();
            $method = strtolower(trim((string) AppSetting::get(
                AppSetting::KEY_PASSWORD_RESET_OTP_DELIVERY_METHOD,
                'email'
            )));

            PasswordResetCode::query()
                ->where('email', $request->email)
                ->whereNull('used_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->update(['expires_at' => now()]);

            PasswordResetCode::create([
                'user_id' => $user->id,
                'email' => $request->email,
                'token' => $code,
                'delivery_method' => in_array($method, ['email', 'admin', 'sms'], true) ? $method : 'email',
                'expires_at' => now()->addMinutes(15),
                'used_at' => null,
            ]);

            if ($method === 'admin') {
                app(AdminNotificationService::class)->notifyPasswordResetOtp($user, (string) $code);

                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.reset_code_sent_to_admin'),
                ], 200);
            }

            if ($method === 'sms') {
                $smsSent = $this->sendResetCodeSms($user, (string) $code);

                return response()->json([
                    'status' => $smsSent ? 'success' : 'error',
                    'message' => $smsSent
                        ? __('messages.reset_code_sent_by_sms')
                        : __('messages.reset_code_failed'),
                ], 200);
            }

            Mail::to($request['email'])->send(new ResetPasswordMail($request['email'], $code));

            return response()->json([
                'status' => 'success',
                'message' => __('messages.reset_code_sent'),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.reset_code_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => $e->getMessage(), // Shows the raw SQL/database error message

            ], 200);
        }
    }

    private function sendResetCodeSms(User $user, string $code): bool
    {
        $phone = $this->normalizeSmsPhone($user->phone ?: $user->sub_phone);
        if ($phone === null) {
            Log::warning('password_reset_sms_missing_phone', ['user_id' => $user->id]);

            return false;
        }

        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $from = config('services.twilio.from');
        if (! $accountSid || ! $authToken || ! $from) {
            Log::warning('password_reset_sms_missing_twilio_config');

            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => $from,
                    'To' => $phone,
                    'Body' => __('messages.reset_code_sms_body', ['code' => $code]),
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('password_reset_sms_failed', [
                'user_id' => $user->id,
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('password_reset_sms_exception', [
                'user_id' => $user->id,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function normalizeSmsPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', trim($phone));
        if ($normalized === '') {
            return null;
        }

        if (! str_starts_with($normalized, '+')) {
            $normalized = '+'.$normalized;
        }

        return preg_match('/^\+\d{8,15}$/', $normalized) ? $normalized : null;
    }

    // reset the passsword
    public function reset(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
                'token' => 'required|digits:4',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $record = PasswordResetCode::where('email', $request->email)
                ->where('token', $request->token)
                ->whereNull('used_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now());
                })
                ->orderByDesc('id')
                ->first();

            if (! $record) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_token'),
                ], 200);
            }

            $user = User::where('email', $request->email)->first();
            $user->password = Hash::make($request->password);
            $user->save();

            $record->used_at = now();
            $record->save();

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
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.reset_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.expired_token'),
                ], 200);
            }

            $response = [
                'status' => 'success',
                'user' => $user,
            ];
            if ($user->type === 'employee') {
                $employee = $user->employee;
                if (! $employee || (bool) ($employee->is_suspended ?? false)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'تم تعطيل حسابك مؤقتاً، تواصل مع الإدارة.',
                    ], 200);
                }

                $employee->employee_img = $employee->employee_img
                    ? 'public/EmployeeImages/'.$employee->employee_img[0]
                    : null;

                $employee->document_img = $employee->document_img
                    ? 'public/EmployeeDocumetImages/'.$employee->document_img[0]
                    : null;
                $employee->setAttribute(
                    'points',
                    app(EmployeePointsService::class)->getTotalNetPoints((int) $employee->id)
                );

                $response['employee_permissions'] = $this->permissions($user->employee);

            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }

    }

    public function quickRegister(Request $request)
    {

        $data = $request->validate([
            'name' => 'required|string',
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
