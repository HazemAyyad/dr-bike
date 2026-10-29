<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Services\OnlineStore\StoreDeliveryQuoteService;
use App\Support\ShiplySettings;
use Illuminate\Http\Request;

class StoreCitiesController extends StoreBaseController
{
    public function getAllCities()
    {
        $mode = ShiplySettings::mode();

        $rows = StoreShiplyCity::query()
            ->where('mode', $mode)
            ->whereNull('deleted_at_remote')
            ->orderBy('name')
            ->get()
            ->map(function (StoreShiplyCity $city) {
                return [
                    'id' => (int) $city->shiply_id,
                    'cityNameAr' => (string) $city->name,
                    'cityNameEng' => (string) $city->name,
                    'cityNameAbree' => (string) $city->name,
                    'deliver' => 0.0,
                    'isShow' => true,
                    'userIdAdd' => null,
                    'dateAdd' => $this->dateString($city->created_at),
                    'userUpdate' => null,
                    'dateUpdate' => $this->dateString($city->updated_at),
                ];
            });

        return response()->json($this->rowsResponse($rows));
    }

    public function getVillagesByCityId(Request $request)
    {
        $cityId = $request->query('cityId', $request->input('cityId'));
        $mode = ShiplySettings::mode();

        $rows = StoreShiplyVillage::query()
            ->where('mode', $mode)
            ->where('shiply_city_id', (int) $cityId)
            ->whereNull('deleted_at_remote')
            ->where('is_closed', false)
            ->orderBy('name')
            ->get()
            ->map(fn (StoreShiplyVillage $village) => [
                'id' => (int) $village->shiply_id,
                'name' => (string) $village->name,
                'note' => $village->note,
                'isClosed' => (bool) $village->is_closed,
            ]);

        return response()->json($this->rowsResponse($rows));
    }

    public function calculateDeliveryFee(Request $request)
    {
        $villageId = $request->query('villageId', $request->input('villageId', $request->input('shiplyVillageId')));
        $price = (float) $request->input('price', 0);
        $mode = ShiplySettings::mode();

        if (! is_numeric($villageId)) {
            return response()->json(['message' => 'VillageRequired'], 400);
        }

        $quote = app(StoreDeliveryQuoteService::class)->quote((int) $villageId, max(0, $price), $mode);
        $delivery = round((float) $quote['delivery_cost'], 2);

        return response()->json([
            'deliveryCost' => $delivery,
            'priceDelivery' => $delivery,
            'fees' => $quote,
        ]);
    }
}
