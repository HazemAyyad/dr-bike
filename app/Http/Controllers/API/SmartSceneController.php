<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SmartSceneResource;
use App\Models\SmartDevice;
use App\Models\SmartHome;
use App\Models\SmartRoom;
use App\Models\SmartScene;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmartSceneController extends Controller
{
    public function index(Request $request)
    {
        $query = SmartScene::query()
            ->where('user_id', $this->ownerId($request))
            ->orderByDesc('enabled')
            ->orderBy('name');

        if ($request->filled('home_id')) {
            $home = $this->home($request, (int) $request->input('home_id'));
            $query->where('smart_home_id', $home->id);
        }
        if ($request->filled('room_id')) {
            $query->where('smart_room_id', (int) $request->input('room_id'));
        }
        if ($request->boolean('home_only')) {
            $query->where('show_on_home', true);
        }

        return response()->json([
            'status' => 'success',
            'scenes' => SmartSceneResource::collection($query->get()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $scene = DB::transaction(function () use ($request, $data) {
            $this->validateRelations($request, $data);
            return SmartScene::create([
                ...$data,
                'user_id' => $this->ownerId($request),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تمت إضافة المشهد',
            'scene' => new SmartSceneResource($scene),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        return response()->json([
            'status' => 'success',
            'scene' => new SmartSceneResource($this->scene($request, $id)),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $scene = $this->scene($request, $id);
        $data = $this->validated($request, true);
        $merged = array_merge($scene->only(['smart_home_id', 'smart_room_id', 'conditions', 'actions']), $data);
        $this->validateRelations($request, $merged);
        $scene->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث المشهد',
            'scene' => new SmartSceneResource($scene->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->scene($request, $id)->delete();

        return response()->json(['status' => 'success', 'message' => 'تم حذف المشهد']);
    }

    public function recordExecution(Request $request, int $id)
    {
        $scene = $this->scene($request, $id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['success', 'failed', 'partial'])],
            'source' => ['sometimes', Rule::in(['app', 'tuya', 'automation'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'details' => ['nullable', 'array'],
        ]);

        $execution = DB::transaction(function () use ($request, $scene, $data) {
            $executedAt = now();
            $scene->update([
                'last_executed_at' => $executedAt,
                'last_execution_status' => $data['status'],
            ]);
            return $scene->executions()->create([
                ...$data,
                'source' => $data['source'] ?? 'app',
                'user_id' => $request->user()->id,
                'executed_at' => $executedAt,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'execution_id' => (int) $execution->id,
            'scene' => new SmartSceneResource($scene->fresh()),
        ], 201);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'smart_home_id' => [$sometimes, 'integer'],
            'smart_room_id' => ['nullable', 'integer'],
            'tuya_scene_id' => ['nullable', 'string', 'max:191'],
            'name' => [$sometimes, 'string', 'max:191'],
            'icon' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', 'string', 'max:24'],
            'trigger_type' => [$sometimes, Rule::in(['manual', 'schedule', 'device'])],
            'match_type' => ['sometimes', Rule::in(['any', 'all'])],
            'conditions' => [$partial ? 'sometimes' : 'present', 'array'],
            'conditions.*.type' => ['required', Rule::in(['schedule', 'device'])],
            'conditions.*.device_id' => ['nullable', 'integer'],
            'conditions.*.dp_id' => ['nullable', 'string', 'max:120'],
            'conditions.*.value' => ['nullable'],
            'conditions.*.time' => ['nullable', 'date_format:H:i'],
            'conditions.*.date' => ['nullable', 'date_format:Y-m-d'],
            'conditions.*.repeat_days' => ['nullable', 'array'],
            'conditions.*.repeat_days.*' => [Rule::in(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])],
            'conditions.*.timezone' => ['nullable', 'string', 'max:80'],
            'actions' => [$sometimes, 'array', 'min:1'],
            'actions.*.device_id' => ['required', 'integer'],
            'actions.*.dp_id' => ['required', 'string', 'max:120'],
            'actions.*.value' => ['required'],
            'actions.*.device_name' => ['nullable', 'string', 'max:191'],
            'actions.*.function_name' => ['nullable', 'string', 'max:191'],
            'enabled' => ['sometimes', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
            'show_in_room' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateRelations(Request $request, array $data): void
    {
        $home = $this->home($request, (int) $data['smart_home_id']);
        if (! empty($data['smart_room_id'])) {
            abort_unless(SmartRoom::whereKey($data['smart_room_id'])->where('smart_home_id', $home->id)->exists(), 422, 'الغرفة لا تتبع هذا المنزل');
        }

        $deviceIds = collect($data['actions'] ?? [])->pluck('device_id')
            ->merge(collect($data['conditions'] ?? [])->pluck('device_id'))
            ->filter()->unique()->values();
        $count = SmartDevice::query()
            ->whereIn('id', $deviceIds)
            ->where('smart_home_id', $home->id)
            ->where(fn (Builder $query) => $query
                ->where('user_id', $this->ownerId($request))
                ->orWhereHas('home', fn (Builder $query) => $query->where('user_id', $this->ownerId($request))))
            ->count();
        abort_unless($count === $deviceIds->count(), 422, 'أحد أجهزة المشهد غير متاح لهذا المستخدم');
    }

    private function scene(Request $request, int $id): SmartScene
    {
        return SmartScene::where('user_id', $this->ownerId($request))->findOrFail($id);
    }

    private function home(Request $request, int $id): SmartHome
    {
        return SmartHome::where('user_id', $this->ownerId($request))->findOrFail($id);
    }

    private function ownerId(Request $request): int
    {
        if ($request->user()?->type === 'admin' && $request->filled('user_id')) {
            return (int) $request->input('user_id');
        }
        return (int) $request->user()->id;
    }
}
