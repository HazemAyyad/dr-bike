<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListingLifecycleService
{
    public function __construct(private readonly ListingReadinessService $readiness) {}

    public function refresh(OnlineStoreListing $listing, ?User $actor = null): OnlineStoreListing
    {
        return DB::transaction(function () use ($listing, $actor) {
            $locked = OnlineStoreListing::query()->lockForUpdate()->findOrFail($listing->getKey());
            $before = $this->snapshot($locked);
            $result = $this->readiness->evaluate($locked->load('product'));
            $locked->readiness_state = $result['state'];
            $locked->readiness_issues = $result['issues'];
            if ($result['state'] === 'incomplete' && $locked->status !== 'draft') {
                $locked->status = 'draft';
            }
            $locked->save();
            if ($before['status'] !== $locked->status) {
                $this->audit($locked, $actor, 'status_changed', $before);
            }

            return $locked->fresh('product');
        });
    }

    public function transition(OnlineStoreListing $listing, string $target, User $actor): OnlineStoreListing
    {
        return DB::transaction(function () use ($listing, $target, $actor) {
            $locked = OnlineStoreListing::query()->lockForUpdate()->findOrFail($listing->getKey());
            $result = $this->readiness->evaluate($locked->load('product'));
            $locked->readiness_state = $result['state'];
            $locked->readiness_issues = $result['issues'];
            $allowed = [
                'draft' => ['draft', 'ready'], 'ready' => ['draft', 'ready', 'published'],
                'published' => ['draft', 'published', 'hidden'], 'hidden' => ['draft', 'hidden'],
            ];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['The requested listing transition is not allowed.']]);
            }
            if (in_array($target, ['ready', 'published'], true) && $result['state'] !== 'complete') {
                throw ValidationException::withMessages(['status' => ['The listing is not ready for this transition.'], 'readiness_issues' => $result['issues']]);
            }
            $before = $this->snapshot($locked);
            $locked->status = $target;
            if ($target === 'published' && $before['status'] !== 'published') {
                $locked->published_at = now();
                $locked->hidden_at = null;
            }
            if ($target === 'hidden' && $before['status'] !== 'hidden') {
                $locked->hidden_at = now();
            }
            $locked->updated_by = $actor->getKey();
            $locked->save();
            if ($before['status'] !== $target) {
                $action = $target === 'published' ? 'published' : ($target === 'hidden' ? 'hidden' : 'status_changed');
                $this->audit($locked, $actor, $action, $before);
            }

            return $locked->fresh('product');
        });
    }

    private function snapshot(OnlineStoreListing $listing): array
    {
        return ['status' => $listing->status, 'readiness_state' => $listing->readiness_state,
            'readiness_issues' => $listing->readiness_issues, 'published_at' => $listing->published_at?->toISOString(),
            'hidden_at' => $listing->hidden_at?->toISOString()];
    }

    private function audit(OnlineStoreListing $listing, ?User $actor, string $action, array $before): void
    {
        DB::table('online_store_audit_events')->insert([
            'actor_user_id' => $actor?->getKey(), 'action' => $action, 'entity_type' => 'listing',
            'entity_id' => $listing->getKey(), 'before_values' => json_encode($before),
            'after_values' => json_encode($this->snapshot($listing)), 'request_id' => request()->header('X-Request-ID'),
            'ip_address' => request()->ip(), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
