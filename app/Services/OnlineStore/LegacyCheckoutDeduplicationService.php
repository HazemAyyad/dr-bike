<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreLegacyCheckoutAttempt;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LegacyCheckoutDeduplicationService
{
    public const WINDOW_SECONDS = 120;

    public const PROTOCOL = 'legacy-store-checkout-v1';

    public function fingerprint(User $actor, array $payload): string
    {
        $items = collect($payload['details'] ?? [])->map(fn ($item) => [
            'product_id' => (int) ($item['itemId'] ?? 0),
            'size_id' => $this->nullableInt($item['itemSizeId'] ?? null),
            'size_color_id' => $this->nullableInt($item['itemSizeColorId'] ?? null),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ])->groupBy(fn ($item) => $item['product_id'].':'.($item['size_id'] ?? '').':'.($item['size_color_id'] ?? ''))
            ->map(function ($same) {
                $item = $same->first();
                $item['quantity'] = $same->sum('quantity');

                return $item;
            })->sortBy(fn ($item) => implode(':', array_map(fn ($value) => $value ?? '', $item)))->values()->all();
        $canonical = [
            'protocol' => self::PROTOCOL, 'actor' => (int) $actor->getKey(), 'items' => $items,
            'coupon' => mb_strtoupper(trim((string) ($payload['discoundCode'] ?? ''))),
            'city' => $this->nullableInt($payload['cityId'] ?? null),
            'village' => $this->nullableInt($payload['shiplyVillageId'] ?? $payload['villageId'] ?? null),
            'address' => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($payload['address'] ?? '')))),
            'payment' => 'cash',
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{order: SalesOrder, created: bool} */
    public function execute(User $actor, array $payload, callable $create): array
    {
        $fingerprint = $this->fingerprint($actor, $payload);

        return DB::transaction(function () use ($actor, $fingerprint, $create) {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $attempt = OnlineStoreLegacyCheckoutAttempt::query()->where('origin_user_id', $actor->getKey())
                ->where('request_fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($attempt && $attempt->expires_at->isFuture() && $attempt->sales_order_id) {
                return ['order' => SalesOrder::query()->findOrFail($attempt->sales_order_id), 'created' => false];
            }
            if ($attempt) {
                $attempt->delete();
            }
            $attempt = OnlineStoreLegacyCheckoutAttempt::create([
                'origin_user_id' => $actor->getKey(), 'request_fingerprint' => $fingerprint,
                'expires_at' => now()->addSeconds(self::WINDOW_SECONDS),
            ]);
            $order = $create('legacy:'.(string) \Illuminate\Support\Str::uuid());
            $attempt->update(['sales_order_id' => $order->getKey()]);

            return ['order' => $order, 'created' => true];
        });
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
