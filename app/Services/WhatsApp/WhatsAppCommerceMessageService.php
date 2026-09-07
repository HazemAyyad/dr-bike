<?php

namespace App\Services\WhatsApp;

use App\Models\MetaCatalogProductSync;
use App\Models\Product;
use App\Models\SizeColor;
use App\Models\WhatsAppMessage;
use App\Support\ApiImageUrl;
use Illuminate\Support\Collection;

class WhatsAppCommerceMessageService
{
    public function details(WhatsAppMessage $message): ?array
    {
        $payload = $message->raw_payload ?: [];
        $kind = null;
        $rawItems = [];

        if ($message->direction === 'inbound' && data_get($payload, 'type') === 'order') {
            $kind = 'order';
            $rawItems = (array) data_get($payload, 'order.product_items', []);
        } elseif (
            $message->direction === 'outbound'
            && data_get($payload, 'type') === 'interactive'
            && data_get($payload, 'interactive.type') === 'product_list'
        ) {
            $kind = 'catalog';
            $rawItems = collect((array) data_get($payload, 'interactive.action.sections', []))
                ->flatMap(fn ($section) => (array) data_get($section, 'product_items', []))
                ->all();
        }

        if ($kind === null) return null;

        $entries = collect($rawItems)->map(fn ($item) => [
            'retailer_id' => (string) data_get($item, 'product_retailer_id', ''),
            'quantity' => $kind === 'order' ? max(1, (int) data_get($item, 'quantity', 1)) : 1,
            'meta_unit_price' => is_numeric(data_get($item, 'item_price'))
                ? (float) data_get($item, 'item_price')
                : null,
            'currency' => data_get($item, 'currency'),
        ])->filter(fn ($item) => $item['retailer_id'] !== '')->values();

        $catalog = $this->catalogItems($entries->pluck('retailer_id'), $message->whatsapp_account_id);
        $items = $entries->map(function (array $entry) use ($catalog) {
            $resolved = $catalog->get($entry['retailer_id']);
            $unitPrice = $resolved['unit_price'] ?? $entry['meta_unit_price'] ?? 0;

            return array_merge($entry, $resolved ?: [
                'product_id' => null,
                'size_color_id' => null,
                'size_id' => null,
                'size_label' => null,
                'color_label' => null,
                'name' => 'منتج غير مربوط: '.$entry['retailer_id'],
                'variant_label' => null,
                'unit_price' => (float) $unitPrice,
                'stock' => 0,
                'image' => null,
                'matched' => false,
            ], [
                'line_total' => (float) $unitPrice * $entry['quantity'],
            ]);
        })->values();

        return [
            'kind' => $kind,
            'title' => $kind === 'order' ? 'سلة منتجات من الزبون' : 'كتالوج منتجات مُرسل',
            'items_count' => $items->sum('quantity'),
            'lines_count' => $items->count(),
            'estimated_total' => $items->sum('line_total'),
            'currency' => $items->pluck('currency')->filter()->first() ?: 'ILS',
            'can_convert' => $kind === 'order'
                && $items->isNotEmpty()
                && $items->every(fn (array $item) => $item['matched'] === true
                    && $item['stock'] > 0
                    && $item['quantity'] <= $item['stock']),
            'items' => $items->all(),
        ];
    }

    protected function catalogItems(Collection $retailerIds, ?int $accountId): Collection
    {
        $ids = $retailerIds->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        $syncs = MetaCatalogProductSync::query()
            ->with(['product.normalImages', 'variant.size'])
            ->whereIn('meta_catalog_retailer_id', $ids)
            ->when($accountId, fn ($query) => $query->orderByRaw('whatsapp_account_id = ? desc', [$accountId]))
            ->get()
            ->unique('meta_catalog_retailer_id')
            ->keyBy('meta_catalog_retailer_id');

        return $ids->mapWithKeys(function (string $retailerId) use ($syncs) {
            $sync = $syncs->get($retailerId);
            $product = $sync?->product ?: Product::query()
                ->with('normalImages')
                ->where('meta_catalog_retailer_id', $retailerId)
                ->first();
            $variant = $sync?->variant ?: SizeColor::query()
                ->with('size.product.normalImages')
                ->where('meta_catalog_retailer_id', $retailerId)
                ->first();
            $product ??= $variant?->size?->product;
            if (! $product) return [];

            $label = collect([
                trim((string) ($variant?->colorAr ?: $variant?->colorEn)),
                trim((string) $variant?->size?->size),
            ])->filter()->implode(' / ');
            $image = $variant?->image_url ?: $product->normalImages->first()?->imageUrl;

            return [$retailerId => [
                'product_id' => (string) $product->id,
                'size_color_id' => $variant ? (string) $variant->id : null,
                'size_id' => $variant?->size ? (string) $variant->size->id : null,
                'size_label' => $variant?->size?->size,
                'color_label' => $variant?->colorAr ?: $variant?->colorEn,
                'name' => $product->nameAr ?: $product->nameEng ?: 'منتج',
                'variant_label' => $label ?: null,
                'unit_price' => (float) ($variant?->normailPrice ?? $product->normailPrice ?? 0),
                'stock' => (int) ($variant?->stock ?? $product->stock ?? 0),
                'image' => filled($image) ? ApiImageUrl::normalize((string) $image) : null,
                'matched' => true,
            ]];
        });
    }
}
