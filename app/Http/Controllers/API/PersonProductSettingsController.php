<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PersonProductSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonProductSettingsController extends Controller
{
    public function index(Request $request)
    {
        $person = $this->validatePerson($request);

        $settings = PersonProductSetting::query()
            ->where($person['column'], $person['id'])
            ->with([
                'product:id,nameAr,product_code,normailPrice,wholesalePrice',
                'priceTiers',
            ])
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
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_qty' => 'required_with:price_tiers|integer|min:1',
            'price_tiers.*.max_qty' => 'nullable|integer|min:1',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:0.01',
        ]);

        $customPrice = $data['custom_price'] ?? null;
        $isHidden = (bool) ($data['is_hidden'] ?? false);
        $tiers = $this->normalizePriceTiers($data['price_tiers'] ?? []);
        if ($customPrice === null && ! $isHidden && empty($tiers)) {
            throw ValidationException::withMessages([
                'custom_price' => ['أدخل سعراً خاصاً أو أضف شرائح أسعار أو فعّل إخفاء المنتج.'],
            ]);
        }

        $setting = DB::transaction(function () use ($person, $data, $customPrice, $isHidden, $tiers) {
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

            $setting->priceTiers()->delete();
            foreach ($tiers as $tier) {
                $setting->priceTiers()->create($tier);
            }

            return $setting;
        });

        $setting->load([
            'product:id,nameAr,product_code,normailPrice,wholesalePrice',
            'priceTiers',
        ]);

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
            'price_tiers' => $setting->priceTiers
                ->sortBy('min_qty')
                ->values()
                ->map(fn ($tier) => [
                    'min_qty' => (int) $tier->min_qty,
                    'max_qty' => $tier->max_qty === null ? null : (int) $tier->max_qty,
                    'unit_price' => (float) $tier->unit_price,
                ])
                ->all(),
            'retail_price' => (float) ($setting->product?->normailPrice ?? 0),
            'wholesale_price' => (float) ($setting->product?->wholesalePrice ?? 0),
        ];
    }

    private function normalizePriceTiers(array $rows): array
    {
        $tiers = collect($rows)
            ->map(function ($row) {
                $row = is_array($row) ? $row : [];
                $min = (int) ($row['min_qty'] ?? 0);
                $maxRaw = $row['max_qty'] ?? null;
                $max = ($maxRaw === null || $maxRaw === '') ? null : (int) $maxRaw;
                $price = $row['unit_price'] ?? null;

                return [
                    'min_qty' => $min,
                    'max_qty' => $max,
                    'unit_price' => $price === null || $price === '' ? null : (float) $price,
                ];
            })
            ->filter(fn ($tier) => $tier['min_qty'] > 0 || $tier['unit_price'] !== null)
            ->sortBy('min_qty')
            ->values()
            ->all();

        $openEndedSeen = false;
        $previousMax = 0;
        foreach ($tiers as $index => $tier) {
            if ($tier['min_qty'] < 1 || $tier['unit_price'] === null || $tier['unit_price'] <= 0) {
                throw ValidationException::withMessages([
                    'price_tiers' => ['تأكد من إدخال كمية وسعر صحيحين لكل شريحة.'],
                ]);
            }
            if ($tier['max_qty'] !== null && $tier['max_qty'] < $tier['min_qty']) {
                throw ValidationException::withMessages([
                    'price_tiers' => ['كمية "إلى" يجب أن تكون أكبر أو تساوي كمية "من".'],
                ]);
            }
            if ($openEndedSeen || $tier['min_qty'] <= $previousMax) {
                throw ValidationException::withMessages([
                    'price_tiers' => ['شرائح الكميات لا يمكن أن تتداخل.'],
                ]);
            }

            if ($tier['max_qty'] === null) {
                $openEndedSeen = true;
                if ($index !== count($tiers) - 1) {
                    throw ValidationException::withMessages([
                        'price_tiers' => ['الشريحة المفتوحة يجب أن تكون آخر شريحة.'],
                    ]);
                }
            } else {
                $previousMax = $tier['max_qty'];
            }
        }

        return $tiers;
    }
}
