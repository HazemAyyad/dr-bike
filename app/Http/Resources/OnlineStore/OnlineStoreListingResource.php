<?php

namespace App\Http\Resources\OnlineStore;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OnlineStoreListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource->product;
        $variantStock = DB::table('sizes')->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
            ->where('sizes.itemId', $product->getKey())->sum('size_colors.stock');
        $hasVariants = DB::table('sizes')->where('itemId', $product->getKey())->exists();
        $available = (int) ($hasVariants ? $variantStock : ($product->stock ?? 0));

        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'status' => $this->status,
            'name_translations' => $this->name_translations,
            'description_translations' => $this->description_translations,
            'badge_translations' => $this->badge_translations,
            'display' => [
                'name' => $this->fallback($this->name_translations, [$product->nameAr, $product->nameEng, $product->nameAbree]),
                'description' => $this->fallback($this->description_translations, [$product->descriptionAr, $product->descriptionEng, $product->descriptionAbree]),
            ],
            'is_featured' => $this->is_featured, 'is_new' => $this->is_new,
            'show_on_home' => $this->show_on_home, 'show_as_offer' => $this->show_as_offer,
            'sort_order' => $this->sort_order, 'readiness_state' => $this->readiness_state,
            'readiness_issues' => $this->readiness_issues ?? [],
            'base_prices' => ['retail' => $product->normailPrice, 'wholesale' => $product->wholesalePrice],
            'availability' => ['stock' => $available, 'in_stock' => $available > 0,
                'purchasable' => $this->status === 'published' && $available > 0],
            'published_at' => $this->published_at, 'hidden_at' => $this->hidden_at,
        ];
    }

    private function fallback(?array $translations, array $productValues): ?string
    {
        foreach ([...(array) $translations, ...$productValues] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
