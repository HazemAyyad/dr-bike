<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreShiplyCity;

class StoreCitiesController extends StoreBaseController
{
    public function getAllCities()
    {
        $rows = StoreShiplyCity::query()
            ->whereNull('deleted_at_remote')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreShiplyCity $city) => [
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
            ]);

        return response()->json($this->rowsResponse($rows));
    }
}
