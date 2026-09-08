<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DeliveryCompany;

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
                ->get()
                ->map(fn (DeliveryCompany $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'delivery_type' => $company->operationalType(),
                    'default_carrier_fee' => $company->default_carrier_fee !== null
                        ? (float) $company->default_carrier_fee
                        : null,
                    'contact_name' => $company->contact_name,
                    'contact_phone' => $company->contact_phone,
                    'vehicle_number' => $company->vehicle_number,
                ]);

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
