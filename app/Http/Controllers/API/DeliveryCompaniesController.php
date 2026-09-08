<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeliveryCompaniesController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => 'success',
            'delivery_companies' => DeliveryCompany::query()
                ->orderBy('is_active', 'desc')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (DeliveryCompany $company) => $this->format($company)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['name'] = trim($data['name']);
        $data['code'] = $this->uniqueCode($data['name']);
        $data['sort_order'] = (int) (DeliveryCompany::max('sort_order') ?? 0) + 1;
        $company = DeliveryCompany::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تمت إضافة جهة التوصيل بنجاح',
            'delivery_company' => $this->format($company),
        ], 201);
    }

    public function update(Request $request, DeliveryCompany $deliveryCompany)
    {
        $data = $this->validated($request, $deliveryCompany);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (! str_starts_with((string) $deliveryCompany->code, 'custom-')) {
            unset($data['delivery_type']);
        }
        $deliveryCompany->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث جهة التوصيل بنجاح',
            'delivery_company' => $this->format($deliveryCompany->fresh()),
        ]);
    }

    private function validated(Request $request, ?DeliveryCompany $company = null): array
    {
        return $request->validate([
            'name' => [
                $company ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('delivery_companies', 'name')->ignore($company?->id),
            ],
            'delivery_type' => [$company ? 'sometimes' : 'required', Rule::in(['office', 'taxi', 'internal', 'pickup'])],
            'default_carrier_fee' => 'nullable|numeric|min:0|max:9999999999.99',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'vehicle_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'carrier';
        do {
            $code = 'custom-'.$base.'-'.Str::lower(Str::random(5));
        } while (DeliveryCompany::where('code', $code)->exists());

        return $code;
    }

    private function format(DeliveryCompany $company): array
    {
        return [
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
            'notes' => $company->notes,
            'is_active' => (bool) $company->is_active,
            'sort_order' => (int) $company->sort_order,
        ];
    }
}
