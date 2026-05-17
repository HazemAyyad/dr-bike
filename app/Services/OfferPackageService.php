<?php

namespace App\Services;

use App\Models\Closeout;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Support\ApiImageUrl;
use Illuminate\Http\UploadedFile;
class OfferPackageService
{
    private const IMAGE_DIR = 'Images/OfferPackages';

    public function imagePublicPath(?string $imagePath): string
    {
        if ($imagePath === null || $imagePath === '') {
            return 'no image';
        }

        return ApiImageUrl::normalize(self::IMAGE_DIR.'/'.$imagePath);
    }

    public function availableQuantity(OfferPackage $package): int
    {
        $package->loadMissing(['items.product']);

        if ($package->items->isEmpty()) {
            return 0;
        }

        $min = PHP_INT_MAX;

        foreach ($package->items as $item) {
            $stock = (float) ($item->product->stock ?? 0);
            $perPackage = max(1, (int) $item->quantity);
            $min = min($min, (int) floor($stock / $perPackage));
        }

        return $min === PHP_INT_MAX ? 0 : max(0, $min);
    }

    public function needsAdjustment(OfferPackage $package): bool
    {
        return $this->availableQuantity($package) < 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPackage(OfferPackage $package, bool $withItems = true): array
    {
        $available = $this->availableQuantity($package);

        $unitPrice = (float) $package->price;
        $packageQty = max(1, (int) ($package->package_quantity ?? 1));
        $package->loadMissing(['items.product']);
        $partsTotal = $package->items->sum(function ($item) {
            $productPrice = (float) ($item->product->normailPrice ?? 0);

            return $productPrice * max(1, (int) $item->quantity);
        });

        $data = [
            'id' => $package->id,
            'name' => $package->name,
            'price' => $unitPrice,
            'package_quantity' => $packageQty,
            'parts_total_price' => round((float) $partsTotal, 2),
            'effective_price' => round((float) $partsTotal, 2),
            'image' => $this->imagePublicPath($package->image_path),
            'is_active' => (bool) $package->is_active,
            'available_quantity' => $available,
            'needs_adjustment' => $available < 1,
        ];

        if ($withItems) {
            $package->loadMissing(['items.product.normalImages', 'items.product.viewImages']);
            $data['items'] = $package->items->map(function ($item) {
                $product = $item->product;
                $image = $product?->viewImages->first() ?? $product?->normalImages->first();

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $product?->nameAr ?? '-',
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) ($product?->normailPrice ?? 0),
                    'stock' => (float) ($product?->stock ?? 0),
                    'product_image' => $image
                        ? ApiImageUrl::normalize($image->imageUrl)
                        : 'no image',
                ];
            })->values()->all();
        }

        return $data;
    }

    public function storeUploadedImage(UploadedFile $file): string
    {
        $dir = public_path(self::IMAGE_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $file->move($dir, $name);

        return $name;
    }

    public function deleteImageFile(?string $imagePath): void
    {
        if ($imagePath === null || $imagePath === '') {
            return;
        }

        $full = public_path(self::IMAGE_DIR.'/'.$imagePath);
        if (is_file($full)) {
            unlink($full);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function syncItems(OfferPackage $package, array $items): void
    {
        $package->items()->delete();

        foreach ($items as $row) {
            $package->items()->create([
                'product_id' => (int) $row['product_id'],
                'quantity' => max(1, (int) $row['quantity']),
            ]);
        }
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function validateStockForSale(OfferPackage $package, int $packagesSold): array
    {
        $package->loadMissing(['items.product']);

        if ($packagesSold < 1) {
            return ['ok' => false, 'message' => __('messages.cant_sale')];
        }

        foreach ($package->items as $item) {
            $needed = (int) $item->quantity * $packagesSold;
            $stock = (float) ($item->product->stock ?? 0);

            if ($stock <= 0 || $needed > $stock) {
                return ['ok' => false, 'message' => __('messages.cant_sale')];
            }
        }

        return ['ok' => true];
    }

    public function deductStockForPackageSale(OfferPackage $package, int $packagesSold): void
    {
        $package->loadMissing('items.product');

        foreach ($package->items as $item) {
            $qty = (int) $item->quantity * $packagesSold;
            $product = Product::lockForUpdate()->find($item->product_id);

            if (! $product instanceof Product) {
                continue;
            }

            $product->stock = max(0, (float) $product->stock - $qty);
            $product->save();

            if ((float) $product->stock === 0.0) {
                $closeout = Closeout::where('product_id', $product->id)->first();
                if ($closeout) {
                    $closeout->update(['status' => 'archived']);
                }
            }
        }
    }

    public function restoreStockForPackageSale(OfferPackage $package, int $packagesSold): void
    {
        $package->loadMissing('items');

        foreach ($package->items as $item) {
            $qty = (int) $item->quantity * $packagesSold;
            $productId = (int) $item->product_id;

            Product::withTrashed()
                ->where('id', $productId)
                ->increment('stock', $qty);

            $product = Product::withTrashed()->find($productId);

            if ($product && (float) $product->stock > 0) {
                $closeout = Closeout::where('product_id', $productId)->first();
                if ($closeout && $closeout->status === 'archived') {
                    $closeout->update(['status' => 'ongoing']);
                }
            }
        }
    }
}
