<?php

namespace App\Services\Meta;

use App\Models\AppSetting;
use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use App\Models\SizeColor;
use App\Support\ProductImageResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MetaCatalogService
{
    public function validateConfig(): void
    {
        $missing = collect([
            'META_CATALOG_ID' => config('meta_commerce.catalog_id'),
            'WHATSAPP_ACCESS_TOKEN' => config('meta_commerce.access_token'),
            'WHATSAPP_API_VERSION' => config('meta_commerce.api_version'),
        ])->filter(fn ($value) => blank($value))->keys();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Missing Meta Catalog configuration: '.$missing->implode(', '));
        }
    }

    public function buildProductPayload(Product $product, ?SizeColor $variant = null): array
    {
        $product->loadMissing(['category', 'normalImages', 'viewImages', 'image3d', 'sizes.colorSizes']);
        $price = $variant
            ? (float) ($variant->normailPrice ?? 0)
            : (float) ($product->normailPrice ?: $product->price ?: 0);
        $quantity = $variant ? (int) $variant->stock : (int) $product->stock;
        $image = $variant?->image_url ?: ProductImageResolver::preferredUrl($product);
        $image = $this->normalizeImageUrl($image);

        $name = trim((string) ($product->nameAr ?: $product->nameEng));
        if ($variant) {
            $size = trim((string) $variant->size?->size);
            $color = trim((string) ($variant->colorAr ?: $variant->colorEn));
            $suffix = trim(implode(' - ', array_filter([$size, $color])));
            $name .= $suffix !== '' ? ' - '.$suffix : '';
        }

        $description = trim((string) ($product->descriptionAr ?: $product->descriptionEng ?: $name));
        if (AppSetting::getBool(AppSetting::KEY_SHOW_QUANTITY_IN_META_CATALOG)) {
            $description = trim($description."\nالمتوفر: {$quantity}");
        }

        $errors = [];
        if ($name === '') $errors[] = 'اسم المنتج مطلوب.';
        if ($price <= 0) $errors[] = 'سعر المنتج يجب أن يكون أكبر من صفر.';
        if ($quantity < 0) $errors[] = 'كمية المخزون غير صالحة.';
        if (! $product->isShow) $errors[] = 'المنتج غير مفعّل للعرض.';
        if ($image === null) $errors[] = 'صورة عامة بصيغة HTTPS مطلوبة.';
        if ($errors !== []) throw new RuntimeException(implode(' ', $errors));

        return array_filter([
            'retailer_id' => $this->generateRetailerId($product, $variant),
            'name' => mb_substr($name, 0, 100),
            'description' => mb_substr($description, 0, 9999),
            'price' => $this->normalizePrice($price),
            'currency' => strtoupper((string) AppSetting::get(AppSetting::KEY_META_CATALOG_CURRENCY, 'ILS')),
            'availability' => $this->normalizeAvailability($quantity),
            'condition' => 'new',
            'image_url' => $image,
            'url' => rtrim((string) config('app.url'), '/').'/product/'.$product->id,
            'brand' => (string) AppSetting::get(AppSetting::KEY_META_CATALOG_DEFAULT_BRAND, 'Dr Bike'),
            'category' => $product->category?->nameAr ?: $product->category?->nameEng,
            'inventory' => max(0, $quantity),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function syncProduct(Product $product, ?SizeColor $variant = null, string $forcedAction = ''): array
    {
        $target = $variant ?: $product;
        $action = $forcedAction ?: ($target->meta_catalog_item_id ? 'update' : 'create');

        try {
            $this->validateConfig();
            $payload = $this->buildProductPayload($product, $variant);
            $target->forceFill([
                'meta_catalog_sync_status' => 'pending',
                'meta_catalog_retailer_id' => $payload['retailer_id'],
                'meta_catalog_payload' => $payload,
                'meta_catalog_last_error' => null,
            ])->saveQuietly();

            $response = $target->meta_catalog_item_id
                ? $this->updateCatalogItem($target->meta_catalog_item_id, $payload)
                : $this->createCatalogItem($payload);

            $itemId = (string) ($response['id'] ?? $target->meta_catalog_item_id ?? '');
            if ($itemId === '') throw new RuntimeException('Meta did not return a catalog item id.');

            $target->forceFill([
                'meta_catalog_item_id' => $itemId,
                'meta_catalog_retailer_id' => $payload['retailer_id'],
                'meta_catalog_sync_status' => 'synced',
                'meta_catalog_last_synced_at' => now(),
                'meta_catalog_last_error' => null,
                'meta_catalog_payload' => $payload,
            ])->saveQuietly();
            $this->log($product, $variant, $action, 'success', $payload, $response);

            return ['item' => $target->fresh(), 'response' => $response];
        } catch (Throwable $e) {
            $target->forceFill([
                'meta_catalog_sync_status' => 'failed',
                'meta_catalog_last_error' => $e->getMessage(),
            ])->saveQuietly();
            $this->log($product, $variant, $action, 'failed', $target->meta_catalog_payload, null, $e->getMessage());
            throw $e;
        }
    }

    public function createCatalogItem(array $payload): array
    {
        return $this->request('post', '/'.config('meta_commerce.catalog_id').'/products', $payload);
    }

    public function updateCatalogItem(string $metaCatalogItemId, array $payload): array
    {
        return $this->request('post', '/'.$metaCatalogItemId, $payload);
    }

    public function deleteCatalogItem(string $metaCatalogItemId): array
    {
        return $this->request('delete', '/'.$metaCatalogItemId);
    }

    public function disable(Product $product, ?SizeColor $variant = null): array
    {
        $target = $variant ?: $product;
        if (! $target->meta_catalog_item_id) {
            $target->forceFill(['meta_catalog_sync_status' => 'disabled'])->saveQuietly();
            return ['success' => true];
        }

        try {
            $response = $this->deleteCatalogItem($target->meta_catalog_item_id);
            $this->log($product, $variant, 'disable', 'success', null, $response);
            $target->forceFill([
                'meta_catalog_item_id' => null,
                'meta_catalog_sync_status' => 'disabled',
                'meta_catalog_last_error' => null,
            ])->saveQuietly();
            return $response;
        } catch (Throwable $e) {
            $target->forceFill(['meta_catalog_sync_status' => 'failed', 'meta_catalog_last_error' => $e->getMessage()])->saveQuietly();
            $this->log($product, $variant, 'disable', 'failed', null, null, $e->getMessage());
            throw $e;
        }
    }

    public function normalizeImageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || strtolower($path) === 'no image') return null;
        if (! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://')) {
            $path = rtrim((string) config('app.url'), '/').'/'.ltrim(str_replace('\\', '/', $path), '/');
        }
        return str_starts_with($path, 'https://') ? $path : null;
    }

    public function normalizePrice(float|int|string $price): int
    {
        return (int) round(((float) $price) * 100);
    }

    public function normalizeAvailability(int $quantity): string
    {
        return $quantity > 0 ? 'in stock' : 'out of stock';
    }

    public function generateRetailerId(Product $product, ?SizeColor $variant = null): string
    {
        return $variant ? 'DRBIKE-V-'.$variant->id : 'DRBIKE-P-'.$product->id;
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $this->validateConfig();
        $client = Http::withToken((string) config('meta_commerce.access_token'))
            ->acceptJson()
            ->timeout((int) config('meta_commerce.timeout', 20));
        $url = 'https://graph.facebook.com/'.config('meta_commerce.api_version').$path;
        /** @var Response $response */
        $response = $client->{$method}($url, $payload);
        if ($response->failed()) {
            $message = data_get($response->json(), 'error.message') ?: 'Meta Catalog request failed (HTTP '.$response->status().').';
            throw new RuntimeException($message);
        }
        return $response->json() ?: ['success' => true];
    }

    private function log(
        Product $product,
        ?SizeColor $variant,
        string $action,
        string $status,
        ?array $request,
        ?array $response,
        ?string $error = null
    ): void {
        MetaCatalogSyncLog::query()->create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'action' => $action,
            'status' => $status,
            'meta_catalog_item_id' => $variant?->meta_catalog_item_id ?: $product->meta_catalog_item_id,
            'retailer_id' => $variant?->meta_catalog_retailer_id ?: $product->meta_catalog_retailer_id,
            'request_payload' => $request,
            'response_payload' => $response,
            'error_message' => $error,
        ]);
    }
}
