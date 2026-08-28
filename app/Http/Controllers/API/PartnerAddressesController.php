<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PartnerAddress;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerAddressesController extends Controller
{
    public function index(Request $request)
    {
        $partner = $this->resolvePartner($request);

        return response()->json([
            'status' => 'success',
            'data' => $partner->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $data = $this->validated($request);

        $address = DB::transaction(function () use ($partner, $data, $request) {
            if (($data['is_default'] ?? false) || ! $partner->addresses()->exists()) {
                $partner->addresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return $partner->addresses()->create(array_merge($data, [
                'street_address' => $this->street($data['street_address'] ?? null),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]));
        });

        return response()->json(['status' => 'success', 'data' => $address], 201);
    }

    public function update(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $address = $partner->addresses()->findOrFail($request->integer('address_id'));
        $data = $this->validated($request, true);

        DB::transaction(function () use ($partner, $address, $data, $request) {
            if ($data['is_default'] ?? false) {
                $partner->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }
            if (array_key_exists('street_address', $data)) {
                $data['street_address'] = $this->street($data['street_address']);
            }
            $address->update(array_merge($data, ['updated_by' => $request->user()?->id]));
        });

        return response()->json(['status' => 'success', 'data' => $address->fresh()]);
    }

    public function destroy(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $address = $partner->addresses()->findOrFail($request->integer('address_id'));
        $wasDefault = $address->is_default;

        DB::transaction(function () use ($partner, $address, $wasDefault) {
            $address->delete();
            if ($wasDefault) {
                $partner->addresses()->oldest('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['status' => 'success']);
    }

    private function resolvePartner(Request $request): Model
    {
        $data = $request->validate([
            'partner_type' => 'required|string|in:customer,seller',
            'partner_id' => 'required|integer|min:1',
        ]);

        return match ($data['partner_type']) {
            'customer' => Customer::query()->findOrFail($data['partner_id']),
            'seller' => Seller::query()->findOrFail($data['partner_id']),
        };
    }

    private function validated(Request $request, bool $update = false): array
    {
        $sometimes = $update ? 'sometimes|' : '';

        return $request->validate([
            'label' => $sometimes.'string|max:100',
            'city_id' => 'nullable|integer|exists:cities,id',
            'shiply_city_id' => 'required|integer|min:1',
            'shiply_village_id' => 'required|integer|min:1',
            'shiply_city_name' => 'nullable|string|max:255',
            'shiply_village_name' => 'nullable|string|max:255',
            'street_address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'delivery_notes' => 'nullable|string|max:2000',
            'is_default' => 'nullable|boolean',
        ]);
    }

    private function street(mixed $value): string
    {
        $street = trim((string) $value);

        return $street !== '' ? $street : '----';
    }
}
