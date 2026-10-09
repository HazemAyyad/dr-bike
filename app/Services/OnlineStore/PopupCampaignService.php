<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreCoupon;
use App\Models\OnlineStore\OnlineStorePopupCampaign;
use App\Models\OnlineStore\OnlineStorePopupEvent;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\SalesOrder;
use App\Models\Store\StoreUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PopupCampaignService
{
    public function __construct(private readonly OnlineStoreAuditService $audit) {}

    public function save(?OnlineStorePopupCampaign $campaign, array $data, User $actor): OnlineStorePopupCampaign
    {
        $before = $campaign?->toArray();
        $merged = array_merge($campaign?->only([
            'audience_type', 'audience_days', 'action_type', 'action_target_id', 'action_url',
            'starts_at', 'ends_at',
        ]) ?? [], $data);
        $this->validateContract($merged);

        $campaign ??= new OnlineStorePopupCampaign;
        $campaign->fill($data);
        if (! $campaign->exists) {
            $campaign->created_by = $actor->getKey();
        }
        $campaign->updated_by = $actor->getKey();
        $campaign->save();
        $campaign->refresh();

        $this->audit->record(
            $actor,
            $before === null ? 'created' : 'updated',
            'popup_campaign',
            (int) $campaign->getKey(),
            $before,
            $campaign->toArray(),
        );

        return $campaign;
    }

    public function setActive(OnlineStorePopupCampaign $campaign, bool $active, User $actor): OnlineStorePopupCampaign
    {
        $before = $campaign->toArray();
        $campaign->is_active = $active;
        $campaign->updated_by = $actor->getKey();
        $campaign->save();
        $this->audit->record($actor, $active ? 'activated' : 'deactivated', 'popup_campaign', (int) $campaign->getKey(), $before, $campaign->toArray());

        return $campaign->fresh();
    }

    public function delete(OnlineStorePopupCampaign $campaign, User $actor): void
    {
        $before = $campaign->toArray();
        $id = (int) $campaign->getKey();
        $campaign->delete();
        $this->audit->record($actor, 'deleted', 'popup_campaign', $id, $before, null);
    }

    public function eligible(Request $request, ?StoreUser $user): ?OnlineStorePopupCampaign
    {
        $now = CarbonImmutable::now('UTC');
        $identity = $this->identity($request, $user);

        return OnlineStorePopupCampaign::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('priority')->orderByDesc('id')->get()
            ->first(fn (OnlineStorePopupCampaign $campaign) => $this->audienceMatches($campaign, $user)
                && $this->targetIsEligible($campaign, $now)
                && ! $this->hasEvent($campaign, $identity, 'dismiss')
                && $this->frequencyAllows($campaign, $identity));
    }

    public function record(OnlineStorePopupCampaign $campaign, Request $request, ?StoreUser $user, string $eventType): void
    {
        $identity = $this->identity($request, $user);
        $attributes = [
            'campaign_id' => $campaign->getKey(),
            'user_id' => $user?->getKey(),
            'visitor_hash' => $identity['visitor_hash'],
            'event_type' => $eventType,
        ];
        if ($eventType === 'impression' && $campaign->display_frequency === 'once_per_session') {
            $attributes['session_hash'] = $identity['session_hash'];
        }

        $values = [
            'session_hash' => $identity['session_hash'],
            'occurred_at' => now(),
        ];
        if ($eventType === 'click' || ($eventType === 'impression' && $campaign->display_frequency === 'always')) {
            OnlineStorePopupEvent::query()->create(array_merge($attributes, $values));

            return;
        }

        OnlineStorePopupEvent::query()->firstOrCreate($attributes, $values);
    }

    public function storefrontPayload(OnlineStorePopupCampaign $campaign): array
    {
        $action = $this->resolvedAction($campaign);

        return [
            'id' => (int) $campaign->getKey(),
            'image_path' => $campaign->image_path,
            'title_translations' => (array) $campaign->title_translations,
            'content_translations' => (array) $campaign->content_translations,
            'button_translations' => (array) $campaign->button_translations,
            'theme' => (string) $campaign->theme,
            'action_type' => $action['type'],
            'action_target_id' => $action['target_id'],
            'action_product_id' => $action['product_id'],
            'action_url' => $action['url'],
            'coupon_code' => $action['coupon_code'],
        ];
    }

    private function validateContract(array $data): void
    {
        if (($data['audience_type'] ?? null) === 'new_users' && empty($data['audience_days'])) {
            throw ValidationException::withMessages(['audience_days' => ['New-user campaigns require a positive audience window.']]);
        }
        $start = ! empty($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : null;
        $end = ! empty($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : null;
        if ($start && $end && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }

        $type = (string) ($data['action_type'] ?? 'none');
        $target = $data['action_target_id'] ?? null;
        $url = $data['action_url'] ?? null;
        $tables = [
            'listing' => 'online_store_listings', 'category' => 'online_store_categories',
            'promotion' => 'online_store_promotions', 'coupon' => 'online_store_coupons',
        ];
        if (isset($tables[$type])) {
            if (! $target || $url !== null || ! DB::table($tables[$type])->where('id', $target)->exists()) {
                throw ValidationException::withMessages(['action_target_id' => ['Choose an existing target that matches the action.']]);
            }
        } elseif ($type === 'url') {
            $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
            if ($target !== null || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages(['action_url' => ['Only safe HTTP or HTTPS URLs are allowed.']]);
            }
        } elseif ($type !== 'none' || $target !== null || $url !== null) {
            throw ValidationException::withMessages(['action_type' => ['The selected action contract is invalid.']]);
        }
    }

    private function audienceMatches(OnlineStorePopupCampaign $campaign, ?StoreUser $user): bool
    {
        return match ($campaign->audience_type) {
            'all' => true,
            'guests' => $user === null,
            'registered' => $user !== null,
            'new_users' => $user !== null && $user->created_at?->gte(now()->subDays(max(1, (int) $campaign->audience_days))),
            'no_orders' => $user !== null && ! $this->hasStoreOrders($user),
            'customers' => $user !== null && $this->hasStoreOrders($user),
            default => false,
        };
    }

    private function hasStoreOrders(StoreUser $user): bool
    {
        return SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE)
            ->where('origin_user_id', $user->getKey())->exists();
    }

    private function frequencyAllows(OnlineStorePopupCampaign $campaign, array $identity): bool
    {
        return match ($campaign->display_frequency) {
            'always' => true,
            'once' => ! $this->hasEvent($campaign, $identity, 'impression'),
            'once_per_session' => ! OnlineStorePopupEvent::query()->where('campaign_id', $campaign->getKey())
                ->where('event_type', 'impression')->where('session_hash', $identity['session_hash'])->exists(),
            default => false,
        };
    }

    private function hasEvent(OnlineStorePopupCampaign $campaign, array $identity, string $type): bool
    {
        return OnlineStorePopupEvent::query()->where('campaign_id', $campaign->getKey())
            ->where('event_type', $type)
            ->where(function (Builder $query) use ($identity) {
                if ($identity['user_id'] !== null) {
                    $query->where('user_id', $identity['user_id'])->orWhere('visitor_hash', $identity['visitor_hash']);
                } else {
                    $query->where('visitor_hash', $identity['visitor_hash']);
                }
            })->exists();
    }

    private function identity(Request $request, ?StoreUser $user): array
    {
        $visitor = trim((string) $request->header('X-Store-Visitor-ID'));
        if ($visitor === '') {
            $visitor = implode('|', [(string) $request->ip(), (string) $request->userAgent()]);
        }
        $session = trim((string) $request->header('X-Store-Session-ID'));
        if ($session === '') {
            $session = $visitor;
        }

        return [
            'user_id' => $user?->getKey(),
            'visitor_hash' => hash('sha256', mb_substr($visitor, 0, 255)),
            'session_hash' => hash('sha256', mb_substr($session, 0, 255)),
        ];
    }

    private function targetIsEligible(OnlineStorePopupCampaign $campaign, CarbonImmutable $now): bool
    {
        return $this->resolvedAction($campaign, $now)['eligible'];
    }

    private function resolvedAction(OnlineStorePopupCampaign $campaign, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $empty = ['type' => 'none', 'target_id' => null, 'product_id' => null, 'url' => null, 'coupon_code' => null, 'eligible' => false];
        if ($campaign->action_type === 'none') {
            return array_merge($empty, ['eligible' => true]);
        }
        if ($campaign->action_type === 'url') {
            return array_merge($empty, ['type' => 'url', 'url' => $campaign->action_url, 'eligible' => true]);
        }
        if ($campaign->action_type === 'listing') {
            $listing = DB::table('online_store_listings as listing')
                ->join('products as product', 'product.id', '=', 'listing.product_id')
                ->where('listing.id', $campaign->action_target_id)
                ->where('listing.status', 'published')
                ->where('listing.readiness_state', 'complete')
                ->whereNull('product.deleted_at')
                ->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('online_store_category_listing as membership')
                    ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                    ->whereColumn('membership.online_store_listing_id', 'listing.id')
                    ->where('category.is_active', true))
                ->first(['listing.id', 'listing.product_id']);

            return $listing ? array_merge($empty, ['type' => 'listing', 'target_id' => (int) $listing->id, 'product_id' => (int) $listing->product_id, 'eligible' => true]) : $empty;
        }
        if ($campaign->action_type === 'category') {
            $category = OnlineStoreCategory::query()->whereKey($campaign->action_target_id)->where('is_active', true)->first();

            return $category ? array_merge($empty, ['type' => 'category', 'target_id' => (int) $category->getKey(), 'eligible' => true]) : $empty;
        }
        if ($campaign->action_type === 'coupon') {
            $coupon = OnlineStoreCoupon::query()->whereKey($campaign->action_target_id)->where('is_active', true)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->first();

            return $coupon ? array_merge($empty, ['type' => 'coupon', 'target_id' => (int) $coupon->getKey(), 'coupon_code' => $coupon->code, 'eligible' => true]) : $empty;
        }
        if ($campaign->action_type === 'promotion') {
            $promotion = OnlineStorePromotion::query()->with('targets')->whereKey($campaign->action_target_id)->where('is_active', true)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->first();
            if (! $promotion) {
                return $empty;
            }
            $target = $promotion->targets->first();
            if (! $target) {
                return array_merge($empty, ['eligible' => true]);
            }
            $copy = clone $campaign;
            $copy->action_type = $target->target_type;
            $copy->action_target_id = $target->target_id;

            return $this->resolvedAction($copy, $now);
        }

        return $empty;
    }
}
