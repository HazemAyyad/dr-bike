<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStorePopupCampaign;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\PopupCampaignService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PopupCampaignController extends Controller
{
    private const PERMISSION = 'Online Store Content Manage';

    public function index(Request $request)
    {
        $this->authorizeRequest($request);

        return response()->json(['data' => OnlineStorePopupCampaign::query()
            ->withCount([
                'events as impressions_count' => fn ($query) => $query->where('event_type', 'impression'),
                'events as clicks_count' => fn ($query) => $query->where('event_type', 'click'),
                'events as dismisses_count' => fn ($query) => $query->where('event_type', 'dismiss'),
            ])
            ->orderByDesc('priority')->orderByDesc('id')->get()]);
    }

    public function show(Request $request, OnlineStorePopupCampaign $popupCampaign)
    {
        $this->authorizeRequest($request, true);

        return response()->json(['data' => $popupCampaign]);
    }

    public function store(Request $request, PopupCampaignService $service)
    {
        $this->authorizeRequest($request);

        return response()->json(['data' => $service->save(null, $this->validated($request), $request->user())], 201);
    }

    public function update(Request $request, OnlineStorePopupCampaign $popupCampaign, PopupCampaignService $service)
    {
        $this->authorizeRequest($request, true);

        return response()->json(['data' => $service->save($popupCampaign, $this->validated($request, true), $request->user())]);
    }

    public function activate(Request $request, OnlineStorePopupCampaign $popupCampaign, PopupCampaignService $service)
    {
        $this->authorizeRequest($request, true);

        return response()->json(['data' => $service->setActive($popupCampaign, true, $request->user())]);
    }

    public function deactivate(Request $request, OnlineStorePopupCampaign $popupCampaign, PopupCampaignService $service)
    {
        $this->authorizeRequest($request, true);

        return response()->json(['data' => $service->setActive($popupCampaign, false, $request->user())]);
    }

    public function destroy(Request $request, OnlineStorePopupCampaign $popupCampaign, PopupCampaignService $service)
    {
        $this->authorizeRequest($request, true);
        $service->delete($popupCampaign, $request->user());

        return response()->noContent();
    }

    private function validated(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'title_translations' => [$required, 'array'],
            'title_translations.*' => ['nullable', 'string', 'max:255'],
            'content_translations' => ['sometimes', 'nullable', 'array'],
            'content_translations.*' => ['nullable', 'string', 'max:1000'],
            'button_translations' => ['sometimes', 'nullable', 'array'],
            'button_translations.*' => ['nullable', 'string', 'max:80'],
            'theme' => [$required, Rule::in(OnlineStorePopupCampaign::THEMES)],
            'audience_type' => [$required, Rule::in(OnlineStorePopupCampaign::AUDIENCES)],
            'audience_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'display_frequency' => [$required, Rule::in(OnlineStorePopupCampaign::FREQUENCIES)],
            'action_type' => [$required, Rule::in(OnlineStorePopupCampaign::ACTION_TYPES)],
            'action_target_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'action_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
    }

    private function authorizeRequest(Request $request, bool $resource = false): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        ($resource
            ? app(OnlineStorePolicy::class)->authorizeResource($user, self::PERMISSION, true)
            : app(OnlineStorePolicy::class)->authorize($user, self::PERMISSION))->authorize();
    }
}
