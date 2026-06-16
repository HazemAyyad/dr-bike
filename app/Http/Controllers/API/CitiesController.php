<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DeliveryCompany;
use Illuminate\Http\Request;

class CitiesController extends Controller
{
    public function index()
    {
        try {
            $cities = City::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get()
                ->map(fn (City $city) => [
                    'id' => $city->id,
                    'name_ar' => $city->name_ar,
                    'name_en' => $city->name_en,
                    'delivery_fee' => $city->currentDeliveryFee(),
                    'shiply_area_code' => $city->shiply_area_code,
                ]);

            return response()->json([
                'status' => 'success',
                'cities' => $cities,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function deliveryCompanies()
    {
        try {
            $companies = DeliveryCompany::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code']);

            return response()->json([
                'status' => 'success',
                'delivery_companies' => $companies,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
