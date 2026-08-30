<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\NotificationDeviceSound;
use App\Models\NotificationPolicy;
use App\Models\NotificationSound;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Support\NotificationCatalog;
use Illuminate\Support\Facades\Schema;

class NotificationControlService
{
    public function syncDefaults(): void
    {
        if (! Schema::hasTable('notification_sounds') || ! Schema::hasTable('notification_policies')) {
            return;
        }

        foreach (NotificationCatalog::bundledSounds() as $key => $definition) {
            NotificationSound::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'source' => 'bundled',
                    'category' => $definition['category'] ?? 'system',
                    'android_resource' => $definition['android'],
                    'ios_filename' => $definition['ios'],
                    'is_active' => true,
                    'background_capable' => true,
                ]
            );
        }

        $sounds = NotificationSound::query()->where('source', 'bundled')->get()->keyBy('key');
        foreach (NotificationCatalog::types() as $type => $definition) {
            NotificationPolicy::query()->firstOrCreate(
                ['notification_type' => $type],
                [
                    'priority' => $definition['priority'],
                    'sound_id' => $sounds->get($definition['sound'])?->id,
                    'fallback_sound_id' => $sounds->get('default')?->id,
                    'show_on_lock_screen' => ! $definition['sensitive'],
                ]
            );
        }
    }

    public function policyFor(string $type): ?NotificationPolicy
    {
        if (! Schema::hasTable('notification_policies')) {
            return null;
        }

        return NotificationPolicy::query()
            ->with(['sound', 'fallbackSound'])
            ->where('notification_type', $type)
            ->first();
    }

    /**
     * Return the global notification types this admin may see in the in-app
     * center. Targeted notifications are handled separately by the query.
     *
     * @return list<string>
     */
    public function visibleGlobalTypesFor(User $user): array
    {
        if (! Schema::hasTable('notification_policies')) {
            return array_keys(NotificationCatalog::types());
        }

        $policies = NotificationPolicy::query()
            ->get(['notification_type', 'audience', 'recipient_user_ids', 'recipient_roles'])
            ->keyBy('notification_type');
        $role = (string) ($user->development_role ?? '');

        return collect(array_keys(NotificationCatalog::types()))
            ->filter(function (string $type) use ($policies, $user, $role): bool {
                $policy = $policies->get($type);
                if (! $policy || $policy->audience === 'all_admins') {
                    return true;
                }

                if ($policy->audience === 'selected_users') {
                    return in_array((int) $user->id, array_map('intval', $policy->recipient_user_ids ?: []), true);
                }

                return $policy->audience === 'roles'
                    && $role !== ''
                    && in_array($role, $policy->recipient_roles ?: [], true);
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data @return array{title: string, body: string, lock_screen: ?string} */
    public function render(string $type, string $title, string $body, array $data): array
    {
        if (! Schema::hasTable('notification_templates')) {
            return ['title' => $title, 'body' => $body, 'lock_screen' => null];
        }

        $template = NotificationTemplate::query()
            ->where('notification_type', $type)
            ->where('locale', app()->getLocale())
            ->where('is_active', true)
            ->first();
        if (! $template) {
            return ['title' => $title, 'body' => $body, 'lock_screen' => null];
        }

        $values = array_merge($data, ['title' => $title, 'body' => $body]);
        $replace = function (string $value) use ($values): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($match) use ($values) {
                $resolved = $values[$match[1]] ?? $match[0];

                return is_scalar($resolved) || $resolved === null ? (string) $resolved : $match[0];
            }, $value) ?? $value;
        };

        return [
            'title' => $replace($template->title_template),
            'body' => $replace($template->body_template),
            'lock_screen' => $template->lock_screen_template
                ? $replace($template->lock_screen_template)
                : null,
        ];
    }

    public function pushAllowedNow(NotificationPolicy $policy): bool
    {
        if (! $policy->is_enabled || ! $policy->push_enabled) {
            return false;
        }
        if ($policy->bypass_quiet_hours || ! $policy->quiet_hours_start || ! $policy->quiet_hours_end) {
            return true;
        }

        $now = now()->format('H:i:s');
        $start = (string) $policy->quiet_hours_start;
        $end = (string) $policy->quiet_hours_end;

        return $start < $end
            ? ! ($now >= $start && $now < $end)
            : ! ($now >= $start || $now < $end);
    }

    /** @return array<string, string> */
    public function deliveryData(string $type, AdminDeviceToken $device): array
    {
        $policy = $this->policyFor($type);
        if (! $policy) {
            return [];
        }

        $requested = $policy->sound;
        $resolved = $requested;
        $usedFallback = false;

        if ($requested?->source === 'uploaded') {
            $ready = NotificationDeviceSound::query()
                ->where('admin_device_token_id', $device->id)
                ->where('notification_sound_id', $requested->id)
                ->where('sound_version', $requested->version)
                ->where('status', 'ready')
                ->first();

            if (! $ready) {
                $resolved = $policy->fallbackSound ?: $requested->fallback;
                $usedFallback = true;
            }
        }

        $bundled = $resolved ? (NotificationCatalog::bundledSounds()[$resolved->key] ?? null) : null;
        $channelId = $bundled['channel'] ?? ($resolved
            ? 'dr_bike_custom_'.$resolved->id.'_v'.$resolved->version
            : 'dr_bike_admin_notifications');
        if (! $policy->vibration_enabled) {
            $channelId .= '_no_vibration';
        }

        return [
            'notification_priority' => (string) $policy->priority,
            'notification_sound_id' => (string) ($resolved?->id ?? ''),
            'notification_sound_key' => (string) ($resolved?->key ?? 'default'),
            'notification_sound_version' => (string) ($resolved?->version ?? 1),
            'notification_channel_id' => $channelId,
            'notification_android_sound' => (string) ($resolved?->android_resource ?? 'default'),
            'notification_ios_sound' => (string) ($resolved?->ios_filename ?? 'default'),
            'notification_sound_url' => $resolved?->source === 'uploaded'
                ? 'admin/notification-sounds/'.$resolved->id.'/file'
                : '',
            'notification_requested_sound_id' => (string) ($requested?->id ?? ''),
            'notification_requested_sound_key' => (string) ($requested?->key ?? ''),
            'notification_requested_sound_version' => (string) ($requested?->version ?? 1),
            'notification_requested_sound_filename' => (string) ($requested?->ios_filename ?? ''),
            'notification_requested_sound_url' => $requested?->source === 'uploaded'
                ? 'admin/notification-sounds/'.$requested->id.'/file'
                : '',
            'notification_used_fallback' => $usedFallback ? '1' : '0',
            'notification_vibration' => $policy->vibration_enabled ? '1' : '0',
            'notification_foreground_banner' => $policy->show_foreground_banner ? '1' : '0',
            'notification_lock_screen' => $policy->show_on_lock_screen ? '1' : '0',
        ];
    }
}
