<?php

namespace App\Http\Controllers\API\Store;

use App\Models\PasswordResetCode;
use App\Models\Store\StoreUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

    public function forgotPassword(Request $request)
    {
        $email = $request->query('Email', $request->input('Email'));
        $user = StoreUser::query()->where('email', $email)->first();

        if (! $user) {
            return response()->json(['message' => 'UserNotFound'], 404);
        }

        $code = random_int(1000, 9999);
        PasswordResetCode::updateOrCreate(['email' => $email], ['token' => $code]);

        return response()->json([
            'userId' => (string) $user->id,
            'email' => $email,
            'otp' => (string) $code,
            'message' => 'success',
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'userId' => ['required'],
            'oldPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string'],
            'confirmPassword' => ['required', 'same:newPassword'],
        ]);

        $user = StoreUser::query()->find($data['userId']);
        if (! $user || ! Hash::check($data['oldPassword'], $user->password)) {
            return response()->json(['message' => 'OldPasswordNotCorrect'], 400);
        }

        $user->forceFill(['password' => Hash::make($data['newPassword'])])->save();

        return response()->json(['message' => 'success']);
    }

    public function changePasswordToForgot(Request $request)
    {
        $data = $request->validate([
            'userId' => ['required'],
            'newPassword' => ['required', 'string'],
            'confirmPassword' => ['required', 'same:newPassword'],
        ]);

        $user = StoreUser::query()->find($data['userId']);
        if (! $user) {
            return response()->json(['message' => 'UserNotFound'], 404);
        }

        $user->forceFill(['password' => Hash::make($data['newPassword'])])->save();

        return response()->json(['message' => 'success']);
    }
}
