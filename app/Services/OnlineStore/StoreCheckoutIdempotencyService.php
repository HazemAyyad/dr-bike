<?php

namespace App\Services\OnlineStore;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreCheckoutIdempotencyService
{
    /** @return array{order: SalesOrder, created: bool} */
    public function execute(User $actor, string $requestId, callable $create): array
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            throw ValidationException::withMessages(['client_request_id' => ['A stable checkout request identifier is required.']]);
        }

        return DB::transaction(function () use ($actor, $requestId, $create) {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $existing = SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE)
                ->where('origin_user_id', $actor->getKey())->where('client_request_id', $requestId)->first();
            if ($existing) {
                return ['order' => $existing, 'created' => false];
            }
            $order = $create();
            if (! $order instanceof SalesOrder || $order->origin !== SalesOrder::ORIGIN_STORE
                || (int) $order->origin_user_id !== (int) $actor->getKey() || $order->client_request_id !== $requestId) {
                throw new \LogicException('Store checkout did not persist its trusted idempotency identity.');
            }

            return ['order' => $order, 'created' => true];
        });
    }
}
