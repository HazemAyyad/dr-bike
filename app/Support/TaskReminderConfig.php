<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class TaskReminderConfig
{
    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    /**
     * @return array{minutes: int, channel: string}|null
     */
    public static function fromRecurrenceConfig(?array $config): ?array
    {
        if (! is_array($config) || ! array_key_exists('reminder_before_minutes', $config)) {
            return null;
        }

        $minutes = (int) $config['reminder_before_minutes'];
        $channel = (string) ($config['reminder_channel'] ?? self::CHANNEL_PUSH);
        if (! in_array($channel, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL], true)) {
            $channel = self::CHANNEL_PUSH;
        }

        return ['minutes' => max(0, $minutes), 'channel' => $channel];
    }

    /**
     * @return array{minutes: int, channel: string}|null
     */
    public static function fromLegacyTask(?int $minutes, ?string $channel): ?array
    {
        if ($minutes === null) {
            return null;
        }

        $ch = $channel ?? self::CHANNEL_PUSH;
        if (! in_array($ch, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL], true)) {
            $ch = self::CHANNEL_PUSH;
        }

        return ['minutes' => max(0, $minutes), 'channel' => $ch];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function mergeIntoRecurrenceConfig(array $config, ?int $minutes, ?string $channel): array
    {
        if ($minutes === null) {
            unset($config['reminder_before_minutes'], $config['reminder_channel']);

            return $config;
        }

        $config['reminder_before_minutes'] = max(0, $minutes);
        $config['reminder_channel'] = in_array($channel, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL], true)
            ? $channel
            : self::CHANNEL_PUSH;

        return $config;
    }

    public static function minutesFromRequest(Request $request): ?int
    {
        if ($request->has('reminder_before_minutes')) {
            $raw = $request->input('reminder_before_minutes');
            if ($raw === '' || $raw === null) {
                return null;
            }

            return max(0, (int) $raw);
        }

        $config = $request->input('recurrence_config');
        if (is_array($config) && array_key_exists('reminder_before_minutes', $config)) {
            return max(0, (int) $config['reminder_before_minutes']);
        }

        $when = $request->input('reminder_when');
        if ($when === null || $when === '' || $when === 'none') {
            return null;
        }

        return match ($when) {
            'before_10m' => 10,
            'before_1h' => 60,
            'before_1d' => 24 * 60,
            'at_time' => 0,
            default => null,
        };
    }

    public static function channelFromRequest(Request $request): string
    {
        $config = $request->input('recurrence_config');
        if (is_array($config) && isset($config['reminder_channel'])) {
            $channel = (string) $config['reminder_channel'];
        } else {
            $channel = (string) $request->input('reminder_channel', self::CHANNEL_PUSH);
        }

        return in_array($channel, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL], true)
            ? $channel
            : self::CHANNEL_PUSH;
    }

    public static function remindAt(Carbon $start, int $minutesBefore): Carbon
    {
        return $start->copy()->subMinutes($minutesBefore);
    }

    public static function isDueNow(Carbon $remindAt, Carbon $now, int $windowMinutes = 10): bool
    {
        return $now->gte($remindAt) && $now->lt($remindAt->copy()->addMinutes($windowMinutes));
    }

    public static function whenFromMinutes(int $minutes): string
    {
        return match ($minutes) {
            10 => 'before_10m',
            60 => 'before_1h',
            1440 => 'before_1d',
            0 => 'at_time',
            default => 'none',
        };
    }

    /**
     * @return array{reminder_when: string, reminder_channel: string, reminder_before_minutes: int|null}
     */
    public static function apiReminderFields(?array $config, ?int $legacyMinutes = null, ?string $legacyChannel = null): array
    {
        $parsed = self::fromRecurrenceConfig($config);
        if ($parsed === null && $legacyMinutes !== null) {
            $parsed = self::fromLegacyTask($legacyMinutes, $legacyChannel);
        }

        if ($parsed === null) {
            return [
                'reminder_when' => 'none',
                'reminder_channel' => self::CHANNEL_PUSH,
                'reminder_before_minutes' => null,
            ];
        }

        return [
            'reminder_when' => self::whenFromMinutes($parsed['minutes']),
            'reminder_channel' => $parsed['channel'],
            'reminder_before_minutes' => $parsed['minutes'],
        ];
    }
}
