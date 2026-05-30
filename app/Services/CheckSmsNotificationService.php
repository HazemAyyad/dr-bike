<?php

namespace App\Services;

use App\Models\CheckNotificationLog;
use App\Models\CheckNotificationRule;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CheckSmsNotificationService
{
    public function dispatchForAction(IncomingCheck|OutgoingCheck $check, string $eventType): void
    {
        $rules = CheckNotificationRule::query()
            ->where('type', $eventType)
            ->where('trigger_mode', 'on_action')
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $this->sendForCheck($rule, $check, $eventType);
        }
    }

    public function sendForCheck(CheckNotificationRule $rule, IncomingCheck|OutgoingCheck $check, string $eventType): CheckNotificationLog
    {
        $checkType = $check instanceof IncomingCheck ? 'incoming' : 'outgoing';
        $phone = $this->resolvePhone($check);
        $message = $this->renderMessage($rule->message, $check);

        $log = CheckNotificationLog::firstOrCreate(
            [
                'rule_id' => $rule->id,
                'check_type' => $checkType,
                'check_id' => $check->id,
                'event_type' => $eventType,
            ],
            [
                'phone' => $phone,
                'message' => $message,
                'status' => 'pending',
            ]
        );

        if ($log->sent_at) {
            return $log;
        }

        $log->fill([
            'phone' => $phone,
            'message' => $message,
        ]);

        if (! $phone) {
            $log->fill([
                'status' => 'no_phone',
                'response' => 'No phone number found for check owner.',
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

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => $from,
                    'To' => $phone,
                    'Body' => $message,
                ]);

            $log->fill([
                'status' => $response->successful() ? 'sent' : 'failed',
                'response' => Str::limit($response->body(), 1000, ''),
                'sent_at' => $response->successful() ? now() : null,
            ])->save();
        } catch (\Throwable $e) {
            $log->fill([
                'status' => 'failed',
                'response' => $e->getMessage(),
            ])->save();
        }

        return $log;
    }

    private function resolvePhone(IncomingCheck|OutgoingCheck $check): ?string
    {
        if ($check instanceof IncomingCheck) {
            $person = $check->fromCustomer ?: $check->fromSeller ?: $check->toCustomer ?: $check->toSeller;
            return $person?->phone ?: $person?->sub_phone;
        }

        $person = $check->customer ?: $check->seller;
        return $person?->phone ?: $person?->sub_phone;
    }

    private function renderMessage(string $template, IncomingCheck|OutgoingCheck $check): string
    {
        $person = $check instanceof IncomingCheck
            ? ($check->fromCustomer ?: $check->fromSeller ?: $check->toCustomer ?: $check->toSeller)
            : ($check->customer ?: $check->seller);

        return strtr($template, [
            '{name}' => (string) ($person?->name ?? ''),
            '{check_number}' => (string) ($check->check_id ?? ''),
            '{amount}' => (string) ($check->total ?? ''),
            '{currency}' => (string) ($check->currency ?? ''),
            '{due_date}' => $check->due_date ? (string) $check->due_date : '',
            '{bank}' => (string) ($check->bank_name ?? ''),
        ]);
    }
}
