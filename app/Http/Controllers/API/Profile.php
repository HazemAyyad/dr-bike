<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Profile extends Controller
{
    public function updatePersonalInformation(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.expired_token'),
                ], 200);
            }

            $normalizePhone = static function (?string $value): ?string {
                if ($value === null || trim($value) === '') {
                    return null;
                }

                $compact = preg_replace('/\s+/', '', trim($value));

                return $compact === '' ? null : $compact;
            };

            $isValidPhone = static function (?string $value): bool {
                return $value !== null && preg_match('/^\+?[0-9]{12}$/', $value) === 1;
            };

            $isAdmin = $user->type === 'admin';

            $phone = $normalizePhone($request->input('phone'));
            $subPhone = $normalizePhone($request->input('sub_phone'));
            if ($subPhone !== null && ! $isValidPhone($subPhone)) {
                $subPhone = null;
            }

            $city = $request->filled('city')
                ? trim((string) $request->input('city'))
                : null;
            if ($city === '') {
                $city = null;
            }

            $request->merge([
                'phone' => $phone,
                'sub_phone' => $subPhone,
                'city' => $city,
                'address' => $request->filled('address')
                    ? trim((string) $request->input('address'))
                    : null,
            ]);

            $phoneRule = $isAdmin
                ? ['nullable', 'string', 'regex:/^\+?[0-9]{12}$/']
                : ['required', 'string', 'regex:/^\+?[0-9]{12}$/'];

            $cityRule = $isAdmin
                ? ['nullable', 'string', 'max:50']
                : ['required', 'string', 'max:50'];

            $data = $request->validate([
                'name' => 'required|string|max:100',
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id),
                ],
                'phone' => $phoneRule,
                'sub_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{12}$/'],
                'city' => $cityRule,
                'address' => 'nullable|string|max:500',
            ]);

            $user->update($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.profile_updated'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            Log::error('Profile update query failed', [
                'user_id' => $request->user()?->id,
                'code' => $e->errorInfo[1] ?? null,
                'message' => $e->getMessage(),
            ]);

            if (($e->errorInfo[1] ?? null) === 1062) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.duplicate_email'),
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'details' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Profile update failed', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'details' => $e->getMessage(),
            ], 200);
        }
    }
}
