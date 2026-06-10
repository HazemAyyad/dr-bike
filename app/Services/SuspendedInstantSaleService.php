<?php

namespace App\Services;

use App\Http\Controllers\API\InstantSales;
use App\Models\SuspendedInstantSale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuspendedInstantSaleService
{
    public function __construct(
        protected SalesDailySessionService $sessionService,
        protected AdminNotificationService $adminNotificationService
    ) {}

    public function isAdmin(User $user): bool
    {
        return $user->type === 'admin';
    }

    public function canView(User $user, SuspendedInstantSale $suspended): bool
    {
        return $this->isAdmin($user)
            || (int) $suspended->created_by_user_id === (int) $user->id;
    }

    public function canMutate(User $user, SuspendedInstantSale $suspended): bool
    {
        return $this->canView($user, $suspended);
    }

    /**
     * @return array<string, mixed>
     */
    public function validatePayload(array $payload): array
    {
        $hasPackage = ! empty($payload['offer_package_id']);
        $hasProduct = ! empty($payload['product_id']);

        if (! $hasPackage && ! $hasProduct) {
            throw ValidationException::withMessages([
                'payload' => [__('messages.suspended_instant_sale_empty')],
            ]);
        }

        $rules = [
            'offer_package_id' => 'nullable|integer|exists:offer_packages,id',
            'product_id' => 'required_without:offer_package_id|nullable|exists:products,id',
            'quantity' => 'nullable|numeric|min:1',
            'cost' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'additional_notes' => 'nullable|array',
            'type' => 'nullable|string|in:normal,project',
            'project_id' => 'nullable',
            'other_products' => 'nullable|array',
            'other_products.*.product_id' => 'required_with:other_products|exists:products,id',
            'other_products.*.cost' => 'required_with:other_products|numeric|min:0',
            'other_products.*.quantity' => 'required_with:other_products|numeric|min:1',
            'other_products.*.type' => 'nullable|string|in:normal,project',
            'other_products.*.project_id' => 'nullable',
            'buyer_type' => 'nullable|string|in:trader,customer,unknown,seller',
            'buyer_id' => 'nullable|integer',
            'buyer_name' => 'nullable|string|max:255',
            'buyer_phone' => 'nullable|string|max:50',
            'buyer_address' => 'nullable|string|max:500',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
            'payment_box_name' => 'nullable|string|max:255',
            'payment_box_value' => 'nullable|numeric|min:0',
            'seller_id' => 'nullable|integer|exists:sellers,id',
        ];

        return validator($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function buildSummaryLabel(array $payload): string
    {
        $parts = [];

        if (! empty($payload['offer_package_id'])) {
            $parts[] = 'باكيج';
        }

        $lineCount = 0;
        if (! empty($payload['product_id'])) {
            $lineCount++;
        }
        $lineCount += count($payload['other_products'] ?? []);

        if ($lineCount > 0) {
            $parts[] = $lineCount.' منتج';
        }

        $buyerName = trim((string) ($payload['buyer_name'] ?? ''));
        if ($buyerName !== '' && $buyerName !== '-') {
            $parts[] = $buyerName;
        }

        return $parts !== [] ? implode(' — ', $parts) : __('messages.suspended_instant_sale_default_label');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveTotalCost(array $payload): float
    {
        if (isset($payload['total_cost']) && is_numeric($payload['total_cost'])) {
            return max(0, round((float) $payload['total_cost'], 2));
        }

        $total = 0.0;
        if (! empty($payload['offer_package_id'])) {
            $qty = max(1, (float) ($payload['quantity'] ?? 1));
            $total += (float) ($payload['cost'] ?? 0) * $qty;
        } elseif (! empty($payload['product_id'])) {
            $total += (float) ($payload['cost'] ?? 0) * (float) ($payload['quantity'] ?? 1);
        }

        foreach ($payload['other_products'] ?? [] as $item) {
            $total += (float) ($item['cost'] ?? 0) * (float) ($item['quantity'] ?? 0);
        }

        $discount = (float) ($payload['discount'] ?? 0);
        $notesTotal = 0.0;
        foreach ($payload['additional_notes'] ?? [] as $note) {
            if (is_array($note)) {
                $notesTotal += (float) ($note['amount'] ?? 0);
            }
        }

        return max(0, round($total - $discount + $notesTotal, 2));
    }

    public function store(User $user, Request $request): SuspendedInstantSale
    {
        $session = $this->sessionService->assertCanCreateSale($user);
        $data = $request->validate([
            'current_step' => 'required|string|in:product_picker,checkout',
            'payload' => 'required|array',
            'suspended_instant_sale_id' => 'nullable|integer|exists:suspended_instant_sales,id',
        ]);

        $payload = $this->validatePayload($data['payload']);
        $owner = $this->sessionService->resolveOwner($user);

        if (! empty($data['suspended_instant_sale_id'])) {
            $existing = SuspendedInstantSale::query()->findOrFail($data['suspended_instant_sale_id']);
            if (! $this->canMutate($user, $existing)) {
                throw ValidationException::withMessages([
                    'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_forbidden')],
                ]);
            }
            if (! $existing->isSuspended()) {
                throw ValidationException::withMessages([
                    'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_not_active')],
                ]);
            }

            $existing->update([
                'current_step' => $data['current_step'],
                'payload' => $payload,
                'summary_label' => $this->buildSummaryLabel($payload),
                'total_cost' => $this->resolveTotalCost($payload),
                'suspended_at' => now(),
            ]);

            return $existing->fresh(['createdByUser:id,name', 'employee:id,name']);
        }

        $record = SuspendedInstantSale::create([
            'sales_daily_session_id' => $session->id,
            'created_by_user_id' => $user->id,
            'employee_id' => $owner['employee_id'],
            'current_step' => $data['current_step'],
            'payload' => $payload,
            'summary_label' => $this->buildSummaryLabel($payload),
            'total_cost' => $this->resolveTotalCost($payload),
            'status' => SuspendedInstantSale::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);

        $record->update([
            'reference_code' => 'ع-'.$record->id,
        ]);

        $record = $record->fresh(['createdByUser:id,name', 'employee.user']);

        $this->notifyAdminSuspendedCreated($record);

        return $record;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(User $user, array $filters = []): array
    {
        $query = SuspendedInstantSale::query()
            ->with(['createdByUser:id,name', 'employee.user:id,name'])
            ->where('status', SuspendedInstantSale::STATUS_SUSPENDED)
            ->orderByDesc('suspended_at')
            ->orderByDesc('id');

        if (! $this->isAdmin($user)) {
            $query->where('created_by_user_id', $user->id);
        } elseif (! empty($filters['created_by_user_id'])) {
            $query->where('created_by_user_id', (int) $filters['created_by_user_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('summary_label', 'like', $term)
                    ->orWhere('reference_code', 'like', $term);
            });
        }

        return $query->get()
            ->map(fn (SuspendedInstantSale $item) => $this->formatListItem($item))
            ->values()
            ->all();
    }

    public function show(User $user, int $id): SuspendedInstantSale
    {
        $record = SuspendedInstantSale::query()
            ->with(['createdByUser:id,name', 'employee:id,name'])
            ->findOrFail($id);

        if (! $this->canView($user, $record)) {
            throw ValidationException::withMessages([
                'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_forbidden')],
            ]);
        }

        return $record;
    }

    public function cancel(User $user, int $id): SuspendedInstantSale
    {
        $record = $this->show($user, $id);

        if (! $this->canMutate($user, $record)) {
            throw ValidationException::withMessages([
                'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_forbidden')],
            ]);
        }

        if (! $record->isSuspended()) {
            throw ValidationException::withMessages([
                'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_not_active')],
            ]);
        }

        $record->update([
            'status' => SuspendedInstantSale::STATUS_CANCELLED,
            'cancelled_by_user_id' => $user->id,
            'cancelled_at' => now(),
        ]);

        return $record->fresh(['createdByUser:id,name', 'employee:id,name']);
    }

    /**
     * @return array{response: \Illuminate\Http\JsonResponse, instant_sale_id: int|null}
     */
    public function complete(User $user, Request $request): array
    {
        $data = $request->validate([
            'suspended_instant_sale_id' => 'required|integer|exists:suspended_instant_sales,id',
            'payload' => 'nullable|array',
        ]);

        $record = $this->show($user, $data['suspended_instant_sale_id']);

        if (! $record->isSuspended()) {
            throw ValidationException::withMessages([
                'suspended_instant_sale_id' => [__('messages.suspended_instant_sale_not_active')],
            ]);
        }

        $payload = $this->validatePayload(
            array_merge($record->payload ?? [], $data['payload'] ?? [])
        );

        $this->sessionService->assertCanCreateSale($user);

        return DB::transaction(function () use ($user, $record, $payload) {
            $record->update([
                'payload' => $payload,
                'summary_label' => $this->buildSummaryLabel($payload),
                'total_cost' => $this->resolveTotalCost($payload),
            ]);

            $storeRequest = Request::create('/api/create/instant/sale', 'POST', $this->normalizePayloadForStore($payload));
            $storeRequest->setUserResolver(fn () => $user);

            $response = app(InstantSales::class)->store($storeRequest);
            $body = json_decode($response->getContent(), true) ?? [];

            if (($body['status'] ?? '') !== 'success') {
                return [
                    'response' => response()->json($body, 200),
                    'instant_sale_id' => null,
                ];
            }

            $instantSaleId = isset($body['instant_sale_id']) ? (int) $body['instant_sale_id'] : null;

            $record->update([
                'status' => SuspendedInstantSale::STATUS_COMPLETED,
                'completed_instant_sale_id' => $instantSaleId,
                'completed_by_user_id' => $user->id,
                'completed_at' => now(),
            ]);

            $record = $record->fresh(['createdByUser:id,name', 'employee.user']);
            $this->notifyAdminSuspendedCompleted($record, $user);

            return [
                'response' => response()->json([
                    'status' => 'success',
                    'message' => __('messages.suspended_instant_sale_completed'),
                    'instant_sale_id' => $instantSaleId,
                    'suspended_instant_sale_id' => $record->id,
                ], 200),
                'instant_sale_id' => $instantSaleId,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayloadForStore(array $payload): array
    {
        if (isset($payload['project_id']) && ($payload['project_id'] === '' || $payload['project_id'] === '0')) {
            $payload['project_id'] = null;
        }

        if (empty($payload['quantity'])) {
            $payload['quantity'] = 1;
        }
        if (! isset($payload['cost'])) {
            $payload['cost'] = 0;
        }
        if (! isset($payload['discount'])) {
            $payload['discount'] = 0;
        }
        if (! isset($payload['type'])) {
            $payload['type'] = 'normal';
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatListItem(SuspendedInstantSale $item): array
    {
        return [
            'id' => $item->id,
            'reference_code' => $item->reference_code ?? ('ع-'.$item->id),
            'current_step' => $item->current_step,
            'summary_label' => $item->summary_label,
            'total_cost' => $item->total_cost,
            'status' => $item->status,
            'suspended_at' => optional($item->suspended_at)->format('Y-m-d H:i:s'),
            'created_by_user_id' => $item->created_by_user_id,
            'created_by_name' => $item->createdByUser?->name,
            'employee_id' => $item->employee_id,
            'employee_name' => $item->employee?->user?->name ?? $item->createdByUser?->name,
            'payload' => $item->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDetail(SuspendedInstantSale $item): array
    {
        return array_merge($this->formatListItem($item), [
            'sales_daily_session_id' => $item->sales_daily_session_id,
            'completed_instant_sale_id' => $item->completed_instant_sale_id,
            'completed_at' => optional($item->completed_at)->format('Y-m-d H:i:s'),
            'cancelled_at' => optional($item->cancelled_at)->format('Y-m-d H:i:s'),
        ]);
    }

    public function suspendedCountForUser(User $user): int
    {
        $query = SuspendedInstantSale::query()
            ->where('status', SuspendedInstantSale::STATUS_SUSPENDED);

        if (! $this->isAdmin($user)) {
            $query->where('created_by_user_id', $user->id);
        }

        return $query->count();
    }

    private function notifyAdminSuspendedCreated(SuspendedInstantSale $record): void
    {
        $record->loadMissing(['createdByUser', 'employee.user']);
        $employeeName = $record->createdByUser?->name ?? __('messages.employee_default_name');
        $reference = $record->reference_code ?? ('ع-'.$record->id);

        $this->adminNotificationService->create(
            AdminNotificationService::TYPE_SUSPENDED_INSTANT_SALE_CREATED,
            __('messages.admin_notify_suspended_sale_created_title'),
            __('messages.admin_notify_suspended_sale_created_body', [
                'employee' => $employeeName,
                'reference' => $reference,
                'total' => number_format((float) $record->total_cost, 2),
            ]),
            [
                'suspended_instant_sale_id' => (string) $record->id,
                'reference_code' => $reference,
                'employee_name' => $employeeName,
                'total_cost' => (string) $record->total_cost,
            ],
            $record->employee_id,
            'suspended_instant_sale',
            $record->id
        );
    }

    private function notifyAdminSuspendedCompleted(
        SuspendedInstantSale $record,
        User $completedBy
    ): void {
        $record->loadMissing(['createdByUser', 'employee.user']);
        $ownerName = $record->createdByUser?->name ?? __('messages.employee_default_name');
        $actorName = $completedBy->name ?? __('messages.employee_default_name');
        $reference = $record->reference_code ?? ('ع-'.$record->id);

        $bodyKey = (int) $completedBy->id === (int) $record->created_by_user_id
            ? 'admin_notify_suspended_sale_completed_body'
            : 'admin_notify_suspended_sale_completed_body_admin';

        $this->adminNotificationService->create(
            AdminNotificationService::TYPE_SUSPENDED_INSTANT_SALE_COMPLETED,
            __('messages.admin_notify_suspended_sale_completed_title'),
            __($bodyKey, [
                'employee' => $ownerName,
                'actor' => $actorName,
                'reference' => $reference,
                'total' => number_format((float) $record->total_cost, 2),
            ]),
            [
                'suspended_instant_sale_id' => (string) $record->id,
                'reference_code' => $reference,
                'employee_name' => $ownerName,
                'completed_by_name' => $actorName,
                'instant_sale_id' => (string) ($record->completed_instant_sale_id ?? ''),
            ],
            $record->employee_id,
            'suspended_instant_sale',
            $record->id
        );
    }
}
