<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CheckNotificationRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckNotificationRulesController extends Controller
{
    private array $types = ['before_due', 'cashed', 'returned'];
    private array $triggerModes = ['on_action', 'at_time'];

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'rules' => CheckNotificationRule::query()
                ->latest('id')
                ->get(),
        ], 200);
    }

    public function store(Request $request)
    {
        return $this->save($request);
    }

    public function update(Request $request, CheckNotificationRule $rule)
    {
        return $this->save($request, $rule);
    }

    public function destroy(CheckNotificationRule $rule)
    {
        $rule->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('messages.check_notification_rule_deleted'),
        ], 200);
    }

    private function save(Request $request, ?CheckNotificationRule $rule = null)
    {
        try {
            $data = $request->validate([
                'type' => ['required', 'string', Rule::in($this->types)],
                'days' => ['required', 'integer', 'min:0', 'max:365'],
                'trigger_mode' => ['required', 'string', Rule::in($this->triggerModes)],
                'send_time' => ['nullable', 'date_format:H:i'],
                'message' => ['required', 'string', 'max:1000'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            if ($data['trigger_mode'] === 'at_time' && empty($data['send_time'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => ['send_time' => [__('messages.check_notification_time_required')]],
                ], 200);
            }

            if ($data['trigger_mode'] === 'on_action') {
                $data['send_time'] = null;
            }

            $data['is_active'] = $request->boolean('is_active', true);

            $rule = $rule ?? new CheckNotificationRule();
            $rule->fill($data)->save();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.check_notification_rule_saved'),
                'rule' => $rule,
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
