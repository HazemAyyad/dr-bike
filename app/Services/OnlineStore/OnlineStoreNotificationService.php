<?php

namespace App\Services\OnlineStore;

use App\Enums\SalesOrderStatus;
use App\Models\AdminNotification;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AdminNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;

final class OnlineStoreNotificationService
{
    public const TYPE_ORDER_STATUS = AdminNotificationService::TYPE_STORE_CUSTOMER_ORDER_STATUS;

    public const TYPE_MARKETING_PROMOTION = AdminNotificationService::TYPE_STORE_MARKETING_PROMOTION;

    private const SUPPORTED_LOCALES = ['ar', 'en', 'he'];

    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly StoreIdentityService $identity,
    ) {}

    public function notifyOrderStatus(SalesOrder $order, string $status): ?AdminNotification
    {
        if ($order->origin !== SalesOrder::ORIGIN_STORE
            || ! $order->origin_user_id
            || SalesOrderStatus::tryFrom($status) === null) {
            return null;
        }

        $recipient = User::query()->find($order->origin_user_id);
        if (! $recipient || ! $this->eligibleRecipient($recipient)) {
            return null;
        }
        if (! $this->identity->ownedOrders($recipient)->whereKey($order->getKey())->exists()) {
            return null;
        }

        $locale = $this->localeFor($recipient);

        return $this->withLocale($locale, function () use ($recipient, $order, $status, $locale) {
            [$title, $body] = $this->orderCopy($status);

            return $this->notifications->createStoreRecipientNotification(
                $recipient,
                self::TYPE_ORDER_STATUS,
                $title,
                $body,
                [
                    'destination_type' => 'order',
                    'destination_id' => (string) $order->getKey(),
                    'order_id' => (string) $order->getKey(),
                    'order_status' => $status,
                    'locale' => $locale,
                    'source' => 'online_store',
                ],
                'sales_order',
                (int) $order->getKey(),
            );
        });
    }

    public function notifyPromotion(
        User $recipient,
        OnlineStorePromotion $promotion,
        ?CarbonInterface $at = null,
    ): ?AdminNotification {
        $at ??= now();
        if (! $this->eligiblePromotionRecipient($recipient, $promotion)
            || ! $this->promotionIsCurrent($promotion, $at)) {
            return null;
        }

        $locale = $this->localeFor($recipient);

        return $this->withLocale($locale, function () use ($recipient, $promotion, $locale) {
            $name = trim((string) $promotion->name);
            [$title, $body] = $this->promotionCopy($locale, $name);

            return $this->notifications->createStoreRecipientNotification(
                $recipient,
                self::TYPE_MARKETING_PROMOTION,
                $title,
                $body,
                [
                    'destination_type' => 'promotion',
                    'destination_id' => (string) $promotion->getKey(),
                    'promotion_id' => (string) $promotion->getKey(),
                    'locale' => $locale,
                    'source' => 'online_store',
                ],
                'online_store_promotion',
                (int) $promotion->getKey(),
            );
        });
    }

    /** @return array{sent: int, skipped: int} */
    public function broadcastPromotion(OnlineStorePromotion $promotion, ?CarbonInterface $at = null): array
    {
        $links = OnlineStoreAccountLink::query()
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->with('user')
            ->get()
            ->unique('user_id');
        $sent = 0;
        $skipped = 0;
        foreach ($links as $link) {
            $notification = $link->user
                ? $this->notifyPromotion($link->user, $promotion, $at)
                : null;
            $notification ? $sent++ : $skipped++;
        }

        return compact('sent', 'skipped');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AdminNotification> */
    public function notificationsFor(User $recipient)
    {
        if (! $this->eligibleRecipient($recipient)) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return AdminNotification::query()
            ->where('recipient_user_id', $recipient->getKey())
            ->whereIn('type', [self::TYPE_ORDER_STATUS, self::TYPE_MARKETING_PROMOTION])
            ->where(function ($query) {
                $query->whereNull('data')
                    ->orWhereNull('data->_in_app_hidden')
                    ->orWhere('data->_in_app_hidden', '!=', '1');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function markRead(User $recipient, int $notificationId): AdminNotification
    {
        abort_unless($this->eligibleRecipient($recipient), 404);
        $notification = AdminNotification::query()
            ->whereKey($notificationId)
            ->where('recipient_user_id', $recipient->getKey())
            ->whereIn('type', [self::TYPE_ORDER_STATUS, self::TYPE_MARKETING_PROMOTION])
            ->firstOrFail();
        $notification->forceFill(['is_read' => true, 'read_at' => now()])->save();

        return $notification->fresh();
    }

    private function eligibleRecipient(User $recipient): bool
    {
        return ! $recipient->trashed()
            && ! $recipient->is_blocked
            && strcasecmp((string) $recipient->type, 'User') === 0
            && $this->identity->activeLinks($recipient)->isNotEmpty();
    }

    private function eligiblePromotionRecipient(User $recipient, OnlineStorePromotion $promotion): bool
    {
        $role = match ((string) $promotion->applies_to) {
            'retail' => 'customer',
            'wholesale' => 'seller',
            'both' => null,
            default => false,
        };

        if ($role === false) {
            return false;
        }

        return $this->identity->activeLinks($recipient, $role)->isNotEmpty();
    }

    private function promotionIsCurrent(OnlineStorePromotion $promotion, CarbonInterface $at): bool
    {
        return (bool) $promotion->is_active
            && (! $promotion->starts_at || $promotion->starts_at->lte($at))
            && (! $promotion->ends_at || $promotion->ends_at->gte($at));
    }

    private function localeFor(User $recipient): string
    {
        $preferences = is_array($recipient->ui_preferences) ? $recipient->ui_preferences : [];
        $candidate = strtolower((string) ($preferences['locale'] ?? $preferences['language'] ?? ''));
        if (in_array($candidate, self::SUPPORTED_LOCALES, true)) {
            return $candidate;
        }

        return 'ar';
    }

    /** @return array{0: string, 1: string} */
    private function orderCopy(string $status): array
    {
        $label = match ($status) {
            'confirmed' => 'confirmed',
            'ready' => 'ready',
            'with_delivery' => 'out for delivery',
            'delivered', 'archived' => 'delivered',
            'returned', 'partial_return', 'alternative_return' => 'returned',
            'canceled' => 'canceled',
            default => 'updated',
        };

        return App::getLocale() === 'ar'
            ? ['تحديث على طلبك', 'تم تحديث حالة طلبك: '.$label]
            : ['Order update', 'Your order status is now '.$label.'.'];
    }

    /** @return array{0: string, 1: string} */
    private function promotionCopy(string $locale, string $name): array
    {
        return $locale === 'ar'
            ? ['عرض جديد في المتجر', 'يتوفر الآن عرض: '.$name]
            : ['New Store offer', 'A promotion is now available: '.$name];
    }

    private function withLocale(string $locale, callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
