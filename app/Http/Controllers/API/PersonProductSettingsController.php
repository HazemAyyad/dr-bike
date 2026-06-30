<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PersonProductSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PersonProductSettingsController extends Controller
{
    public function index(Request $request)
    {
        $person = $this->validatePerson($request);

        $settings = PersonProductSetting::query()
            ->where($person['column'], $person['id'])
            ->with(['product:id,nameAr,product_code,normailPrice,wholesalePrice'])
            ->latest('id')
            ->get()
            ->map(fn (PersonProductSetting $setting) => $this->format($setting));

        return response()->json([
            'status' => 'success',
            'settings' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $person = $this->validatePerson($request);
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'custom_price' => 'nullable|numeric|min:0.01',
            'is_hidden' => 'nullable|boolean',
        ]);

        $customPrice = $data['custom_price'] ?? null;
        $isHidden = (bool) ($data['is_hidden'] ?? false);
        if ($customPrice === null && ! $isHidden) {
            throw ValidationException::withMessages([
                'custom_price' => ['أدخل سعراً خاصاً أو فعّل إخفاء المنتج.'],
            ]);
        }

        $setting = PersonProductSetting::updateOrCreate(
            [
                $person['column'] => $person['id'],
                'product_id' => $data['product_id'],
            ],
            [
                $person['other_column'] => null,
                'custom_price' => $customPrice,
                'is_hidden' => $isHidden,
            ]
        );

        $setting->load('product:id,nameAr,product_code,normailPrice,wholesalePrice');

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ تخصيص المنتج.',
            'setting' => $this->format($setting),
        ]);
    }

    public function destroy(Request $request)
    {
        $person = $this->validatePerson($request);
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        PersonProductSetting::query()
            ->where($person['column'], $person['id'])
            ->where('product_id', $data['product_id'])
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم إلغاء تخصيص المنتج.',
        ]);
    }

    private function validatePerson(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'seller_id' => 'nullable|integer|exists:sellers,id',
        ]);

        $hasCustomer = ! empty($data['customer_id']);
        $hasSeller = ! empty($data['seller_id']);
        if ($hasCustomer === $hasSeller) {
            throw ValidationException::withMessages([
                'person' => ['يجب تحديد زبون أو تاجر واحد فقط.'],
            ]);
        }

        return $hasCustomer
            ? ['column' => 'customer_id', 'other_column' => 'seller_id', 'id' => (int) $data['customer_id']]
            : ['column' => 'seller_id', 'other_column' => 'customer_id', 'id' => (int) $data['seller_id']];
    }

    private function format(PersonProductSetting $setting): array
    {
        return [
            'product_id' => (int) $setting->product_id,
            'product_name' => $setting->product?->nameAr,
            'product_code' => $setting->product?->product_code,
            'custom_price' => $setting->custom_price,
            'is_hidden' => $setting->is_hidden,
            'retail_price' => (float) ($setting->product?->normailPrice ?? 0),
            'wholesale_price' => (float) ($setting->product?->wholesalePrice ?? 0),
        ];
    }
}
