<?php

namespace App\Http\Controllers\API\Store;

use App\Mail\ResetPasswordMail;
use App\Models\Store\StoreUser;
use App\Models\User;
use App\Services\OnlineStore\StorePasswordResetService;
use App\Support\AppUpdateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StoreAuthController extends StoreBaseController
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'userToken' => ['nullable', 'string'],
        ]);

        $user = StoreUser::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'ErrorInEmailOrPassword'], 400);
        }

        if ((bool) ($user->is_blocked ?? false)) {
            return response()->json(['message' => 'UserIsBlocked'], 400);
        }

        if (! empty($data['userToken'])) {
            $user->forceFill(['fcm_token' => $data['userToken']])->save();
        }

        $token = $user->createToken('store-app', ['*'], now()->addWeek())->plainTextToken;

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
            'token' => $token,
        ]);
    }

    public function checkUser(Request $request)
    {
        $userId = $request->query('UserId', $request->input('UserId'));
        $user = StoreUser::query()->find($userId);

        if (! $user || (bool) ($user->is_blocked ?? false)) {
            return response()->json(['message' => 'UserNotActive'], 400);
        }

        return response()->json($this->userPayload($user));
    }

    public function forgotPassword(Request $request, StorePasswordResetService $passwordResets)
    {
        if ($response = $this->passwordResetVersionGate($request)) {
            return $response;
        }

        $data = $request->validate([
            'Email' => ['required', 'email'],
        ]);
        $email = mb_strtolower(trim((string) $data['Email']));
        $user = User::query()
            ->where('email', $email)
            ->where('type', 'User')
            ->where('is_blocked', false)
            ->first();

        if ($user) {
            $otp = $passwordResets->createChallenge($user);
            try {
                Mail::to($user->email)->send(new ResetPasswordMail($user->email, $otp));
            } catch (\Throwable) {
                Log::warning('store_password_reset_delivery_failed');
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'success',
        ]);
    }

    public function verifyForgotPasswordOtp(Request $request, StorePasswordResetService $passwordResets)
    {
        if ($response = $this->passwordResetVersionGate($request)) {
            return $response;
        }

        $data = $request->validate([
            'Email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        return response()->json([
            'status' => 'success',
            'resetProof' => $passwordResets->verify((string) $data['Email'], (string) $data['otp']),
            'message' => 'success',
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $this->storeUserFromRequest($request);
        if (! $user || (bool) ($user->is_blocked ?? false)) {
            abort(401, 'Unauthenticated.');
        }

        $data = $request->validate([
            'userId' => ['nullable'],
            'oldPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string'],
            'confirmPassword' => ['required', 'same:newPassword'],
        ]);

        if (isset($data['userId']) && (int) $data['userId'] !== (int) $user->getKey()) {
            abort(404);
        }
        if (! Hash::check($data['oldPassword'], $user->password)) {
            return response()->json(['message' => 'OldPasswordNotCorrect'], 400);
        }

        $user->forceFill(['password' => Hash::make($data['newPassword'])])->save();

        return response()->json(['message' => 'success']);
    }

    public function changePasswordToForgot(Request $request, StorePasswordResetService $passwordResets)
    {
        if ($response = $this->passwordResetVersionGate($request)) {
            return $response;
        }

        $data = $request->validate([
            'resetProof' => ['required', 'string', 'min:40', 'max:255'],
            'newPassword' => ['required', 'string', 'min:8'],
            'confirmPassword' => ['required', 'same:newPassword'],
        ]);

        $passwordResets->reset((string) $data['resetProof'], (string) $data['newPassword']);

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    private function passwordResetVersionGate(Request $request)
    {
        $app = strtolower(trim((string) $request->input('app', $request->query('app'))));
        $platform = strtolower(trim((string) $request->input('platform', $request->query('platform'))));
        $version = trim((string) $request->input('current_version', $request->query('current_version')));
        $build = $request->input('current_build', $request->query('current_build'));

        $validVersion = preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
        $validBuild = filter_var($build, FILTER_VALIDATE_INT) !== false;

        if ($app !== 'store' || ! in_array($platform, ['android', 'ios'], true)
            || ! $validVersion || ! $validBuild || (int) $build < AppUpdateSettings::STORE_PASSWORD_RESET_MINIMUM_BUILD) {
            return response()->json([
                'status' => 'upgrade_required',
                'message' => 'A Store app update is required to reset the password securely.',
                'minimum_build' => AppUpdateSettings::STORE_PASSWORD_RESET_MINIMUM_BUILD,
            ], 426);
        }

        return null;
    }
}
