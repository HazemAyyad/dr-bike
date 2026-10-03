<?php

namespace App\Services\OnlineStore;

use App\Models\City;
use App\Models\Customer;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\PartnerAddress;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Seller;
use App\Models\SizeColor;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Models\User;
use App\Services\SalesOrderService;
use App\Services\SalesOrderStockService;
use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OnlineStoreCheckoutService
{
    public function __construct(
        private StoreIdentityService $identity,
        private StoreCheckoutIdempotencyService $idempotency,
        private LegacyCheckoutDeduplicationService $legacyDedupe,
        private StorePricingService $pricing,
        private CouponService $coupons,
        private ListingReadinessService $readiness,
        private SalesOrderStockService $stock,
        private SalesOrderService $orders,
        private ShiplyService $shiply,
    ) {}

    /** @return array{order: SalesOrder, created: bool} */
    public function checkout(User $actor, array $payload): array
    {
        $requestId = trim((string) ($payload['client_request_id'] ?? ''));

        return $this->idempotency->execute($actor, $requestId, fn () => $this->createOrder($actor, $payload, $requestId, false));
    }

    /** @return array{order: SalesOrder, created: bool} */
    public function legacyCheckout(User $actor, array $payload): array
    {
        return $this->legacyDedupe->execute($actor, $payload, fn (string $requestId) => $this->createOrder($actor, $payload, $requestId, true));
    }

    private function createOrder(User $actor, array $payload, string $requestId, bool $legacy): SalesOrder
    {
        $role = $legacy ? null : ($payload['account_role'] ?? null);
        $link = $this->identity->activeLink($actor, $role);
        if (($payload['payment_type'] ?? 'cash') !== 'cash') {
            throw ValidationException::withMessages(['payment_type' => ['Credit and mixed Store checkout are introduced in the approved credit phase.']]);
        }
        $lines = $this->resolveLines($legacy ? ($payload['details'] ?? []) : ($payload['items'] ?? []), $legacy);
        $couponCode = $legacy ? ($payload['discoundCode'] ?? null) : ($payload['coupon_code'] ?? null);
        $priced = $this->pricing->price($actor, $link, $lines, $couponCode, now(), true);
        $selectedAddress = $this->selectedAddress($link, $payload['partner_address_id'] ?? null);
        if ($selectedAddress) {
            $payload = [...$payload,
                'customer_address' => $selectedAddress->street_address,
                'city_id' => $selectedAddress->city_id,
                'shiply_city_id' => $selectedAddress->shiply_city_id,
                'shiply_village_id' => $selectedAddress->shiply_village_id,
            ];
        }
        $address = $this->addressData($payload, $legacy, $priced['payable_before_delivery']);
        $party = $link->party();
        $items = collect($priced['items'])->map(fn ($item) => [
            'product_id' => $item['product_id'], 'size_id' => $item['size_id'], 'size_color_id' => $item['size_color_id'],
            'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'is_hidden' => false,
        ])->all();
        $reserveCoupon = $priced['coupon'] ? function (SalesOrder $order) use ($priced, $actor, $link): void {
            $this->coupons->reserve($priced['coupon'], $order, $actor, $link, $priced['coupon_discount']);
        } : null;
        $trusted = new Request([
            'partner_type' => $link->role, 'partner_id' => $party->getKey(),
            'customer_id' => $link->role === 'customer' ? $party->getKey() : null,
            'customer_name' => $party->name, 'customer_phone' => $selectedAddress?->phone ?: $party->phone,
            'customer_address' => $address['customer_address'] ?: ($party->address ?? '----'),
            'city_id' => $address['city_id'], 'shiply_city_id' => $address['shiply_city_id'],
            'shiply_village_id' => $address['shiply_village_id'], 'shiply_city_name' => $address['shiply_city_name'],
            'shiply_village_name' => $address['shiply_village_name'], 'customer_delivery_fee' => $address['delivery_fee'],
            'partner_address_id' => $payload['partner_address_id'] ?? null,
            'delivery_company_id' => $payload['delivery_company_id'] ?? null,
            'payment_type' => 'cash', 'payment_amount' => 0, 'discount' => $priced['coupon_discount'],
            'items' => $items, 'reserve_stock' => true,
            'notes' => $couponCode ? 'Online Store coupon applied' : null,
        ]);
        $order = $this->orders->store($actor, $trusted, [
            'origin' => SalesOrder::ORIGIN_STORE, 'origin_user_id' => $actor->getKey(), 'client_request_id' => $requestId,
            'before_creation_effects' => $reserveCoupon,
        ]);

        return $order;
    }

    private function resolveLines(array $rawLines, bool $legacy): array
    {
        $grouped = collect($rawLines)->map(function ($item) use ($legacy) {
            $listing = $legacy
                ? OnlineStoreListing::query()->where('product_id', (int) ($item['itemId'] ?? 0))->first()
                : OnlineStoreListing::query()->find((int) ($item['listing_id'] ?? 0));
            if (! $listing) {
                throw ValidationException::withMessages(['items' => ['A submitted product is not listed in the Online Store.']]);
            }
            $listing->load('product');
            if ($listing->status !== 'published' || $this->readiness->evaluate($listing)['state'] !== 'complete') {
                throw ValidationException::withMessages(['items' => ['A submitted listing is not customer-purchasable.']]);
            }
            $sizeId = $this->nullableInt($legacy ? ($item['itemSizeId'] ?? null) : ($item['size_id'] ?? null));
            $colorId = $this->nullableInt($legacy ? ($item['itemSizeColorId'] ?? null) : ($item['size_color_id'] ?? null));
            if ($colorId !== null) {
                $variant = SizeColor::query()->with('size')->find($colorId);
                if (! $variant || (int) $variant->size?->itemId !== (int) $listing->product_id || ($sizeId !== null && (int) $variant->sizeId !== $sizeId)) {
                    throw ValidationException::withMessages(['items' => ['A submitted variant does not belong to its Product.']]);
                }
                $sizeId = (int) $variant->sizeId;
            }

            return ['listing' => $listing, 'quantity' => max(1, (int) ($item['quantity'] ?? 1)), 'size_id' => $sizeId, 'size_color_id' => $colorId];
        })->groupBy(fn ($line) => $line['listing']->getKey().':'.($line['size_id'] ?? '').':'.($line['size_color_id'] ?? ''))
            ->map(function ($same) {
                $line = $same->first();
                $line['quantity'] = $same->sum('quantity');

                return $line;
            })->values();
        if ($grouped->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['At least one item is required.']]);
        }

        $productIds = $grouped->pluck('listing.product_id')->unique()->sort()->values()->all();
        $lockedProducts = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variantIds = $grouped->pluck('size_color_id')->filter()->unique()->sort()->values()->all();
        if ($variantIds !== []) {
            SizeColor::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        }
        foreach ($grouped as $line) {
            $line['listing']->setRelation('product', $lockedProducts->get($line['listing']->product_id));
            if ($this->readiness->evaluate($line['listing'])['state'] !== 'complete') {
                throw ValidationException::withMessages(['items' => ['A submitted listing became ineligible before checkout.']]);
            }
        }

        $availability = collect($this->stock->bulkAvailability($productIds));
        foreach ($grouped as $line) {
            $available = $availability->first(fn ($row) => (int) $row['product_id'] === (int) $line['listing']->product_id
                && (($line['size_color_id'] === null && (($row['is_aggregate'] ?? false) || $row['size_color_id'] === null))
                    || (int) $row['size_color_id'] === (int) $line['size_color_id']));
            if (! $available || (int) $available['available_qty'] < $line['quantity']) {
                throw ValidationException::withMessages(['items' => ['Requested quantity exceeds authoritative available stock.']]);
            }
        }

        return $grouped->all();
    }

    private function addressData(array $payload, bool $legacy, float $parcelPrice): array
    {
        $cityId = $legacy ? null : $this->nullableInt($payload['city_id'] ?? null);
        $shiplyId = $legacy ? $this->nullableInt($payload['cityId'] ?? null) : $this->nullableInt($payload['shiply_city_id'] ?? null);
        $villageId = $this->nullableInt($legacy ? ($payload['shiplyVillageId'] ?? $payload['villageId'] ?? null) : ($payload['shiply_village_id'] ?? null));
        $mode = ShiplySettings::mode();
        $shiplyCity = $shiplyId ? StoreShiplyCity::query()->where('mode', $mode)->where('shiply_id', $shiplyId)->whereNull('deleted_at_remote')->first() : null;
        if (! $legacy && $shiplyId && ! $shiplyCity) {
            throw ValidationException::withMessages(['shiply_city_id' => ['The selected delivery city is unavailable.']]);
        }
        $city = $cityId ? City::query()->where('is_active', true)->find($cityId) : null;
        if (! $legacy && $cityId && ! $city) {
            throw ValidationException::withMessages(['city_id' => ['The selected delivery city is unavailable.']]);
        }
        if (! $city && $shiplyCity) {
            $city = City::query()->where('is_active', true)->where(fn ($q) => $q->where('shiply_area_code', (string) $shiplyId)->orWhere('name_ar', $shiplyCity->name)->orWhere('name_en', $shiplyCity->name))->first();
        }
        $villageQuery = $shiplyCity ? StoreShiplyVillage::query()->where('mode', $mode)
            ->where('shiply_city_id', (int) $shiplyCity->shiply_id)->whereNull('deleted_at_remote')->where('is_closed', false) : null;
        $village = $villageQuery && $villageId ? (clone $villageQuery)->where('shiply_id', $villageId)->first() : null;
        if (! $legacy && $villageId && ! $village) {
            throw ValidationException::withMessages(['shiply_village_id' => ['The selected delivery village is unavailable for this city.']]);
        }
        if ($legacy && ! $village && $villageQuery) {
            $village = $villageQuery->orderBy('name')->first();
        }
        $deliveryFee = round((float) ($city?->currentDeliveryFee() ?? 0), 2);
        if ($deliveryFee <= 0 && $village) {
            try {
                $deliveryFee = round((float) ($this->shiply->calculateDeliveryCost((int) $village->shiply_id, max(0, $parcelPrice), $mode)['delivery_cost'] ?? 0), 2);
            } catch (\Throwable) {
                $deliveryFee = 0.0;
            }
        }

        return ['customer_address' => trim((string) ($payload[$legacy ? 'address' : 'customer_address'] ?? '')), 'city_id' => $city?->getKey(),
            'shiply_city_id' => $shiplyId, 'shiply_village_id' => $village?->shiply_id, 'shiply_city_name' => $shiplyCity?->name,
            'shiply_village_name' => $village?->name, 'delivery_fee' => $deliveryFee];
    }

    private function selectedAddress(\App\Models\OnlineStore\OnlineStoreAccountLink $link, mixed $addressId): ?PartnerAddress
    {
        if (! is_numeric($addressId) || (int) $addressId < 1) {
            return null;
        }
        $partyClass = $link->role === 'customer' ? Customer::class : Seller::class;
        $address = PartnerAddress::query()->whereKey((int) $addressId)
            ->where('addressable_type', $partyClass)->where('addressable_id', $link->party()?->getKey())->first();
        if (! $address) {
            throw ValidationException::withMessages(['partner_address_id' => ['The selected delivery address does not belong to the linked party.']]);
        }

        return $address;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
