<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStoreMediaPresentation;
use App\Models\User;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MediaPresentationService
{
    private const SOURCE_TABLES = ['view_image' => 'view_image_products', 'normal_image' => 'normal_image_products', 'image3d' => 'image3d_products'];

    private const METADATA_KEYS = ['media_type', 'mime_type', 'alt_translations', 'poster_path', 'is_360', 'role'];

    public function initialize(OnlineStoreListing $listing, ?User $actor = null): OnlineStoreListing
    {
        return DB::transaction(function () use ($listing, $actor) {
            $locked = OnlineStoreListing::query()->with('product')->lockForUpdate()->findOrFail($listing->getKey());
            if (! $locked->product || $locked->product->trashed()) {
                return $locked->fresh(['product', 'mediaPresentations']);
            }
            $rows = collect();
            foreach (self::SOURCE_TABLES as $type => $table) {
                $rows = $rows->concat(DB::table($table)->where('itemId', $locked->product_id)
                    ->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->orderBy('id')->get(['id'])
                    ->map(fn ($row) => ['source_type' => $type, 'source_id' => $row->id, 'store_media_path' => null, 'media_metadata' => null]));
            }
            $rows = $rows->concat(DB::table('size_colors')
                ->join('sizes', 'sizes.id', '=', 'size_colors.sizeId')
                ->where('sizes.itemId', $locked->product_id)
                ->whereNotNull('size_colors.image_url')->where('size_colors.image_url', '<>', '')
                ->orderBy('sizes.id')->orderBy('size_colors.id')
                ->get(['size_colors.id'])
                ->map(fn ($row) => [
                    'source_type' => 'variant', 'source_id' => $row->id, 'store_media_path' => null,
                    'media_metadata' => ['media_type' => 'image', 'role' => 'variant'],
                ]));
            if (filled($locked->product->videoUrl)) {
                $rows->push([
                    'source_type' => 'store_specific', 'source_id' => null,
                    'store_media_path' => trim((string) $locked->product->videoUrl),
                    'media_metadata' => ['media_type' => 'video', 'role' => 'video'],
                ]);
            }

            $existing = $locked->mediaPresentations()->get();
            $identities = $existing->mapWithKeys(fn ($item) => [
                $item->source_type.':'.($item->source_id ?? trim((string) $item->store_media_path)) => true,
            ]);
            $nextOrder = ((int) $existing->max('sort_order')) + ($existing->isEmpty() ? 0 : 1);
            $hasMain = $existing->contains(fn ($item) => $item->is_main);
            foreach ($rows->values() as $row) {
                $identity = $row['source_type'].':'.($row['source_id'] ?? trim((string) $row['store_media_path']));
                if ($identities->has($identity)) {
                    continue;
                }
                $presentation = $locked->mediaPresentations()->make([
                    ...$row, 'is_main' => ! $hasMain, 'is_visible' => true, 'sort_order' => $nextOrder++,
                ]);
                $presentation->created_by = $actor?->getKey();
                $presentation->updated_by = $actor?->getKey();
                $presentation->save();
                $hasMain = true;
                $identities->put($identity, true);
            }

            return $locked->fresh(['product', 'mediaPresentations']);
        });
    }

    public function replace(OnlineStoreListing $listing, array $items, User $actor): OnlineStoreListing
    {
        return DB::transaction(function () use ($listing, $items, $actor) {
            $locked = OnlineStoreListing::query()->with('product')->lockForUpdate()->findOrFail($listing->getKey());
            if (! $locked->product || $locked->product->trashed()) {
                throw ValidationException::withMessages(['listing' => ['The listing Product is unavailable.']]);
            }
            $validated = $this->validateItems($locked, $items);
            $locked->mediaPresentations()->delete();
            foreach ($validated as $item) {
                $presentation = $locked->mediaPresentations()->make($item);
                $presentation->created_by = $actor->getKey();
                $presentation->updated_by = $actor->getKey();
                $presentation->save();
            }

            return $locked->fresh(['product', 'mediaPresentations']);
        });
    }

    public function resolved(OnlineStoreListing $listing): array
    {
        if (! $listing->product || $listing->product->trashed()) {
            return [];
        }
        $resolved = $listing->mediaPresentations()->where('is_visible', true)->get()->map(function ($item) use ($listing) {
            $url = $this->resolvePath($listing, $item);
            if ($url === null) {
                return null;
            }

            return ['id' => $item->id, 'source_type' => $item->source_type, 'source_id' => $item->source_id,
                'path' => $url, 'media_metadata' => $item->media_metadata, 'is_main' => $item->is_main,
                'is_visible' => $item->is_visible, 'sort_order' => $item->sort_order];
        })->filter()->values();

        return $resolved->where('is_main', true)->count() === 1 ? $resolved->all() : [];
    }

    public function adminPresentation(OnlineStoreListing $listing): array
    {
        return $listing->mediaPresentations()->get()->map(function ($item) use ($listing) {
            $path = $this->resolvePath($listing, $item, false);

            return ['id' => $item->id, 'source_type' => $item->source_type, 'source_id' => $item->source_id,
                'store_media_path' => $item->store_media_path, 'resolved_path' => $path,
                'source_available' => $path !== null, 'media_metadata' => $item->media_metadata,
                'is_main' => $item->is_main, 'is_visible' => $item->is_visible, 'sort_order' => $item->sort_order];
        })->all();
    }

    private function validateItems(OnlineStoreListing $listing, array $items): array
    {
        $seen = [];
        $mainCount = 0;
        $visibleCount = 0;
        $validated = [];
        foreach (array_values($items) as $order => $item) {
            $type = $item['source_type'];
            if (! in_array($type, OnlineStoreValues::MEDIA_SOURCE_TYPES, true)) {
                throw ValidationException::withMessages(['items' => ['Unsupported media source type.']]);
            }
            $sourceId = isset($item['source_id']) ? (int) $item['source_id'] : null;
            $path = isset($item['store_media_path']) ? trim((string) $item['store_media_path']) : null;
            if ($type === 'store_specific') {
                if ($sourceId !== null || $path === null || $path === '') {
                    throw ValidationException::withMessages(['items' => ['Store-specific media requires only store_media_path.']]);
                }
                $identity = $type.':'.$path;
            } else {
                if (! $sourceId || ($path !== null && $path !== '') || ! $this->sourceBelongsToProduct($type, $sourceId, (int) $listing->product_id)) {
                    throw ValidationException::withMessages(['items' => ['Referenced media must belong to the listing Product and use only source_id.']]);
                }
                $identity = $type.':'.$sourceId;
            }
            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['items' => ['Media presentation entries must be unique.']]);
            }
            $seen[$identity] = true;
            $metadata = $item['media_metadata'] ?? null;
            if (is_array($metadata) && array_diff(array_keys($metadata), self::METADATA_KEYS)) {
                throw ValidationException::withMessages(['items' => ['Unsupported media metadata keys.']]);
            }
            $allowedMetadata = in_array($type, ['view_image', 'image3d', 'store_specific'], true)
                ? self::METADATA_KEYS : ['mime_type', 'alt_translations', 'role'];
            if (is_array($metadata) && array_diff(array_keys($metadata), $allowedMetadata)) {
                throw ValidationException::withMessages(['items' => ['The source capability cannot represent the supplied metadata.']]);
            }
            $visible = (bool) ($item['is_visible'] ?? true);
            $main = (bool) ($item['is_main'] ?? false);
            if ($main && ! $visible) {
                throw ValidationException::withMessages(['items' => ['The main media must be visible.']]);
            }
            $visibleCount += $visible ? 1 : 0;
            $mainCount += $main ? 1 : 0;
            $validated[] = ['source_type' => $type, 'source_id' => $sourceId, 'store_media_path' => $path,
                'media_metadata' => $metadata, 'is_main' => $main, 'is_visible' => $visible, 'sort_order' => $order];
        }
        if ($items !== [] && ($visibleCount < 1 || $mainCount !== 1)) {
            throw ValidationException::withMessages(['items' => ['A non-empty presentation requires exactly one visible main item.']]);
        }

        return $validated;
    }

    private function sourceBelongsToProduct(string $type, int $sourceId, int $productId): bool
    {
        if (isset(self::SOURCE_TABLES[$type])) {
            return DB::table(self::SOURCE_TABLES[$type])->where('id', $sourceId)->where('itemId', $productId)->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->exists();
        }
        if ($type === 'variant') {
            return DB::table('size_colors')->join('sizes', 'sizes.id', '=', 'size_colors.sizeId')->where('size_colors.id', $sourceId)->where('sizes.itemId', $productId)->whereNotNull('size_colors.image_url')->where('size_colors.image_url', '<>', '')->exists();
        }

        return false;
    }

    private function resolvePath(OnlineStoreListing $listing, OnlineStoreMediaPresentation $item, bool $visibleOnly = true): ?string
    {
        if ($visibleOnly && ! $item->is_visible) {
            return null;
        }
        if ($item->source_type === 'store_specific') {
            return filled($item->store_media_path) ? $item->store_media_path : null;
        }
        if (isset(self::SOURCE_TABLES[$item->source_type])) {
            return DB::table(self::SOURCE_TABLES[$item->source_type])->where('id', $item->source_id)->where('itemId', $listing->product_id)->value('imageUrl') ?: null;
        }
        if ($item->source_type === 'variant') {
            return DB::table('size_colors')->join('sizes', 'sizes.id', '=', 'size_colors.sizeId')->where('size_colors.id', $item->source_id)->where('sizes.itemId', $listing->product_id)->value('size_colors.image_url') ?: null;
        }

        return null;
    }
}
