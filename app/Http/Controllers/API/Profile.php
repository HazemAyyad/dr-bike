<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;


class Profile extends Controller
{

    public function updatePersonalInformation(Request $request){
        try{

            $token = PersonalAccessToken::findToken($request->bearerToken());
            if(!$token || $token->isExpired()){
                return response()->json(['message'=>__('messages.expired_token')],200);
            }
              
            $user = $request->user();

            $normalizePhone = static function (?string $value): ?string {
                if ($value === null || trim($value) === '') {
                    return null;
                }

                $compact = preg_replace('/\s+/', '', trim($value));

                return $compact === '' ? null : $compact;
            };

            $request->merge([
                'phone' => $normalizePhone($request->input('phone')),
                'sub_phone' => $normalizePhone($request->input('sub_phone')),
                'address' => $request->filled('address')
                    ? trim((string) $request->input('address'))
                    : null,
            ]);

            $data = $request->validate([
                'name'      => 'required|string|max:100',
                'email'     => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id),
                ],
                'phone'     => 'required|string|regex:/^\+?[0-9]{12}$/',
                'sub_phone' => 'nullable|string|regex:/^\+?[0-9]{12}$/',
                'city'      => 'required|string|max:50',
                'address'   => 'nullable|string|max:500',
            ]);
            
            $user->update($data);


            return response()->json([
                'status' => 'success',
                'message'=>__('messages.profile_updated')],200);

        


    }
    catch (ValidationException $e) {
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
}