<?php

namespace App\Models\OnlineStore;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStoreReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'product_id', 'customer_id', 'user_id', 'sales_order_id', 'rating', 'comment',
        'status', 'is_verified_purchase', 'moderated_by', 'moderated_at', 'moderation_reason',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
        'moderated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $review): void {
            if ($review->rating < 1 || $review->rating > 5) {
                throw new \InvalidArgumentException('Online Store review rating must be between 1 and 5.');
            }
            if (! in_array($review->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException('Online Store review status is invalid.');
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by')->withTrashed();
    }
}
