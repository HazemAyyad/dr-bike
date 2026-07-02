<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\CheckNotificationLog;
use App\Models\CheckNotificationRule;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CheckSmsNotificationService
{
    public function dispatchForAction(IncomingCheck|OutgoingCheck $check, string $eventType): void
    {
        $check->loadMissing($this->relationsFor($check));
        $checkDirection = $check instanceof IncomingCheck ? 'incoming' : 'outgoing';

        $rules = CheckNotificationRule::query()
            ->where('type', $eventType)
            ->where('check_direction', $checkDirection)
            ->where('trigger_mode', 'on_action')
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            if ($rule->channel === 'push') {
                app(CheckPushNotificationService::class)->sendForCheck($rule, $check, $eventType);
            } else {
                $this->sendForCheck($rule, $check, $eventType);
            }
        }
    }

    public function sendForCheck(CheckNotificationRule $rule, IncomingCheck|OutgoingCheck $check, string $eventType): CheckNotificationLog
    {
        $check->loadMissing($this->relationsFor($check));

        $checkType = $check instanceof IncomingCheck ? 'incoming' : 'outgoing';
        $phones = $this->resolveRecipientPhones($rule, $check);
        $message = $this->renderMessage($rule->message, $check);
        $phoneLabel = implode(', ', $phones);

        $log = CheckNotificationLog::firstOrCreate(
            [
                'rule_id' => $rule->id,
                'check_type' => $checkType,
                'check_id' => $check->id,
                'event_type' => $eventType,
            ],
            [
                'phone' => $phoneLabel ?: null,
                'message' => $message,
                'status' => 'pending',
            ]
        );

        if ($log->sent_at) {
            return $log;
        }

        $log->fill([
            'phone' => $phoneLabel ?: null,
            'message' => $message,
        ]);

        if ($phones === []) {
            $log->fill([
                'status' => 'no_phone',
                'response' => $rule->recipient === 'admin'
                    ? 'No admin phone number found.'
                    : 'Check owner phone is missing.',
            ])->save();

            return $log;
        }

        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $from = config('services.twilio.from');

        if (! $accountSid || ! $authToken || ! $from) {
            $log->fill([
                'status' => 'skipped',
                'response' => 'Twilio SMS configuration is missing.',
            ])->save();

            return $log;
        }

        $responses = [];
        $sentAny = false;

        foreach ($phones as $phone) {
            try {
                $response = Http::timeout(15)
                    ->asForm()
                    ->withBasicAuth($accountSid, $authToken)
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                        'From' => $from,
                        'To' => $phone,
                        'Body' => $message,
                    ]);

                $responses[] = "{$phone}: ".$response->status();
                if ($response->successful()) {
                    $sentAny = true;
                }
            } catch (\Throwable $e) {
                $responses[] = "{$phone}: ".$e->getMessage();
            }
        }

        $log->fill([
            'status' => $sentAny ? 'sent' : 'failed',
            'response' => Str::limit(implode(' | ', $responses), 1000, ''),
            'sent_at' => $sentAny ? now() : null,
        ])->save();

        return $log;
    }

    /**
     * @return array<int, string>
     */
    private function resolveRecipientPhones(
        CheckNotificationRule $rule,
        IncomingCheck|OutgoingCheck $check
    ): array
    {
        if ($rule->recipient === 'admin') {
            return $this->resolveAdminPhones();
        }

        $personPhone = $this->resolveCheckPersonPhone($check);

        return $personPhone ? [$personPhone] : [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveAdminPhones(): array
    {
        $phones = [];

        $adminUserIds = AdminDeviceToken::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        if ($adminUserIds->isNotEmpty()) {
            User::query()
                ->whereIn('id', $adminUserIds)
                ->get(['phone', 'sub_phone'])
                ->each(function (User $user) use (&$phones) {
                    foreach ([$user->phone, $user->sub_phone] as $phone) {
                        $normalized = $this->normalizePhone($phone);
                        if ($normalized) {
                            $phones[] = $normalized;
                        }
                    }
                });
        }

        $fallback = $this->normalizePhone(config('services.twilio.admin_phone'));
        if ($fallback) {
            $phones[] = $fallback;
        }

        return array_values(array_unique($phones));
    }

    private function resolveCheckPersonPhone(IncomingCheck|OutgoingCheck $check): ?string
    {
        if ($check instanceof IncomingCheck) {
            $person = $check->fromCustomer ?: $check->fromSeller ?: $check->toCustomer ?: $check->toSeller;
        } else {
            $person = $check->customer ?: $check->seller;
        }

        return $this->normalizePhone($person?->phone ?: $person?->sub_phone);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', trim($phone));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, string>
     */
    private function relationsFor(IncomingCheck|OutgoingCheck $check): array
    {
        if ($check instanceof IncomingCheck) {
            return ['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller'];
        }

        return ['customer', 'seller'];
    }

    public function renderMessageForCheck(string $template, IncomingCheck|OutgoingCheck $check): string
    {
        $person = $check instanceof IncomingCheck
            ? ($check->fromCustomer ?: $check->fromSeller ?: $check->toCustomer ?: $check->toSeller)
            : ($check->customer ?: $check->seller);

        $direction = $check instanceof IncomingCheck ? 'واردة' : 'صادرة';

        return strtr($template, [
            '{name}' => (string) ($person?->name ?? ''),
            '{check_number}' => (string) ($check->check_id ?? ''),
            '{amount}' => (string) ($check->total ?? ''),
            '{currency}' => (string) ($check->currency ?? ''),
            '{due_date}' => $check->due_date ? (string) $check->due_date : '',
            '{bank}' => (string) ($check->bank_name ?? ''),
            '{check_type}' => $direction,
        ]);
    }

    private function renderMessage(string $template, IncomingCheck|OutgoingCheck $check): string
    {
        return $this->renderMessageForCheck($template, $check);
    }
}
