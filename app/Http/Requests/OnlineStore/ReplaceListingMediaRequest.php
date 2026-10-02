<?php

namespace App\Http\Requests\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceListingMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $listing = $this->route('listing');
        if (! $user || ! ($listing instanceof OnlineStoreListing)) {
            return false;
        }
        app(OnlineStorePolicy::class)->authorizeResource($user, 'Online Store Products Manage', true)->authorize();

        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.source_type' => ['required', OnlineStoreValues::rule(OnlineStoreValues::MEDIA_SOURCE_TYPES)],
            'items.*.source_id' => ['nullable', 'integer'], 'items.*.store_media_path' => ['nullable', 'string', 'max:2048'],
            'items.*.media_metadata' => ['nullable', 'array'], 'items.*.is_main' => ['required', 'boolean'],
            'items.*.is_visible' => ['required', 'boolean'], 'items.*.sort_order' => ['prohibited'],
            'price' => ['prohibited'], 'normailPrice' => ['prohibited'], 'wholesalePrice' => ['prohibited'],
            'stock' => ['prohibited'], 'base_price' => ['prohibited'], 'base_prices' => ['prohibited'],
        ];
    }
}
