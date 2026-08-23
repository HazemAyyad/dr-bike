<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SmartDeviceActivityLogResource;
use App\Http\Resources\SmartDeviceFunctionResource;
use App\Http\Resources\SmartDeviceResource;
use App\Http\Resources\SmartHomeEventLogResource;
use App\Http\Resources\SmartHomeResource;
use App\Http\Resources\SmartRoomResource;
use App\Models\SmartDevice;
use App\Models\SmartDeviceActivityLog;
use App\Models\SmartDeviceFunction;
use App\Models\SmartHome;
use App\Models\SmartHomeEventLog;
use App\Models\SmartHomeTuyaUser;
use App\Models\SmartRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmartHomeController extends Controller
{
    public function homes(Request $request)
    {
        $userId = $this->requestedOwnerId($request);
        $homes = SmartHome::query()
            ->where('user_id', $userId)
            ->withCount(['rooms', 'devices'])
            ->withCount(['devices as online_devices_count' => fn (Builder $query) => $query->where('online', true)])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'homes' => SmartHomeResource::collection($homes),
        ]);
    }

    public function owners(Request $request)
    {
        abort_unless($request->user()?->type === 'admin', 403);

        $owners = User::query()
            ->select('id', 'name', 'phone', 'type')
            ->whereHas('smartDevices')
            ->withCount([
                'smartHomes as homes_count',
                'smartDevices as devices_count',
                'smartDevices as online_devices_count' => fn (Builder $query) => $query->where('online', true),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'type' => $user->type,
                'homes_count' => (int) ($user->homes_count ?? 0),
                'devices_count' => (int) ($user->devices_count ?? 0),
                'online_devices_count' => (int) ($user->online_devices_count ?? 0),
            ]);

        return response()->json([
            'status' => 'success',
            'owners' => $owners,
        ]);
    }

    public function storeHome(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tuya_home_id' => ['nullable', 'string', 'max:128'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geo_name' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:40', Rule::in([SmartHome::TYPE_HOME, SmartHome::TYPE_COMPANY])],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([SmartHome::STATUS_ACTIVE, SmartHome::STATUS_ARCHIVED])],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        $home = DB::transaction(function () use ($request, $data) {
            $userId = $this->requestedOwnerId($request);
            $isDefault = (bool) ($data['is_default'] ?? ! SmartHome::where('user_id', $userId)->exists());

            if ($isDefault) {
                SmartHome::where('user_id', $userId)->update(['is_default' => false]);
            }

            return SmartHome::create([
                ...$data,
                'user_id' => $userId,
                'type' => $data['type'] ?? SmartHome::TYPE_HOME,
                'is_default' => $isDefault,
                'status' => $data['status'] ?? SmartHome::STATUS_ACTIVE,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء المنزل الذكي',
            'home' => new SmartHomeResource($this->homeQuery($request, $home->id)->first()),
        ], 201);
    }

    public function showHome(Request $request, int $id)
    {
        return response()->json([
            'status' => 'success',
            'home' => new SmartHomeResource($this->readableHomeQuery($request, $id)->firstOrFail()),
        ]);
    }

    public function updateHome(Request $request, int $id)
    {
        $home = $this->ownedHome($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'tuya_home_id' => ['nullable', 'string', 'max:128'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geo_name' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:40', Rule::in([SmartHome::TYPE_HOME, SmartHome::TYPE_COMPANY])],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([SmartHome::STATUS_ACTIVE, SmartHome::STATUS_ARCHIVED])],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($request, $home, $data) {
            if (($data['is_default'] ?? false) === true) {
                SmartHome::where('user_id', $this->requestedOwnerId($request))->whereKeyNot($home->id)->update(['is_default' => false]);
            }
            $home->update($data);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث المنزل الذكي',
            'home' => new SmartHomeResource($this->homeQuery($request, $home->id)->first()),
        ]);
    }

    public function destroyHome(Request $request, int $id)
    {
        $home = $this->ownedHome($request, $id);
        $home->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف المنزل الذكي',
        ]);
    }

    public function rooms(Request $request, int $homeId)
    {
        $this->readableHome($request, $homeId);

        $rooms = SmartRoom::query()
            ->where('smart_home_id', $homeId)
            ->withCount('devices')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'rooms' => SmartRoomResource::collection($rooms),
        ]);
    }

    public function storeRoom(Request $request, int $homeId)
    {
        $this->ownedHome($request, $homeId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tuya_room_id' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $room = SmartRoom::create([
            ...$data,
            'smart_home_id' => $homeId,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء الغرفة',
            'room' => new SmartRoomResource($room),
        ], 201);
    }

    public function updateRoom(Request $request, int $id)
    {
        $room = $this->ownedRoom($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'tuya_room_id' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $room->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الغرفة',
            'room' => new SmartRoomResource($room),
        ]);
    }

    public function destroyRoom(Request $request, int $id)
    {
        $room = $this->ownedRoom($request, $id);
        SmartDevice::where('smart_room_id', $room->id)->update(['smart_room_id' => null]);
        $room->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الغرفة',
        ]);
    }

    public function devices(Request $request)
    {
        $query = SmartDevice::query()
            ->with(['room:id,name,tuya_room_id', 'functions'])
            ->where(fn (Builder $query) => $query
                ->where('user_id', $this->requestedOwnerId($request))
                ->orWhereHas('home', fn (Builder $query) => $query->where('user_id', $this->requestedOwnerId($request))));

        if ($request->filled('home_id')) {
            if ((string) $request->input('home_id') === 'unassigned') {
                $query->whereNull('smart_home_id');
            } else {
                $this->readableHome($request, (int) $request->input('home_id'));
                $query->where('smart_home_id', (int) $request->input('home_id'));
            }
        }

        if ($request->filled('room_id')) {
            $room = $this->readableRoom($request, (int) $request->input('room_id'));
            $query->where('smart_room_id', $room->id);
        }

        if ($request->filled('online')) {
            $query->where('online', $request->boolean('online'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%"));
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        $devices = $query->orderBy('name')->paginate($perPage);
        $devices->getCollection()->each(fn (SmartDevice $device) => $this->syncDeviceFunctions($device));
        $devices->getCollection()->load('functions');

        return response()->json([
            'status' => 'success',
            'devices' => $devices->through(fn (SmartDevice $device) => (new SmartDeviceResource($device))->resolve($request)),
        ]);
    }

    public function showDevice(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id)->load('room:id,name,tuya_room_id');
        $this->syncDeviceFunctions($device);

        return response()->json([
            'status' => 'success',
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ]);
    }

    public function registerDevice(Request $request)
    {
        $data = $this->validateDevicePayload($request, true);
        $home = $this->ownedHome($request, (int) $data['smart_home_id']);
        $roomId = $this->validRoomId($request, $data['smart_room_id'] ?? null, $home->id);

        $device = DB::transaction(function () use ($request, $data, $home, $roomId) {
            $device = SmartDevice::withTrashed()->where('tuya_device_id', $data['tuya_device_id'])->first();
            $payload = $this->devicePayload($data, $home->id, $roomId, $this->requestedOwnerId($request));

            if ($device) {
                $device->restore();
                $device->update($payload);
                return $device;
            }

            return SmartDevice::create($payload + [
                'paired_at' => $data['paired_at'] ?? now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم تسجيل الجهاز',
            'device' => new SmartDeviceResource(tap($device->fresh(), fn (SmartDevice $device) => $this->syncDeviceFunctions($device))->load(['room:id,name,tuya_room_id', 'functions'])),
        ], 201);
    }

    public function syncDevices(Request $request)
    {
        $data = $request->validate([
            'smart_home_id' => ['required', 'integer'],
            'devices' => ['required', 'array'],
            'devices.*.tuya_device_id' => ['required', 'string', 'max:191'],
            'devices.*.smart_room_id' => ['nullable', 'integer'],
            'devices.*.tuya_product_id' => ['nullable', 'string', 'max:191'],
            'devices.*.tuya_uuid' => ['nullable', 'string', 'max:191'],
            'devices.*.name' => ['required', 'string', 'max:191'],
            'devices.*.category' => ['nullable', 'string', 'max:80'],
            'devices.*.product_name' => ['nullable', 'string', 'max:191'],
            'devices.*.icon' => ['nullable', 'string'],
            'devices.*.protocol' => ['nullable', 'string', 'max:64'],
            'devices.*.online' => ['sometimes', 'boolean'],
            'devices.*.model' => ['nullable', 'string', 'max:191'],
            'devices.*.manufacturer' => ['nullable', 'string', 'max:191'],
            'devices.*.raw_metadata' => ['nullable', 'array'],
            'devices.*.last_status' => ['nullable', 'array'],
            'devices.*.paired_at' => ['nullable', 'date'],
            'devices.*.last_seen_at' => ['nullable', 'date'],
        ]);

        $home = $this->ownedHome($request, (int) $data['smart_home_id']);
        $synced = collect();

        DB::transaction(function () use ($request, $data, $home, &$synced) {
            foreach ($data['devices'] as $item) {
                $roomId = $this->validRoomId($request, $item['smart_room_id'] ?? null, $home->id);
                $device = SmartDevice::withTrashed()->where('tuya_device_id', $item['tuya_device_id'])->first();
                $payload = $this->devicePayload($item, $home->id, $roomId, $this->requestedOwnerId($request));

                if ($device) {
                    $device->restore();
                    $device->update($payload);
                } else {
                    $device = SmartDevice::create($payload + [
                        'paired_at' => $item['paired_at'] ?? now(),
                    ]);
                }
                $this->syncDeviceFunctions($device);
                $synced->push($device->fresh()->load(['room:id,name,tuya_room_id', 'functions']));
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تمت مزامنة الأجهزة',
            'devices' => SmartDeviceResource::collection($synced),
        ]);
    }

    public function updateDevice(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'smart_room_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:80'],
            'product_name' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string'],
            'protocol' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:191'],
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        if (array_key_exists('smart_room_id', $data)) {
            $data['smart_room_id'] = $this->validRoomId($request, $data['smart_room_id'], $device->smart_home_id);
        }

        $device->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الجهاز',
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ]);
    }

    public function moveDevice(Request $request, int $id)
    {
        $device = $this->ownedDevice($request, $id);
        $data = $request->validate([
            'smart_home_id' => ['nullable', 'integer'],
            'smart_room_id' => ['nullable', 'integer'],
        ]);

        $homeId = array_key_exists('smart_home_id', $data) && $data['smart_home_id'] !== null
            ? $this->ownedHome($request, (int) $data['smart_home_id'])->id
            : null;
        $roomId = array_key_exists('smart_room_id', $data)
            ? $this->validRoomId($request, $data['smart_room_id'], $homeId)
            : null;

        if ($roomId !== null) {
            $room = $this->ownedRoom($request, $roomId);
            $homeId = (int) $room->smart_home_id;
        }

        $device->update([
            'user_id' => $this->requestedOwnerId($request),
            'smart_home_id' => $homeId,
            'smart_room_id' => $roomId,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم نقل الجهاز',
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ]);
    }

    public function deviceFunctions(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $this->syncDeviceFunctions($device);

        return response()->json([
            'status' => 'success',
            'functions' => SmartDeviceFunctionResource::collection(
                $device->fresh()->functions()->orderBy('sort_order')->orderBy('id')->get()
            ),
        ]);
    }

    public function updateDeviceFunction(Request $request, int $deviceId, int $functionId)
    {
        $device = $this->ownedDevice($request, $deviceId);
        $function = $device->functions()->whereKey($functionId)->firstOrFail();
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        if (array_key_exists('display_name', $data)) {
            $data['display_name'] = trim((string) $data['display_name']);
            abort_if($data['display_name'] === '', 422, 'اسم المفتاح مطلوب');
        }

        $function->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث المفتاح',
            'function' => new SmartDeviceFunctionResource($function->fresh()),
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ]);
    }

    public function destroyDevice(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الجهاز',
        ]);
    }

    public function updateDeviceStatus(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $data = $request->validate([
            'online' => ['sometimes', 'boolean'],
            'last_status' => ['nullable', 'array'],
            'raw_metadata' => ['nullable', 'array'],
            'last_seen_at' => ['nullable', 'date'],
        ]);

        $device->update([
            ...$data,
            'last_seen_at' => $data['last_seen_at'] ?? now(),
        ]);

        $this->syncDeviceFunctions($device);

        return response()->json([
            'status' => 'success',
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ]);
    }

    public function storeActivityLog(Request $request, int $id)
    {
        $device = $this->ownedDevice($request, $id);
        $data = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'command_code' => ['nullable', 'string', 'max:120'],
            'command_value' => ['nullable', 'array'],
            'success' => ['required', 'boolean'],
            'error_code' => ['nullable', 'string', 'max:120'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $log = SmartDeviceActivityLog::create([
            ...$data,
            'smart_device_id' => $device->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'log' => new SmartDeviceActivityLogResource($log),
        ], 201);
    }

    public function storeControlLog(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $data = $request->validate([
            'command_code' => ['required', 'string', 'max:120'],
            'command_value' => ['nullable', 'array'],
            'success' => ['required', 'boolean'],
            'error_code' => ['nullable', 'string', 'max:120'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'last_status' => ['nullable', 'array'],
            'online' => ['sometimes', 'boolean'],
        ]);

        $log = SmartDeviceActivityLog::create([
            'smart_device_id' => $device->id,
            'user_id' => $request->user()->id,
            'action' => 'tuya_control',
            'command_code' => $data['command_code'],
            'command_value' => $data['command_value'] ?? null,
            'success' => (bool) $data['success'],
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
        ]);

        if ((bool) $data['success']) {
            $device->update([
                'last_status' => $data['last_status'] ?? $device->last_status,
                'online' => $data['online'] ?? $device->online,
                'last_seen_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'log' => new SmartDeviceActivityLogResource($log),
            'device' => new SmartDeviceResource($device->fresh()->load(['room:id,name,tuya_room_id', 'functions'])),
        ], 201);
    }

    public function deviceActivity(Request $request, int $id)
    {
        $device = $this->readableDevice($request, $id);
        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        return response()->json([
            'status' => 'success',
            'activity' => SmartDeviceActivityLogResource::collection(
                $device->activityLogs()->with('user:id,name')->latest()->paginate($perPage)
            ),
        ]);
    }

    public function eventLogs(Request $request)
    {
        $query = SmartHomeEventLog::query()
            ->where('user_id', $this->requestedOwnerId($request))
            ->latest();

        if ($request->filled('home_id')) {
            $home = $this->readableHome($request, (int) $request->input('home_id'));
            $query->where('smart_home_id', $home->id);
        }

        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        return response()->json([
            'status' => 'success',
            'logs' => SmartHomeEventLogResource::collection($query->paginate($perPage)),
        ]);
    }

    public function storeEventLog(Request $request)
    {
        $data = $request->validate([
            'smart_home_id' => ['nullable', 'integer'],
            'event' => ['required', 'string', 'max:100'],
            'success' => ['required', 'boolean'],
            'error_code' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:4000'],
            'context' => ['nullable', 'array'],
        ]);

        if (! empty($data['smart_home_id'])) {
            $home = $this->ownedHome($request, (int) $data['smart_home_id']);
            $data['smart_home_id'] = $home->id;
        }

        $log = SmartHomeEventLog::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'log' => new SmartHomeEventLogResource($log),
        ], 201);
    }

    public function tuyaUser(Request $request)
    {
        $userId = $this->requestedOwnerId($request);
        $mapping = SmartHomeTuyaUser::firstOrCreate(['user_id' => $userId], [
            'region' => config('services.tuya.region'),
        ]);
        $login = $this->tuyaUidLoginPayload($userId);

        return response()->json([
            'status' => 'success',
            'tuya_user' => [
                'user_id' => (int) $mapping->user_id,
                'tuya_uid' => $mapping->tuya_uid,
                'region' => $mapping->region,
                'last_login_at' => $mapping->last_login_at?->toISOString(),
                'linked' => filled($mapping->tuya_uid),
                'uid_login' => $login,
            ],
        ]);
    }

    public function updateTuyaUser(Request $request)
    {
        $data = $request->validate([
            'tuya_uid' => ['required', 'string', 'max:191'],
            'region' => ['nullable', 'string', 'max:64'],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        $mapping = SmartHomeTuyaUser::updateOrCreate(
            ['user_id' => $this->requestedOwnerId($request)],
            [
                ...$data,
                'last_login_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'tuya_user' => [
                'user_id' => (int) $mapping->user_id,
                'tuya_uid' => $mapping->tuya_uid,
                'region' => $mapping->region,
                'last_login_at' => $mapping->last_login_at?->toISOString(),
                'linked' => filled($mapping->tuya_uid),
            ],
        ]);
    }

    private function tuyaUidLoginPayload(int $userId): array
    {
        $uid = 'doctorbike_user_'.$userId;
        $secret = config('app.key') ?: config('services.tuya.access_secret') ?: 'doctor-bike';

        return [
            'country_code' => config('services.tuya.country_code', '970'),
            'uid' => $uid,
            'password' => hash_hmac('sha256', $uid, $secret),
        ];
    }

    private function homeQuery(Request $request, int $id): Builder
    {
        return SmartHome::query()
            ->where('user_id', $this->requestedOwnerId($request))
            ->whereKey($id)
            ->withCount(['rooms', 'devices'])
            ->withCount(['devices as online_devices_count' => fn (Builder $query) => $query->where('online', true)]);
    }

    private function readableHomeQuery(Request $request, int $id): Builder
    {
        return SmartHome::query()
            ->where('user_id', $this->requestedOwnerId($request))
            ->whereKey($id)
            ->withCount(['rooms', 'devices'])
            ->withCount(['devices as online_devices_count' => fn (Builder $query) => $query->where('online', true)]);
    }

    private function requestedOwnerId(Request $request): int
    {
        if ($request->user()?->type === 'admin' && $request->filled('user_id')) {
            return (int) $request->input('user_id');
        }

        return (int) $request->user()->id;
    }

    private function readableHome(Request $request, int $id): SmartHome
    {
        return SmartHome::query()
            ->where('user_id', $this->requestedOwnerId($request))
            ->whereKey($id)
            ->firstOrFail();
    }

    private function ownedHome(Request $request, int $id): SmartHome
    {
        return SmartHome::query()
            ->where('user_id', $this->requestedOwnerId($request))
            ->whereKey($id)
            ->firstOrFail();
    }

    private function ownedRoom(Request $request, int $id): SmartRoom
    {
        return SmartRoom::query()
            ->whereKey($id)
            ->whereHas('home', fn (Builder $query) => $query->where('user_id', $this->requestedOwnerId($request)))
            ->firstOrFail();
    }

    private function readableRoom(Request $request, int $id): SmartRoom
    {
        return SmartRoom::query()
            ->whereKey($id)
            ->whereHas('home', fn (Builder $query) => $query->where('user_id', $this->requestedOwnerId($request)))
            ->firstOrFail();
    }

    private function ownedDevice(Request $request, int $id): SmartDevice
    {
        return SmartDevice::query()
            ->whereKey($id)
            ->where(fn (Builder $query) => $query
                ->where('user_id', $this->requestedOwnerId($request))
                ->orWhereHas('home', fn (Builder $query) => $query->where('user_id', $this->requestedOwnerId($request))))
            ->firstOrFail();
    }

    private function readableDevice(Request $request, int $id): SmartDevice
    {
        return SmartDevice::query()
            ->whereKey($id)
            ->where(fn (Builder $query) => $query
                ->where('user_id', $this->requestedOwnerId($request))
                ->orWhereHas('home', fn (Builder $query) => $query->where('user_id', $this->requestedOwnerId($request))))
            ->firstOrFail();
    }

    private function validRoomId(Request $request, mixed $roomId, ?int $homeId): ?int
    {
        if ($roomId === null || $roomId === '') {
            return null;
        }

        abort_if($homeId === null, 422, 'لا يمكن اختيار غرفة بدون مكان');
        $room = $this->ownedRoom($request, (int) $roomId);
        abort_unless((int) $room->smart_home_id === $homeId, 422, 'الغرفة لا تتبع هذا المنزل');

        return (int) $room->id;
    }

    private function validateDevicePayload(Request $request, bool $requireHome): array
    {
        return $request->validate([
            'smart_home_id' => [$requireHome ? 'required' : 'sometimes', 'integer'],
            'smart_room_id' => ['nullable', 'integer'],
            'tuya_device_id' => ['required', 'string', 'max:191'],
            'tuya_product_id' => ['nullable', 'string', 'max:191'],
            'tuya_uuid' => ['nullable', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:80'],
            'product_name' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string'],
            'protocol' => ['nullable', 'string', 'max:64'],
            'online' => ['sometimes', 'boolean'],
            'model' => ['nullable', 'string', 'max:191'],
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'raw_metadata' => ['nullable', 'array'],
            'last_status' => ['nullable', 'array'],
            'paired_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
        ]);
    }

    private function devicePayload(array $data, int $homeId, ?int $roomId, int $userId): array
    {
        return [
            'smart_home_id' => $homeId,
            'user_id' => $userId,
            'smart_room_id' => $roomId,
            'tuya_device_id' => $data['tuya_device_id'],
            'tuya_product_id' => $data['tuya_product_id'] ?? null,
            'tuya_uuid' => $data['tuya_uuid'] ?? null,
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'product_name' => $data['product_name'] ?? null,
            'icon' => $data['icon'] ?? null,
            'protocol' => $data['protocol'] ?? null,
            'online' => (bool) ($data['online'] ?? false),
            'model' => $data['model'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'raw_metadata' => $data['raw_metadata'] ?? null,
            'last_status' => $data['last_status'] ?? null,
            'paired_at' => $data['paired_at'] ?? null,
            'last_seen_at' => $data['last_seen_at'] ?? null,
        ];
    }

    private function syncDeviceFunctions(SmartDevice $device): void
    {
        $metadata = $device->raw_metadata ?? [];
        if (! is_array($metadata) || $metadata === []) {
            return;
        }

        foreach ($this->primaryFunctionDefinitions($metadata) as $definition) {
            $function = SmartDeviceFunction::firstOrNew([
                'smart_device_id' => $device->id,
                'code' => $definition['code'],
            ]);

            $function->fill([
                'dp_id' => $definition['dp_id'],
                'function_type' => $definition['function_type'],
                'sort_order' => $function->exists ? $function->sort_order : $definition['sort_order'],
                'is_visible' => $function->exists ? $function->is_visible : true,
            ]);
            $function->save();
        }
    }

    private function primaryFunctionDefinitions(array $metadata): array
    {
        $definitions = [];
        foreach ($this->schemaEntries($metadata) as $key => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $dpId = trim((string) ($raw['id'] ?? $key));
            $code = trim((string) ($raw['code'] ?? ''));
            $mode = strtolower((string) ($raw['mode'] ?? ''));
            $values = $this->decodeTuyaProperty($raw['property'] ?? null);
            $type = strtolower((string) ($values['type'] ?? $raw['type'] ?? ''));

            if ($dpId === '' || $code === '' || ! str_contains($mode, 'w')) {
                continue;
            }
            if ($type !== 'bool' || ! $this->isPrimarySwitchCode($code)) {
                continue;
            }

            $definitions[] = [
                'dp_id' => $dpId,
                'code' => $code,
                'function_type' => 'switch',
                'sort_order' => (int) ($raw['id'] ?? $dpId),
            ];
        }

        usort($definitions, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return $definitions;
    }

    private function schemaEntries(array $metadata): array
    {
        $schemaMap = $metadata['schema_map'] ?? null;
        if (is_array($schemaMap) && $schemaMap !== []) {
            return $schemaMap;
        }

        $schema = $metadata['schema'] ?? null;
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($schema)) {
            return [];
        }

        if (array_is_list($schema)) {
            return collect($schema)
                ->filter(fn ($raw) => is_array($raw) && isset($raw['id']))
                ->mapWithKeys(fn (array $raw) => [(string) $raw['id'] => $raw])
                ->all();
        }

        return $schema;
    }

    private function decodeTuyaProperty(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isPrimarySwitchCode(string $code): bool
    {
        $clean = strtolower($code);
        if (in_array($clean, ['switch_backlight', 'switch_inching'], true)) {
            return false;
        }

        return $clean === 'switch'
            || $clean === 'switch_led'
            || preg_match('/^switch_\d+$/', $clean) === 1;
    }
}
