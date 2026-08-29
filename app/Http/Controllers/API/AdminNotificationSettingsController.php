<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminDeviceToken;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationDeviceSound;
use App\Models\NotificationPolicy;
use App\Models\NotificationPolicyAudit;
use App\Models\NotificationSound;
use App\Models\NotificationTemplate;
use App\Services\NotificationControlService;
use App\Support\NotificationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminNotificationSettingsController extends Controller
{
    public function __construct(private readonly NotificationControlService $control) {}

    public function catalog()
    {
        $this->control->syncDefaults();
        $policies = NotificationPolicy::query()->with(['sound', 'fallbackSound'])
            ->get()->keyBy('notification_type');

        $items = collect(NotificationCatalog::types())->map(function (array $definition, string $type) use ($policies) {
            return array_merge(['type' => $type], $definition, [
                'policy' => $policies->get($type),
            ]);
        })->values();

        return response()->json(['status' => 'success', 'types' => $items]);
    }

    public function policies()
    {
        $this->control->syncDefaults();

        return response()->json([
            'status' => 'success',
            'policies' => NotificationPolicy::query()
                ->with(['sound', 'fallbackSound'])->orderBy('notification_type')->get(),
        ]);
    }

    public function updatePolicy(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, NotificationCatalog::types()), 404);
        $this->control->syncDefaults();

        $data = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'in_app_enabled' => 'sometimes|boolean',
            'push_enabled' => 'sometimes|boolean',
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'critical'])],
            'sound_id' => 'sometimes|nullable|exists:notification_sounds,id',
            'fallback_sound_id' => 'sometimes|nullable|exists:notification_sounds,id',
            'vibration_enabled' => 'sometimes|boolean',
            'show_foreground_banner' => 'sometimes|boolean',
            'show_on_lock_screen' => 'sometimes|boolean',
            'quiet_hours_start' => 'sometimes|nullable|date_format:H:i',
            'quiet_hours_end' => 'sometimes|nullable|date_format:H:i',
            'bypass_quiet_hours' => 'sometimes|boolean',
            'cooldown_seconds' => 'sometimes|integer|min:0|max:86400',
            'audience' => ['sometimes', Rule::in(['all_admins', 'selected_users', 'roles'])],
            'recipient_user_ids' => 'sometimes|nullable|array',
            'recipient_user_ids.*' => 'integer|exists:users,id',
            'recipient_roles' => 'sometimes|nullable|array',
            'recipient_roles.*' => 'string|max:64',
        ]);

        $policy = NotificationPolicy::query()->where('notification_type', $type)->firstOrFail();
        $before = $policy->toArray();
        $policy->fill($data)->forceFill(['updated_by' => $request->user()->id])->save();
        $this->audit($request, 'policy', $policy->id, 'updated', $before, $policy->fresh()->toArray());

        return response()->json([
            'status' => 'success',
            'policy' => $policy->fresh()->load(['sound', 'fallbackSound']),
        ]);
    }

    public function resetPolicy(Request $request, string $type)
    {
        $definition = NotificationCatalog::types()[$type] ?? null;
        abort_unless($definition, 404);
        $this->control->syncDefaults();

        $sounds = NotificationSound::query()->get()->keyBy('key');
        $policy = NotificationPolicy::query()->where('notification_type', $type)->firstOrFail();
        $before = $policy->toArray();
        $policy->update([
            'is_enabled' => true,
            'in_app_enabled' => true,
            'push_enabled' => true,
            'priority' => $definition['priority'],
            'sound_id' => $sounds->get($definition['sound'])?->id,
            'fallback_sound_id' => $sounds->get('default')?->id,
            'vibration_enabled' => true,
            'show_foreground_banner' => true,
            'show_on_lock_screen' => ! $definition['sensitive'],
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'bypass_quiet_hours' => false,
            'cooldown_seconds' => 0,
            'audience' => 'all_admins',
            'recipient_user_ids' => null,
            'recipient_roles' => null,
            'updated_by' => $request->user()->id,
        ]);
        $this->audit($request, 'policy', $policy->id, 'reset', $before, $policy->fresh()->toArray());

        return response()->json(['status' => 'success', 'policy' => $policy->fresh()->load(['sound', 'fallbackSound'])]);
    }

    public function templates(Request $request)
    {
        $query = NotificationTemplate::query()->orderBy('notification_type')->orderBy('locale');
        if ($request->filled('type')) {
            $query->where('notification_type', $request->string('type'));
        }

        return response()->json(['status' => 'success', 'templates' => $query->get()]);
    }

    public function updateTemplate(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, NotificationCatalog::types()), 404);
        $data = $request->validate([
            'locale' => 'required|string|max:10',
            'title_template' => 'required|string|max:255',
            'body_template' => 'required|string|max:2000',
            'lock_screen_template' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $template = NotificationTemplate::query()->firstOrNew([
            'notification_type' => $type,
            'locale' => $data['locale'],
        ]);
        $before = $template->exists ? $template->toArray() : null;
        $template->fill($data)->forceFill(['updated_by' => $request->user()->id])->save();
        $this->audit($request, 'template', $template->id, $before ? 'updated' : 'created', $before, $template->toArray());

        return response()->json(['status' => 'success', 'template' => $template]);
    }

    public function sounds()
    {
        $this->control->syncDefaults();

        return response()->json([
            'status' => 'success',
            'sounds' => NotificationSound::query()->with('fallback')
                ->withCount('policies')->orderBy('source')->orderBy('name')->get(),
        ]);
    }

    public function storeSound(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:64',
            'fallback_sound_id' => 'nullable|exists:notification_sounds,id',
            'file' => 'required|file|max:2048|mimetypes:audio/wav,audio/x-wav,audio/vnd.wave,audio/mpeg,audio/mp3,audio/x-caf,audio/caf',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['wav', 'mp3', 'caf'], true), 422, 'صيغة الصوت غير مدعومة.');
        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = NotificationSound::query()->where('checksum', $checksum)->first();
        if ($existing) {
            return response()->json(['status' => 'error', 'message' => 'هذا الملف موجود مسبقاً.', 'sound' => $existing], 422);
        }

        $key = 'custom_'.Str::lower(Str::random(12));
        $path = $file->storeAs('notification-sounds', $key.'_v1.'.$extension, 'local');
        $sound = NotificationSound::query()->create([
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'custom',
            'source' => 'uploaded',
            'file_path' => $path,
            'ios_filename' => basename($path),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'checksum' => $checksum,
            'version' => 1,
            'background_capable' => false,
            'uploaded_by' => $request->user()->id,
            'fallback_sound_id' => $data['fallback_sound_id'] ?? null,
        ]);
        $this->audit($request, 'sound', $sound->id, 'created', null, $sound->toArray());

        return response()->json(['status' => 'success', 'sound' => $sound->load('fallback')], 201);
    }

    public function updateSound(Request $request, NotificationSound $sound)
    {
        abort_if($sound->source === 'bundled', 422, 'لا يمكن تعديل صوت نظام جاهز.');
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'category' => 'sometimes|string|max:64',
            'is_active' => 'sometimes|boolean',
            'fallback_sound_id' => 'sometimes|nullable|exists:notification_sounds,id',
        ]);
        $before = $sound->toArray();
        $sound->update($data);
        $this->audit($request, 'sound', $sound->id, 'updated', $before, $sound->fresh()->toArray());

        return response()->json(['status' => 'success', 'sound' => $sound->fresh()->load('fallback')]);
    }

    public function destroySound(Request $request, NotificationSound $sound)
    {
        abort_if($sound->source === 'bundled', 422, 'لا يمكن حذف صوت نظام جاهز.');
        abort_if($sound->policies()->exists(), 422, 'الصوت مستخدم في سياسة إشعار. استبدله أولاً.');
        $before = $sound->toArray();
        DB::transaction(function () use ($sound) {
            $path = $sound->getRawOriginal('file_path');
            $sound->delete();
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        });
        $this->audit($request, 'sound', $sound->id, 'deleted', $before, null);

        return response()->json(['status' => 'success']);
    }

    public function soundFile(NotificationSound $sound)
    {
        abort_unless($sound->source === 'uploaded' && $sound->is_active, 404);
        $path = $sound->getRawOriginal('file_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $sound->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }

    public function soundManifest()
    {
        $this->control->syncDefaults();

        return response()->json([
            'status' => 'success',
            'sounds' => NotificationSound::query()->where('is_active', true)->get()->map(fn (NotificationSound $sound) => [
                'id' => $sound->id,
                'key' => $sound->key,
                'name' => $sound->name,
                'source' => $sound->source,
                'version' => $sound->version,
                'checksum' => $sound->checksum,
                'file_size' => $sound->file_size,
                'android_resource' => $sound->android_resource,
                'ios_filename' => $sound->ios_filename,
                'download_url' => $sound->preview_url,
                'background_capable' => $sound->background_capable,
            ]),
        ]);
    }

    public function syncDeviceSounds(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => 'required|string|max:512',
            'sounds' => 'required|array|max:100',
            'sounds.*.sound_id' => 'required|integer|exists:notification_sounds,id',
            'sounds.*.version' => 'required|integer|min:1',
            'sounds.*.status' => ['required', Rule::in(['pending', 'downloading', 'ready', 'failed', 'outdated'])],
            'sounds.*.channel_id' => 'nullable|string|max:255',
            'sounds.*.error' => 'nullable|string|max:1000',
        ]);
        $device = AdminDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('fcm_token', $data['fcm_token'])->firstOrFail();

        foreach ($data['sounds'] as $row) {
            NotificationDeviceSound::query()->updateOrCreate(
                ['admin_device_token_id' => $device->id, 'notification_sound_id' => $row['sound_id']],
                [
                    'sound_version' => $row['version'],
                    'status' => $row['status'],
                    'channel_id' => $row['channel_id'] ?? null,
                    'last_error' => $row['error'] ?? null,
                    'synced_at' => now(),
                ]
            );
        }

        return response()->json(['status' => 'success']);
    }

    public function devices()
    {
        return response()->json([
            'status' => 'success',
            'devices' => AdminDeviceToken::query()->with('user:id,name,email')
                ->withCount([
                    'sounds as ready_sounds_count' => fn ($query) => $query->where('status', 'ready'),
                    'sounds as failed_sounds_count' => fn ($query) => $query->where('status', 'failed'),
                ])->orderByDesc('last_seen_at')->get(),
        ]);
    }

    public function deliveries(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        return response()->json([
            'status' => 'success',
            'deliveries' => NotificationDeliveryAttempt::query()
                ->with(['notification:id,type,title', 'device:id,user_id,device_name,platform'])
                ->latest('id')->paginate($perPage),
        ]);
    }

    private function audit(Request $request, string $type, ?int $id, string $action, ?array $before, ?array $after): void
    {
        NotificationPolicyAudit::query()->create([
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
        ]);
    }
}
