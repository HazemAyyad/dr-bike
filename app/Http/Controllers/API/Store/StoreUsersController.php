<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreUser;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StoreUsersController extends StoreBaseController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'phoneNumber' => ['required', 'string'],
            'password' => ['required', 'string'],
            'confirmPassword' => ['required', 'same:password'],
        ]);

        $user = new StoreUser;
        $user->forceFill([
            'name' => strstr($data['email'], '@', true) ?: $data['email'],
            'email' => $data['email'],
            'phone' => $data['phoneNumber'],
            'password' => Hash::make($data['password']),
            'type' => 'User',
            'is_blocked' => false,
        ])->save();

        app(AdminNotificationService::class)->notifyStoreUserRegistered($user);

        return response()->json($this->userPayload($user));
    }

    public function getById(Request $request)
    {
        $user = $this->authenticatedStoreUser($request);
        $this->rejectForeignId($request, $user, 'id');

        return response()->json($this->userPayload($user));
    }

    public function edit(Request $request)
    {
        $user = $this->authenticatedStoreUser($request);
        $this->rejectForeignId($request, $user, 'id');

        $data = $request->validate([
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phoneNumber' => ['nullable', 'string'],
            'phoneNumber2' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'fullName' => ['nullable', 'string'],
            'cityId' => ['nullable'],
        ]);

        $user->forceFill([
            'name' => $data['fullName'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phoneNumber'] ?? $user->phone,
            'sub_phone' => $data['phoneNumber2'] ?? $user->sub_phone,
            'address' => $data['address'] ?? $user->address,
            // Authentication role/type is server-owned and never profile-editable.
            'type' => $user->type,
            'city' => array_key_exists('cityId', $data) ? (string) $data['cityId'] : $user->city,
        ])->save();

        return response()->json($this->userPayload($user->fresh()));
    }

    public function blockUserAndNotActive(Request $request)
    {
        $user = $this->authenticatedStoreUser($request);
        $this->rejectForeignId($request, $user, 'userId');

        $user->forceFill(['is_blocked' => true])->save();

        return response()->json(['message' => 'success']);
    }

    private function authenticatedStoreUser(Request $request): StoreUser
    {
        $user = $this->storeUserFromRequest($request);
        if (! $user || (bool) ($user->is_blocked ?? false)) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    private function rejectForeignId(Request $request, StoreUser $user, string $field): void
    {
        $submitted = $request->query($field, $request->input($field));
        if ($submitted !== null && (! is_numeric($submitted) || (int) $submitted !== (int) $user->getKey())) {
            abort(404);
        }
    }
}
