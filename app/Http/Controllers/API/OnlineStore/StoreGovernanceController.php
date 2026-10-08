<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreAuditEvent;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\OnlineStoreDashboardService;
use App\Services\OnlineStore\OnlineStoreReportService;
use App\Services\OnlineStore\OnlineStoreSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreGovernanceController extends Controller
{
    private const VIEW_PERMISSION = 'Online Store View';

    private const SETTINGS_PERMISSION = 'Online Store Settings Manage';

    public function settings(Request $request, OnlineStoreSettingsService $settings)
    {
        $this->authorizeGovernance($request, self::VIEW_PERMISSION);
        $current = $settings->current();

        return ['data' => $current, 'meta' => ['operating_state' => $settings->operatingState($current, true)]];
    }

    public function updateSettings(Request $request, OnlineStoreSettingsService $settings)
    {
        $this->authorizeGovernance($request, self::SETTINGS_PERMISSION);
        $data = $request->validate([
            'store_enabled' => 'sometimes|boolean',
            'maintenance_mode' => 'sometimes|boolean',
            'checkout_enabled' => 'sometimes|boolean',
            'cod_enabled' => 'sometimes|boolean',
            'guest_browsing_enabled' => 'sometimes|boolean',
            'minimum_order' => 'sometimes|numeric|min:0',
            'support_phone' => 'nullable|string|max:100',
            'whatsapp' => 'nullable|string|max:100',
            'enabled_languages' => 'sometimes|array|min:1',
            'enabled_languages.*' => ['string', Rule::in(OnlineStoreSettingsService::LANGUAGES)],
            'cancellation_policy_translations' => 'nullable|array',
            'return_policy_translations' => 'nullable|array',
            'warranty_policy_translations' => 'nullable|array',
            'terms_translations' => 'nullable|array',
            'out_of_stock_behavior' => ['sometimes', Rule::in(['visible_non_purchasable'])],
            'low_stock_threshold' => 'sometimes|integer|min:0',
        ]);

        return ['data' => $settings->save($request->user(), $data)];
    }

    public function auditEvents(Request $request)
    {
        $this->authorizeGovernance($request, self::SETTINGS_PERMISSION);
        $filters = $request->validate([
            'entity_type' => ['nullable', Rule::in(OnlineStoreAuditEvent::ENTITY_TYPES)],
            'action' => ['nullable', Rule::in(OnlineStoreAuditEvent::ACTIONS)],
            'actor_user_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        $query = OnlineStoreAuditEvent::query()->with('actor:id,name')
            ->when($filters['entity_type'] ?? null, fn ($q, $value) => $q->where('entity_type', $value))
            ->when($filters['action'] ?? null, fn ($q, $value) => $q->where('action', $value))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $value) => $q->where('actor_user_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->where('occurred_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->where('occurred_at', '<=', $value))
            ->orderByDesc('occurred_at')->orderByDesc('id');

        return $query->paginate();
    }

    public function dashboard(Request $request, OnlineStoreDashboardService $dashboard)
    {
        $this->authorizeGovernance($request, self::VIEW_PERMISSION);
        $filters = $request->validate(['days' => 'nullable|integer|min:7|max:365']);

        return ['data' => $dashboard->summary((int) ($filters['days'] ?? 30))];
    }

    public function report(Request $request, OnlineStoreReportService $reports)
    {
        $this->authorizeGovernance($request, self::VIEW_PERMISSION);
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'timezone' => 'nullable|timezone',
            'origin' => ['nullable', Rule::in(['admin', 'store'])],
            'status' => 'nullable|string|max:40',
            'account_type' => ['nullable', Rule::in(['customer', 'seller'])],
            'price_context' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'customer_id' => 'nullable|integer|min:1',
            'seller_id' => 'nullable|integer|min:1',
            'listing_id' => 'nullable|integer|min:1',
            'category_id' => 'nullable|integer|min:1',
        ]);

        return ['data' => $reports->report($filters)];
    }

    private function authorizeGovernance(Request $request, string $permission): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($user, $permission)->authorize();
    }
}
