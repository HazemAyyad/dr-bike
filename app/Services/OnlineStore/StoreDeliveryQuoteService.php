<?php

namespace App\Services\OnlineStore;

use App\Exceptions\DeliveryQuoteUnavailableException;
use App\Models\City;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Services\ShiplyService;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreDeliveryQuoteService
{
    public function __construct(private readonly ShiplyService $shiply) {}

    public function quote(int $villageId, float $parcelPrice, string $mode, ?City $city = null): array
    {
        $village = StoreShiplyVillage::query()->where('mode', $mode)->where('shiply_id', $villageId)
            ->whereNull('deleted_at_remote')->where('is_closed', false)->first();
        if (! $village) {
            throw ValidationException::withMessages(['villageId' => ['The selected delivery village is unavailable.']]);
        }
        if (! $city) {
            $shiplyCity = StoreShiplyCity::query()->where('mode', $mode)
                ->where('shiply_id', $village->shiply_city_id)->whereNull('deleted_at_remote')->first();
            $city = $shiplyCity ? City::query()->where('is_active', true)->where(fn ($query) => $query
                ->where('shiply_area_code', (string) $shiplyCity->shiply_id)
                ->orWhere('name_ar', $shiplyCity->name)->orWhere('name_en', $shiplyCity->name))->first() : null;
        }
        $fee = $city?->currentDeliveryFee();
        if ($fee !== null && is_finite($fee) && $fee >= 0) {
            return ['delivery_cost' => round($fee, 2), 'extra_price' => 0.0, 'returned_extra_price' => 0.0];
        }
        try {
            $quote = $this->shiply->calculateDeliveryCost($villageId, max(0, $parcelPrice), $mode);
        } catch (Throwable) {
            throw new DeliveryQuoteUnavailableException;
        }
        if (! array_key_exists('delivery_cost', $quote) || ! is_numeric($quote['delivery_cost'])
            || ! is_finite((float) $quote['delivery_cost']) || (float) $quote['delivery_cost'] < 0) {
            throw new DeliveryQuoteUnavailableException;
        }
        $quote['delivery_cost'] = round((float) $quote['delivery_cost'], 2);

        return $quote;
    }
}
