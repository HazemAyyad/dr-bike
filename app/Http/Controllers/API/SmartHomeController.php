<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SmartDeviceActivityLogResource;
use App\Http\Resources\SmartDeviceResource;
use App\Http\Resources\SmartHomeResource;
use App\Http\Resources\SmartRoomResource;
use App\Models\SmartDevice;
use App\Models\SmartDeviceActivityLog;
use App\Models\SmartHome;
use App\Models\SmartHomeTuyaUser;
use App\Models\SmartRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmartHomeController extends Controller
{
    public function homes(Request $request)
    {
        $homes = SmartHome::query()
            ->where('user_id', $request->user()->id)
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

    public function storeHome(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tuya_home_id' => ['nullable', 'string', 'max:128'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geo_name' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([SmartHome::STATUS_ACTIVE, SmartHome::STATUS_ARCHIVED])],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        $home = DB::transaction(function () use ($request, $data) {
            $userId = (int) $request->user()->id;
            $isDefault = (bool) ($data['is_default'] ?? ! SmartHome::where('user_id', $userId)->exists());

            if ($isDefault) {
                SmartHome::where('user_id', $userId)->update(['is_default' => false]);
            }

            return SmartHome::create([
                ...$data,
                'user_id' => $userId,
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
            'home' => new SmartHomeResource($this->homeQuery($request, $id)->firstOrFail()),
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
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([SmartHome::STATUS_ACTIVE, SmartHome::STATUS_ARCHIVED])],
            'raw_metadata' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($request, $home, $data) {
            if (($data['is_default'] ?? false) === true) {
                SmartHome::where('user_id', $request->user()->id)->whereKeyNot($home->id)->update(['is_default' => false]);
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
        $this->ownedHome($request, $homeId);

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
            ->with('room:id,name,tuya_room_id')
            ->whereHas('home', fn (Builder $query) => $query->where('user_id', $request->user()->id));

        if ($request->filled('home_id')) {
            $this->ownedHome($request, (int) $request->input('home_id'));
            $query->where('smart_home_id', (int) $request->input('home_id'));
        }

        if ($request->filled('room_id')) {
            $room = $this->ownedRoom($request, (int) $request->input('room_id'));
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

        return response()->json([
            'status' => 'success',
            'devices' => $query->orderBy('name')->paginate($perPage)->through(fn (SmartDevice $device) => (new SmartDeviceResource($device))->resolve($request)),
        ]);
    }

    public function showDevice(Request $request, int $id)
    {
        return response()->json([
            'status' => 'success',
            'device' => new SmartDeviceResource($this->ownedDevice($request, $id)->load('room:id,name,tuya_room_id')),
        ]);
    }

    public function registerDevice(Request $request)
    {
        $data = $this->validateDevicePayload($request, true);
        $home = $this->ownedHome($request, (int) $data['smart_home_id']);
        $roomId = $this->validRoomId($request, $data['smart_room_id'] ?? null, $home->id);

        $device = DB::transaction(function () use ($data, $home, $roomId) {
            $device = SmartDevice::withTrashed()->where('tuya_device_id', $data['tuya_device_id'])->first();
            $payload = $this->devicePayload($data, $home->id, $roomId);

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
            'device' => new SmartDeviceResource($device->fresh()->load('room:id,name,tuya_room_id')),
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
                $payload = $this->devicePayload($item, $home->id, $roomId);

                if ($device) {
                    $device->restore();
                    $device->update($payload);
                } else {
                    $device = SmartDevice::create($payload + [
                        'paired_at' => $item['paired_at'] ?? now(),
                    ]);
                }
                $synced->push($device->fresh()->load('room:id,name,tuya_room_id'));
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
        $device = $this->ownedDevice($request, $id);
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
            'device' => new SmartDeviceResource($device->fresh()->load('room:id,name,tuya_room_id')),
        ]);
    }

    public function destroyDevice(Request $request, int $id)
    {
        $device = $this->ownedDevice($request, $id);
        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الجهاز',
        ]);
    }

    public function updateDeviceStatus(Request $request, int $id)
    {
        $device = $this->ownedDevice($request, $id);
        $data = $request->validate([
            'online' => ['sometimes', 'boolean'],
            'last_status' => ['nullable', 'array'],
            'last_seen_at' => ['nullable', 'date'],
        ]);

        $device->update([
            ...$data,
            'last_seen_at' => $data['last_seen_at'] ?? now(),
        ]);

        return response()->json([
            'status' => 'success',
            'device' => new SmartDeviceResource($device->fresh()->load('room:id,name,tuya_room_id')),
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

    public function deviceActivity(Request $request, int $id)
    {
        $device = $this->ownedDevice($request, $id);
        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        return response()->json([
            'status' => 'success',
            'activity' => SmartDeviceActivityLogResource::collection(
                $device->activityLogs()->with('user:id,name')->latest()->paginate($perPage)
            ),
        ]);
    }

    public function tuyaUser(Request $request)
    {
        $mapping = SmartHomeTuyaUser::firstOrCreate(['user_id' => $request->user()->id], [
            'region' => config('services.tuya.region'),
        ]);

        return response()->json([
            'status' => 'success',
            'tuya_user' => [
                'user_id' => (int) $mapping->user_id,
                'tuya_uid' => $mapping->tuya_uid,
                'region' => $mapping->region,
                'last_login_at' => $mapping->last_login_at?->toISOString(),
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
            ['user_id' => $request->user()->id],
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
            ],
        ]);
    }

    private function homeQuery(Request $request, int $id): Builder
    {
        return SmartHome::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->withCount(['rooms', 'devices'])
            ->withCount(['devices as online_devices_count' => fn (Builder $query) => $query->where('online', true)]);
    }

    private function ownedHome(Request $request, int $id): SmartHome
    {
        return SmartHome::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function ownedRoom(Request $request, int $id): SmartRoom
    {
        return SmartRoom::query()
            ->whereKey($id)
            ->whereHas('home', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->firstOrFail();
    }

    private function ownedDevice(Request $request, int $id): SmartDevice
    {
        return SmartDevice::query()
            ->whereKey($id)
            ->whereHas('home', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->firstOrFail();
    }

    private function validRoomId(Request $request, mixed $roomId, int $homeId): ?int
    {
        if ($roomId === null || $roomId === '') {
            return null;
        }

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

    private function devicePayload(array $data, int $homeId, ?int $roomId): array
    {
        return [
            'smart_home_id' => $homeId,
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
}
