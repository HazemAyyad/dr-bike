<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUiPreferencesController extends Controller
{
    public function show(Request $request)
    {
        $preferences = $request->user()->ui_preferences ?? [];

        return response()->json([
            'status' => 'success',
            'data' => [
                'admin_dashboard' => [
                    'hidden_button_keys' => $preferences['admin_dashboard']['hidden_button_keys']
                        ?? $preferences['admin_dashboard']['hidden_button_ids']
                        ?? [],
                ],
            ],
        ], 200);
    }

    public function update(Request $request)
    {
        try {
            $data = $request->validate([
                'admin_dashboard' => ['sometimes', 'array'],
                'admin_dashboard.hidden_button_keys' => ['sometimes', 'array'],
                'admin_dashboard.hidden_button_keys.*' => ['string', 'max:128'],
            ]);

            $user = $request->user();
            $preferences = $user->ui_preferences ?? [];
            $hiddenButtonKeys = $data['admin_dashboard']['hidden_button_keys'] ?? [];

            $preferences['admin_dashboard'] = [
                'hidden_button_keys' => array_values(array_unique($hiddenButtonKeys)),
            ];

            $user->forceFill(['ui_preferences' => $preferences])->save();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'admin_dashboard' => $preferences['admin_dashboard'],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }
}
