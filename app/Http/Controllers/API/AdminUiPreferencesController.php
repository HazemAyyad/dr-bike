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
                    'button_order_keys' => $preferences['admin_dashboard']['button_order_keys'] ?? [],
                    'quick_access_count' => $preferences['admin_dashboard']['quick_access_count'] ?? 6,
                    'show_attention_section' => $preferences['admin_dashboard']['show_attention_section'] ?? true,
                ],
                'debt_ledger' => [
                    'taken_label' => $preferences['debt_ledger']['taken_label'] ?? 'أخذت',
                    'given_label' => $preferences['debt_ledger']['given_label'] ?? 'أعطيت',
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
                'admin_dashboard.button_order_keys' => ['sometimes', 'array'],
                'admin_dashboard.button_order_keys.*' => ['string', 'max:128'],
                'admin_dashboard.quick_access_count' => ['sometimes', 'integer', 'min:3', 'max:30'],
                'admin_dashboard.show_attention_section' => ['sometimes', 'boolean'],
                'debt_ledger' => ['sometimes', 'array'],
                'debt_ledger.taken_label' => ['sometimes', 'string', 'max:30'],
                'debt_ledger.given_label' => ['sometimes', 'string', 'max:30'],
            ]);

            $user = $request->user();
            $preferences = $user->ui_preferences ?? [];
            if (array_key_exists('admin_dashboard', $data)) {
                $dashboard = $preferences['admin_dashboard'] ?? [];
                $hiddenButtonKeys = $data['admin_dashboard']['hidden_button_keys']
                    ?? ($dashboard['hidden_button_keys'] ?? []);
                $buttonOrderKeys = $data['admin_dashboard']['button_order_keys']
                    ?? ($dashboard['button_order_keys'] ?? []);
                $quickAccessCount = $data['admin_dashboard']['quick_access_count']
                    ?? ($dashboard['quick_access_count'] ?? 6);
                $showAttentionSection = $data['admin_dashboard']['show_attention_section']
                    ?? ($dashboard['show_attention_section'] ?? true);
                $preferences['admin_dashboard'] = [
                    'hidden_button_keys' => array_values(array_unique($hiddenButtonKeys)),
                    'button_order_keys' => array_values(array_unique($buttonOrderKeys)),
                    'quick_access_count' => max(3, min(30, (int) $quickAccessCount)),
                    'show_attention_section' => (bool) $showAttentionSection,
                ];
            }

            if (array_key_exists('debt_ledger', $data)) {
                $debtLedger = $preferences['debt_ledger'] ?? [];
                $preferences['debt_ledger'] = [
                    'taken_label' => trim($data['debt_ledger']['taken_label'] ?? ($debtLedger['taken_label'] ?? 'أخذت')) ?: 'أخذت',
                    'given_label' => trim($data['debt_ledger']['given_label'] ?? ($debtLedger['given_label'] ?? 'أعطيت')) ?: 'أعطيت',
                ];
            }

            $user->forceFill(['ui_preferences' => $preferences])->save();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'admin_dashboard' => $preferences['admin_dashboard'] ?? [
                        'hidden_button_keys' => [],
                        'button_order_keys' => [],
                        'quick_access_count' => 6,
                        'show_attention_section' => true,
                    ],
                    'debt_ledger' => $preferences['debt_ledger'] ?? [
                        'taken_label' => 'أخذت',
                        'given_label' => 'أعطيت',
                    ],
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
