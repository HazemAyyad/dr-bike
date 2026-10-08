<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreBanner;
use App\Models\OnlineStore\OnlineStoreListing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BannerService
{
    private const TARGET_TABLES = ['listing' => 'online_store_listings', 'category' => 'online_store_categories', 'promotion' => 'online_store_promotions'];

    public function save(?OnlineStoreBanner $banner, array $data, int $actorId, ?CarbonImmutable $at = null): OnlineStoreBanner
    {
        $at = ($at ?? CarbonImmutable::now(config('app.timezone')))->utc();
        $merged = array_merge($banner?->only(['action_type', 'action_target_id', 'action_url', 'starts_at', 'ends_at']) ?? [], $data);
        $this->validateAction($merged, $at);
        $data = $this->normalizeDates($data);
        $banner ??= new OnlineStoreBanner;
        if (! $banner->exists && ! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = ((int) OnlineStoreBanner::query()->max('sort_order')) + 1;
        }
        $banner->fill($data);
        if (! $banner->exists) {
            $banner->created_by = $actorId;
        }
        $banner->updated_by = $actorId;
        $banner->save();

        return $banner->fresh();
    }

    public function active(?CarbonImmutable $at = null)
    {
        $at = ($at ?? CarbonImmutable::now(config('app.timezone')))->utc();

        return OnlineStoreBanner::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->orderBy('sort_order')->orderBy('id')->get()->filter(fn ($banner) => $this->targetIsEligible($banner, $at))->values();
    }

    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            $actual = OnlineStoreBanner::query()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $provided = array_map('intval', $orderedIds);
            if (count($provided) !== count(array_unique($provided)) || collect($provided)->sort()->values()->all() !== $actual) {
                throw ValidationException::withMessages(['banner_ids' => ['A reorder must include every banner exactly once.']]);
            }
            foreach ($provided as $order => $id) {
                OnlineStoreBanner::query()->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    public function actionProductId(OnlineStoreBanner $banner): ?int
    {
        if ($banner->action_type !== 'listing' || ! $banner->action_target_id) {
            return null;
        }

        $productId = OnlineStoreListing::query()
            ->whereKey((int) $banner->action_target_id)
            ->value('product_id');

        return $productId === null ? null : (int) $productId;
    }

    private function validateAction(array $data, CarbonImmutable $at): void
    {
        $type = $data['action_type'] ?? 'none';
        $target = $data['action_target_id'] ?? null;
        $url = $data['action_url'] ?? null;
        if (isset(self::TARGET_TABLES[$type])) {
            if (! $target || $url !== null || ! DB::table(self::TARGET_TABLES[$type])->where('id', $target)->exists()) {
                throw ValidationException::withMessages(['action_target_id' => ['The action requires an existing compatible target and no URL.']]);
            }
            if (! $this->internalTargetIsEligible($type, (int) $target, $at)) {
                throw ValidationException::withMessages(['action_target_id' => ['The internal target is not currently customer-visible.']]);
            }
        } elseif ($type === 'url') {
            $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
            if ($target !== null || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages(['action_url' => ['Only safe HTTP or HTTPS URLs are allowed.']]);
            }
        } elseif ($type === 'none') {
            if ($target !== null || $url !== null) {
                throw ValidationException::withMessages(['action_type' => ['The none action cannot have a target or URL.']]);
            }
        } else {
            throw ValidationException::withMessages(['action_type' => ['Unsupported banner action.']]);
        }
        $start = $this->parseDate($data['starts_at'] ?? null);
        $end = $this->parseDate($data['ends_at'] ?? null);
        if ($start && $end && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }
    }

    private function normalizeDates(array $data): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->parseDate($data[$field]);
            }
        }

        return $data;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, config('app.timezone'))->utc() : null;
    }

    private function targetIsEligible(OnlineStoreBanner $banner, CarbonImmutable $at): bool
    {
        return match ($banner->action_type) {
            'listing', 'category', 'promotion' => $this->internalTargetIsEligible($banner->action_type, (int) $banner->action_target_id, $at),
            'url', 'none' => true,
            default => false,
        };
    }

    private function internalTargetIsEligible(string $type, int $targetId, CarbonImmutable $at): bool
    {
        return match ($type) {
            'listing' => DB::table('online_store_listings as listing')->join('products as product', 'product.id', '=', 'listing.product_id')
                ->where('listing.id', $targetId)->where('listing.status', 'published')->where('listing.readiness_state', 'complete')
                ->whereNull('product.deleted_at')->whereExists(fn ($query) => $query->selectRaw('1')->from('online_store_category_listing as membership')
                ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                ->whereColumn('membership.online_store_listing_id', 'listing.id')->where('category.is_active', true))->exists(),
            'category' => DB::table('online_store_categories')->where('id', $targetId)->where('is_active', true)->exists(),
            'promotion' => DB::table('online_store_promotions')->where('id', $targetId)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at))->exists(),
            default => false,
        };
    }
}
