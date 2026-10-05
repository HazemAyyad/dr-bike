<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OnlineStoreReviewService
{
    private const ELIGIBLE_ORDER_STATUSES = [
        'delivered',
        'archived',
        'partial_delivered',
        'partial_return',
    ];

    public function __construct(
        private readonly StoreIdentityService $identity,
        private readonly OnlineStoreAuditService $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function submit(User $actor, Product $product, array $input): OnlineStoreReview
    {
        $link = $this->customerLink($actor);
        $product = $this->validProduct($product->getKey());
        $data = $this->validatedSubmission($input);

        return DB::transaction(function () use ($actor, $link, $product, $data) {
            $evidence = $this->eligiblePurchase($link, (int) $product->getKey());

            return OnlineStoreReview::query()->create([
                'product_id' => $product->getKey(),
                'customer_id' => $link->customer_id,
                'user_id' => $actor->getKey(),
                'sales_order_id' => $evidence?->getKey(),
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => OnlineStoreReview::STATUS_PENDING,
                'is_verified_purchase' => $evidence !== null,
            ])->fresh($this->relations());
        });
    }

    /** @param array<string, mixed> $input */
    public function updateOwned(User $actor, int $reviewId, array $input): OnlineStoreReview
    {
        $link = $this->customerLink($actor);
        $data = $this->validatedSubmission($input);

        return DB::transaction(function () use ($actor, $link, $reviewId, $data) {
            $review = OnlineStoreReview::query()->lockForUpdate()
                ->whereKey($reviewId)
                ->where('user_id', $actor->getKey())
                ->where('customer_id', $link->customer_id)
                ->where('status', OnlineStoreReview::STATUS_PENDING)
                ->firstOrFail();
            $this->validProduct($review->product_id);
            $evidence = $this->eligiblePurchase($link, (int) $review->product_id);
            $review->fill([
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'sales_order_id' => $evidence?->getKey(),
                'is_verified_purchase' => $evidence !== null,
            ])->save();

            return $review->fresh($this->relations());
        });
    }

    public function moderate(User $actor, OnlineStoreReview $review, string $status, ?string $reason = null): OnlineStoreReview
    {
        validator(
            ['status' => $status, 'reason' => $reason],
            ['status' => ['required', Rule::in([OnlineStoreReview::STATUS_PUBLISHED, OnlineStoreReview::STATUS_REJECTED])], 'reason' => 'nullable|string|max:1000']
        )->validate();

        return DB::transaction(function () use ($actor, $review, $status, $reason) {
            $review = OnlineStoreReview::query()->lockForUpdate()->findOrFail($review->getKey());
            $review = $this->refreshVerifiedPurchase($review, lockEvidence: true);
            $before = $this->moderationAuditState($review);
            $review->forceFill([
                'status' => $status,
                'moderated_by' => $actor->getKey(),
                'moderated_at' => now(),
                'moderation_reason' => $reason,
            ])->save();
            $after = $this->moderationAuditState($review->fresh());
            $this->audit->record($actor, 'moderated', 'review', (int) $review->getKey(), $before, $after);

            return $review->fresh($this->relations());
        });
    }

    public function refreshVerifiedPurchase(OnlineStoreReview $review, bool $lockEvidence = false): OnlineStoreReview
    {
        $link = OnlineStoreAccountLink::query()
            ->where('user_id', $review->user_id)
            ->where('customer_id', $review->customer_id)
            ->where('role', 'customer')
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->first();
        $evidence = $link ? $this->eligiblePurchase($link, (int) $review->product_id, $lockEvidence) : null;
        $verified = $evidence !== null;
        $evidenceId = $evidence?->getKey();
        if ((bool) $review->is_verified_purchase !== $verified || (int) $review->sales_order_id !== (int) $evidenceId) {
            $review->forceFill([
                'sales_order_id' => $evidenceId,
                'is_verified_purchase' => $verified,
            ])->save();
        }

        return $review->fresh($this->relations());
    }

    /** @param iterable<OnlineStoreReview> $reviews @return Collection<int, OnlineStoreReview> */
    public function refreshVerifiedPurchases(iterable $reviews): Collection
    {
        return new Collection(collect($reviews)->map(fn (OnlineStoreReview $review) => $this->refreshVerifiedPurchase($review))->all());
    }

    public function publishedForProduct(int $productId): Collection
    {
        return $this->refreshVerifiedPurchases(
            OnlineStoreReview::query()->published()->where('product_id', $productId)
                ->with($this->relations())->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    public function ownedBy(User $actor): Collection
    {
        $link = $this->customerLink($actor);

        return $this->refreshVerifiedPurchases(
            OnlineStoreReview::query()->where('user_id', $actor->getKey())
                ->where('customer_id', $link->customer_id)
                ->with($this->relations())->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    /** @return array<string, mixed> */
    public function adminPayload(OnlineStoreReview $review): array
    {
        $review = $this->refreshVerifiedPurchase($review);

        return [
            'id' => (int) $review->getKey(),
            'product_id' => (int) $review->product_id,
            'product_name' => $review->product?->nameAr ?? $review->product?->nameEng,
            'customer_id' => (int) $review->customer_id,
            'customer_name' => $review->customer?->name,
            'user_id' => (int) $review->user_id,
            'sales_order_id' => $review->sales_order_id ? (int) $review->sales_order_id : null,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'is_verified_purchase' => (bool) $review->is_verified_purchase,
            'moderated_by' => $review->moderated_by ? (int) $review->moderated_by : null,
            'moderated_at' => $review->moderated_at?->toIso8601String(),
            'moderation_reason' => $review->moderation_reason,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function storePayload(OnlineStoreReview $review): array
    {
        $review = $this->refreshVerifiedPurchase($review);

        return [
            'id' => (int) $review->getKey(),
            'product_id' => (int) $review->product_id,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'is_verified_purchase' => (bool) $review->is_verified_purchase,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function legacyPayload(OnlineStoreReview $review): array
    {
        $review = $this->refreshVerifiedPurchase($review);

        return [
            'id' => (int) $review->getKey(),
            'comment' => (string) ($review->comment ?? ''),
            'productId' => (int) $review->product_id,
            'productName' => (string) ($review->product?->nameAr ?? $review->product?->nameEng ?? ''),
            'rate' => (int) $review->rating,
            'userName' => (string) ($review->user?->name ?? ''),
            'userAddId' => (string) $review->user_id,
            'isShow' => $review->status === OnlineStoreReview::STATUS_PUBLISHED,
            'dateAdd' => $review->created_at?->format('Y-m-d\TH:i:s') ?? '1970-01-01T00:00:00',
        ];
    }

    private function customerLink(User $actor): OnlineStoreAccountLink
    {
        return $this->identity->activeLink($actor, 'customer');
    }

    private function validProduct(int|string $productId): Product
    {
        $product = Product::query()->find($productId);
        if (! $product || ! (bool) $product->isShow) {
            throw ValidationException::withMessages(['product_id' => ['The selected Product is not available for Store reviews.']]);
        }

        return $product;
    }

    /** @param array<string, mixed> $input @return array{rating: int, comment?: string|null} */
    private function validatedSubmission(array $input): array
    {
        return validator($input, [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:5000',
        ])->validate();
    }

    private function eligiblePurchase(OnlineStoreAccountLink $link, int $productId, bool $lock = false): ?SalesOrder
    {
        $query = SalesOrder::query()
            ->whereIn('status', self::ELIGIBLE_ORDER_STATUSES)
            ->where(function (Builder $party) use ($link) {
                $party->where('customer_id', $link->customer_id)
                    ->orWhere(fn (Builder $partner) => $partner
                        ->where('partner_type', 'customer')
                        ->where('partner_id', $link->customer_id));
            })
            ->whereHas('items', function (Builder $items) use ($productId) {
                $items->where('product_id', $productId)
                    ->where('is_hidden', false)
                    ->where(function (Builder $remaining) {
                        $remaining->where(function (Builder $completed) {
                            $completed->whereIn('sales_orders.status', ['delivered', 'archived'])
                                ->whereColumn('sales_order_items.returned_qty', '<', 'sales_order_items.quantity');
                        })->orWhere(function (Builder $partial) {
                            $partial->whereIn('sales_orders.status', ['partial_delivered', 'partial_return'])
                                ->whereColumn('sales_order_items.returned_qty', '<', 'sales_order_items.delivered_qty');
                        });
                    });
            })
            ->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['product', 'customer', 'user', 'salesOrder', 'moderator'];
    }

    /** @return array<string, mixed> */
    private function moderationAuditState(OnlineStoreReview $review): array
    {
        return [
            'status' => $review->status,
            'is_verified_purchase' => (bool) $review->is_verified_purchase,
            'moderated_by' => $review->moderated_by,
            'moderated_at' => $review->moderated_at?->toIso8601String(),
        ];
    }
}
