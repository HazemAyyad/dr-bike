<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAuditEvent;
use App\Models\User;
use Illuminate\Support\Arr;

final class OnlineStoreAuditService
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization',
        'secret', 'otp', 'reset_token', 'phone', 'whatsapp', 'email', 'address',
    ];

    public function record(
        ?User $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
    ): OnlineStoreAuditEvent {
        if (! in_array($entityType, OnlineStoreAuditEvent::ENTITY_TYPES, true)
            || ! in_array($action, OnlineStoreAuditEvent::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Online Store audit entity or action.');
        }

        return OnlineStoreAuditEvent::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_values' => $this->redact($before),
            'after_values' => $this->redact($after),
            'request_id' => $requestId ?? request()?->header('X-Request-ID'),
            'ip_address' => $ipAddress ?? request()?->ip(),
            'occurred_at' => now(),
        ]);
    }

    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return collect($values)->mapWithKeys(function ($value, $key) {
            $normalized = mb_strtolower((string) $key);
            if (collect(self::SENSITIVE_KEYS)->contains(fn (string $sensitive) => str_contains($normalized, $sensitive))) {
                return [$key => '[REDACTED]'];
            }

            if (is_array($value)) {
                return [$key => $this->redact($value)];
            }

            return [$key => is_object($value) ? Arr::wrap($value) : $value];
        })->all();
    }
}
