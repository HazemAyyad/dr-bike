<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StoreUsersController extends StoreBaseController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string'],
            'confirmPassword' => ['required', 'same:password'],
        ]);

        $user = new StoreUser();
        $user->forceFill([
            'name' => strstr($data['email'], '@', true) ?: $data['email'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'type' => 'User',
            'is_blocked' => false,
        ])->save();

        return response()->json($this->userPayload($user));
    }

    public function getById(Request $request)
    {
        $id = $request->query('id', $request->input('id'));
        $user = StoreUser::query()->find($id);

        if (! $user) {
            return response()->json(['message' => 'UserNotFound'], 404);
        }

        return response()->json($this->userPayload($user));
    }

    public function edit(Request $request)
    {
        $id = $request->input('id', $request->query('id'));
        $user = StoreUser::query()->find($id);

        if (! $user) {
            return response()->json(['message' => 'UserNotFound'], 404);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phoneNumber' => ['nullable', 'string'],
            'phoneNumber2' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'fullName' => ['nullable', 'string'],
            'typeUser' => ['nullable', 'string'],
            'cityId' => ['nullable'],
        ]);

        $user->forceFill([
            'name' => $data['fullName'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phoneNumber'] ?? $user->phone,
            'sub_phone' => $data['phoneNumber2'] ?? $user->sub_phone,
            'address' => $data['address'] ?? $user->address,
            'type' => $data['typeUser'] ?? $user->type,
            'city' => array_key_exists('cityId', $data) ? (string) $data['cityId'] : $user->city,
        ])->save();

        return response()->json($this->userPayload($user->fresh()));
    }

    public function blockUserAndNotActive(Request $request)
    {
        $userId = $request->query('userId', $request->input('userId'));
        $user = StoreUser::query()->find($userId);

        if (! $user) {
            return response()->json(['message' => 'UserNotFound'], 404);
        }

        $user->forceFill(['is_blocked' => true])->save();

        return response()->json(['message' => 'success']);
    }
}
