<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CheckNotificationRule;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckNotificationRulesController extends Controller
{
    private array $types = ['before_due', 'cashed', 'returned'];
    private array $triggerModes = ['on_action', 'at_time'];
    private array $directions = ['incoming', 'outgoing'];
    private array $channels = ['push', 'sms'];
    private array $recipients = ['admin', 'check_owner'];

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

    public function checkOwner(Request $request)
    {
        $data = $request->validate([
            'check_direction' => ['required', Rule::in($this->directions)],
            'check_id' => ['required', 'integer', 'min:1'],
            'event_type' => ['required', Rule::in($this->types)],
            'owner_type' => ['nullable', Rule::in(['customer', 'seller'])],
            'owner_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $requiresOwnerPhone = CheckNotificationRule::query()
            ->where('check_direction', $data['check_direction'])
            ->where('type', $data['event_type'])
            ->where('channel', 'sms')
            ->where('recipient', 'check_owner')
            ->where('is_active', true)
            ->exists();

        if (! $requiresOwnerPhone) {
            return response()->json([
                'status' => 'success',
                'requires_owner_phone' => false,
            ]);
        }

        $owner = $this->resolveCheckOwner(
            $data['check_direction'],
            (int) $data['check_id'],
            $data['owner_type'] ?? null,
            isset($data['owner_id']) ? (int) $data['owner_id'] : null
        );

        return response()->json([
            'status' => 'success',
            'requires_owner_phone' => true,
            'owner' => [
                'id' => $owner->id,
                'type' => $owner instanceof \App\Models\Customer ? 'customer' : 'seller',
                'name' => $owner->name,
                'phone' => $owner->phone,
                'needs_phone' => blank($owner->phone),
            ],
        ]);
    }

    public function updateCheckOwnerPhone(Request $request)
    {
        $data = $request->validate([
            'check_direction' => ['required', Rule::in($this->directions)],
            'check_id' => ['required', 'integer', 'min:1'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-]{7,28}$/'],
            'owner_type' => ['nullable', Rule::in(['customer', 'seller'])],
            'owner_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $owner = $this->resolveCheckOwner(
            $data['check_direction'],
            (int) $data['check_id'],
            $data['owner_type'] ?? null,
            isset($data['owner_id']) ? (int) $data['owner_id'] : null
        );
        $owner->update(['phone' => trim($data['phone'])]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ رقم صاحب الشيك.',
            'owner' => [
                'id' => $owner->id,
                'type' => $owner instanceof \App\Models\Customer ? 'customer' : 'seller',
                'name' => $owner->name,
                'phone' => $owner->phone,
            ],
        ]);
    }

    private function save(Request $request, ?CheckNotificationRule $rule = null)
    {
        try {
            $data = $request->validate([
                'type' => ['required', 'string', Rule::in($this->types)],
                'check_direction' => ['required', 'string', Rule::in($this->directions)],
                'channel' => ['required', 'string', Rule::in($this->channels)],
                'recipient' => ['required', 'string', Rule::in($this->recipients)],
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

            if ($data['channel'] === 'push' && $data['recipient'] !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Push notifications can currently be sent to administrators only.',
                    'errors' => ['recipient' => ['صاحب الشيك لا يملك رمز Push في النظام.']],
                ], 200);
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

    private function resolveCheckOwner(
        string $direction,
        int $checkId,
        ?string $ownerType = null,
        ?int $ownerId = null
    )
    {
        if ($direction === 'incoming') {
            $check = IncomingCheck::query()
                ->with(['fromCustomer', 'fromSeller'])
                ->findOrFail($checkId);
            $owner = $check->fromCustomer ?: $check->fromSeller;
        } else {
            if ($ownerType && $ownerId) {
                return $ownerType === 'customer'
                    ? \App\Models\Customer::findOrFail($ownerId)
                    : \App\Models\Seller::findOrFail($ownerId);
            }

            $check = OutgoingCheck::query()
                ->with(['customer', 'seller'])
                ->findOrFail($checkId);
            $owner = $check->customer ?: $check->seller;
        }

        abort_if(! $owner, 422, 'لا يوجد صاحب مرتبط بهذا الشيك.');

        return $owner;
    }
}
