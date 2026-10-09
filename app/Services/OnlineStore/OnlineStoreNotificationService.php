<?php

namespace App\Services\OnlineStore;

use App\Enums\SalesOrderStatus;
use App\Models\AdminNotification;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStoreNotificationBroadcast;
use App\Models\OnlineStore\OnlineStorePopupCampaign;
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

    public const TYPE_POPUP_CAMPAIGN = AdminNotificationService::TYPE_STORE_POPUP_CAMPAIGN;

    public const TYPE_BROADCAST = AdminNotificationService::TYPE_STORE_BROADCAST;

    public const TYPE_SUPPORT_MESSAGE = AdminNotificationService::TYPE_STORE_SUPPORT_MESSAGE;

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

    /** @return array{broadcast_id: int, recipients: int, sent: int, push: int, skipped: int} */
    public function broadcastPopupCampaign(OnlineStorePopupCampaign $campaign, ?User $actor = null): array
    {
        $payload = app(PopupCampaignService::class)->storefrontPayload($campaign);
        $destination = $this->campaignDestination($payload);
        $broadcast = OnlineStoreNotificationBroadcast::query()->create([
            'title_translations' => (array) $campaign->title_translations,
            'body_translations' => (array) $campaign->content_translations,
            'audience_type' => $this->broadcastAudience($campaign->audience_type),
            'audience_days' => $campaign->audience_days,
            'destination_type' => $destination['destination_type'],
            'destination_id' => $destination['destination_id'] ?? null,
            'destination_url' => $destination['destination_url'] ?? null,
            'popup_campaign_id' => $campaign->getKey(),
            'created_by' => $actor?->getKey(),
        ]);

        return $this->deliverBroadcast($broadcast, self::TYPE_POPUP_CAMPAIGN);
    }

    /** @return array{broadcast_id: int, recipients: int, sent: int, push: int, skipped: int} */
    public function broadcast(array $data, User $actor): array
    {
        $broadcast = OnlineStoreNotificationBroadcast::query()->create([
            'title_translations' => $data['title_translations'],
            'body_translations' => $data['body_translations'],
            'audience_type' => $data['audience_type'],
            'audience_days' => $data['audience_days'] ?? null,
            'destination_type' => $data['destination_type'],
            'destination_id' => $data['destination_id'] ?? null,
            'destination_url' => $data['destination_url'] ?? null,
            'created_by' => $actor->getKey(),
        ]);

        return $this->deliverBroadcast($broadcast, self::TYPE_BROADCAST);
    }

    /** @return array{broadcast_id: int, recipients: int, sent: int, push: int, skipped: int} */
    private function deliverBroadcast(OnlineStoreNotificationBroadcast $broadcast, string $type): array
    {
        $recipients = $this->broadcastRecipients($broadcast);
        $sent = 0;
        $push = 0;
        $skipped = 0;
        foreach ($recipients as $recipient) {
            if (! $this->eligibleRecipient($recipient)) {
                $skipped++;

                continue;
            }
            $locale = $this->localeFor($recipient);
            $title = $this->localized((array) $broadcast->title_translations, $locale);
            $body = $this->localized((array) $broadcast->body_translations, $locale);
            $data = [
                ...$this->notificationDestination($broadcast),
                'destination_url' => $broadcast->destination_url,
                'broadcast_id' => (string) $broadcast->getKey(),
                'popup_campaign_id' => $broadcast->popup_campaign_id === null ? null : (string) $broadcast->popup_campaign_id,
                'locale' => $locale,
                'source' => 'online_store',
            ];
            $notification = $this->notifications->createStoreRecipientNotification(
                $recipient,
                $type,
                $title,
                $body,
                $data,
                'online_store_notification_broadcast',
                (int) $broadcast->getKey(),
            );
            if ($notification) {
                $sent++;
                if (trim((string) $recipient->fcm_token) !== '') {
                    $push++;
                }
            } else {
                $skipped++;
            }
        }
        $result = ['broadcast_id' => (int) $broadcast->getKey(), 'recipients' => $recipients->count(), 'sent' => $sent, 'push' => $push, 'skipped' => $skipped];
        $broadcast->forceFill([
            'recipient_count' => $result['recipients'],
            'sent_count' => $sent,
            'push_count' => $push,
            'skipped_count' => $skipped,
            'sent_at' => now(),
        ])->save();

        return $result;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AdminNotification> */
    public function notificationsFor(User $recipient)
    {
        if (! $this->eligibleRecipient($recipient)) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return AdminNotification::query()
            ->where('recipient_user_id', $recipient->getKey())
            ->whereIn('type', $this->storeTypes())
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
            ->whereIn('type', $this->storeTypes())
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
        $locale = App::getLocale();
        $labels = [
            'ar' => ['unconfirmed' => 'بانتظار التأكيد', 'confirmed' => 'تم التأكيد', 'ready' => 'جاهز', 'postponed' => 'مؤجل', 'with_delivery' => 'مع المندوب', 'delivered' => 'تم التوصيل', 'archived' => 'مكتمل', 'review' => 'قيد المراجعة', 'stuck' => 'متعثر', 'returned' => 'مرتجع', 'partial_delivered' => 'تم التسليم جزئياً', 'partial_return' => 'مرتجع جزئياً', 'alternative_return' => 'استبدال مرتجع', 'canceled' => 'ملغي'],
            'en' => ['unconfirmed' => 'awaiting confirmation', 'confirmed' => 'confirmed', 'ready' => 'ready', 'postponed' => 'postponed', 'with_delivery' => 'out for delivery', 'delivered' => 'delivered', 'archived' => 'completed', 'review' => 'under review', 'stuck' => 'delayed', 'returned' => 'returned', 'partial_delivered' => 'partially delivered', 'partial_return' => 'partially returned', 'alternative_return' => 'replacement return', 'canceled' => 'canceled'],
            'he' => ['unconfirmed' => 'ממתינה לאישור', 'confirmed' => 'אושרה', 'ready' => 'מוכנה', 'postponed' => 'נדחתה', 'with_delivery' => 'בדרך אליך', 'delivered' => 'נמסרה', 'archived' => 'הושלמה', 'review' => 'בבדיקה', 'stuck' => 'מעוכבת', 'returned' => 'הוחזרה', 'partial_delivered' => 'נמסרה חלקית', 'partial_return' => 'הוחזרה חלקית', 'alternative_return' => 'החזרת החלפה', 'canceled' => 'בוטלה'],
        ];
        $label = $labels[$locale][$status] ?? $status;

        return match ($locale) {
            'he' => ['עדכון להזמנה שלך', 'סטטוס ההזמנה עודכן: '.$label],
            'en' => ['Order update', 'Your order status is now '.$label.'.'],
            default => ['تحديث على طلبك', 'أصبحت حالة طلبك: '.$label],
        };
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

    /** @return list<string> */
    private function storeTypes(): array
    {
        return [
            self::TYPE_ORDER_STATUS,
            self::TYPE_MARKETING_PROMOTION,
            self::TYPE_POPUP_CAMPAIGN,
            self::TYPE_BROADCAST,
            self::TYPE_SUPPORT_MESSAGE,
        ];
    }

    private function localized(array $translations, string $locale): string
    {
        foreach ([$locale, 'ar', 'en', 'he'] as $candidate) {
            $value = trim((string) ($translations[$candidate] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function broadcastAudience(string $audience): string
    {
        if ($audience === 'registered') {
            return 'all';
        }

        return in_array($audience, OnlineStoreNotificationBroadcast::AUDIENCES, true) ? $audience : 'all';
    }

    private function broadcastRecipients(OnlineStoreNotificationBroadcast $broadcast)
    {
        $query = User::query()
            ->where('type', 'User')
            ->where('is_blocked', false)
            ->whereHas('onlineStoreAccountLinks', fn ($links) => $links->where('status', 'active')->whereNotNull('verified_at'));
        if ($broadcast->audience_type === 'guests') {
            $query->whereRaw('1 = 0');
        } elseif ($broadcast->audience_type === 'new_users') {
            $query->where('created_at', '>=', now()->subDays(max(1, (int) $broadcast->audience_days)));
        } elseif ($broadcast->audience_type === 'no_orders') {
            $query->whereDoesntHave('originSalesOrders', fn ($orders) => $orders->where('origin', SalesOrder::ORIGIN_STORE));
        } elseif ($broadcast->audience_type === 'customers') {
            $query->whereHas('originSalesOrders', fn ($orders) => $orders->where('origin', SalesOrder::ORIGIN_STORE));
        }

        return $query->orderBy('id')->get();
    }

    /** @return array{destination_type: string, destination_id?: int|null, destination_url?: string|null} */
    private function campaignDestination(array $payload): array
    {
        return match ($payload['action_type'] ?? 'none') {
            'listing' => ['destination_type' => 'product', 'destination_id' => $payload['action_product_id']],
            'category' => ['destination_type' => 'category', 'destination_id' => $payload['action_target_id']],
            'coupon' => ['destination_type' => 'home'],
            'url' => ['destination_type' => 'url', 'destination_url' => $payload['action_url']],
            default => ['destination_type' => 'home'],
        };
    }

    /** @return array<string, scalar|null> */
    private function notificationDestination(OnlineStoreNotificationBroadcast $broadcast): array
    {
        if ($broadcast->destination_type === 'listing') {
            $productId = OnlineStoreListing::query()
                ->whereKey($broadcast->destination_id)->value('product_id');

            return $productId
                ? ['destination_type' => 'product', 'destination_id' => (string) $productId]
                : ['destination_type' => 'home', 'destination_id' => null];
        }

        return [
            'destination_type' => (string) $broadcast->destination_type,
            'destination_id' => $broadcast->destination_id === null ? null : (string) $broadcast->destination_id,
        ];
    }
}
