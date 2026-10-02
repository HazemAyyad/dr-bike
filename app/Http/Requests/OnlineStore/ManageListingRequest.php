<?php

namespace App\Http\Requests\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\Product;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Foundation\Http\FormRequest;

class ManageListingRequest extends FormRequest
{
    public const PERMISSION = 'Online Store Products Manage';

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $resource = $this->route('listing') ?? $this->route('product');
        if ($resource instanceof OnlineStoreListing || $resource instanceof Product) {
            app(OnlineStorePolicy::class)
                ->authorizeResource($user, self::PERMISSION, true)
                ->authorize();

            return true;
        }

        return app(OnlineStorePolicy::class)->authorize($user, self::PERMISSION)->allowed();
    }

    public function rules(): array
    {
        $rules = [
            'normailPrice' => ['prohibited'], 'wholesalePrice' => ['prohibited'],
            'price' => ['prohibited'], 'stock' => ['prohibited'],
            'base_price' => ['prohibited'], 'base_prices' => ['prohibited'],
        ];

        if ($this->routeIs('online-store.listings.store')) {
            $rules['product_id'] = ['required', 'integer', 'exists:products,id'];
        } elseif ($this->routeIs('online-store.listings.transition')) {
            $rules['status'] = ['required', OnlineStoreValues::listingStatusRule()];
        } else {
            $rules['product_id'] = ['prohibited'];
        }

        if (! $this->routeIs('online-store.listings.transition')) {
            foreach (['name_translations', 'description_translations', 'badge_translations'] as $field) {
                $rules[$field] = ['sometimes', 'nullable', 'array'];
                $rules[$field.'.*'] = ['nullable', 'string'];
            }
            foreach (['is_featured', 'is_new', 'show_on_home', 'show_as_offer'] as $field) {
                $rules[$field] = ['sometimes', 'boolean'];
            }
            $rules['sort_order'] = ['sometimes', 'integer', 'min:0'];
        }

        return $rules;
    }
}
