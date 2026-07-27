<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockImageExport;
use App\Support\ApiImageUrl;
use App\Support\ProductImageResolver;
use App\Support\ProductSearchFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class StockImagesZipExportService
{
    public function buildFromRequest(Request $request): string
    {
        return $this->buildZip($this->filtersFromRequest($request));
    }

    public function buildForExport(StockImageExport $export): void
    {
        $export->forceFill([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
            'source_summary' => $this->initialSourceSummary(),
        ])->save();

        try {
            $path = $this->buildZip(
                is_array($export->filters) ? $export->filters : [],
                $export,
            );

            clearstatcache(true, $path);
            $export->forceFill([
                'status' => 'completed',
                'file_path' => $path,
                'file_name' => basename($path),
                'file_size' => is_file($path) ? (int) filesize($path) : 0,
                'completed_at' => now(),
            ])->save();

            try {
                app(AdminNotificationService::class)->notifyStockImagesExportReady($export->fresh() ?: $export);
            } catch (\Throwable $notifyError) {
                Log::warning('stock_images_export.notification_failed', [
                    'export_id' => $export->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $export->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $e;
        }
    }

    public function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'search' => $request->input('search'),
            'category_id' => $request->input('category_id'),
            'sub_category_id' => $request->input('sub_category_id', $request->input('subcategory_id')),
            'tag_id' => $request->input('tag_id'),
            'store_section_id' => $request->input('store_section_id'),
            'cost_price_status' => $request->input('cost_price_status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort_by' => $request->input('sort_by'),
            'sort_direction' => $request->input('sort_direction'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function applyFilters($query, array $filters, bool $admin = true): void
    {
        ProductSearchFilter::apply($query, $filters['search'] ?? null);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['sub_category_id'])) {
            $query->whereHas('subCategories', function ($q) use ($filters) {
                $q->where('sub_category_id', (int) $filters['sub_category_id']);
            });
        }

        if (! empty($filters['tag_id'])) {
            $query->whereHas('tags', function ($q) use ($filters) {
                $q->where('product_tags.id', (int) $filters['tag_id']);
            });
        }

        if (array_key_exists('store_section_id', $filters)) {
            $storeSectionFilter = $this->parseStoreSectionFilter($filters['store_section_id']);
            if ($storeSectionFilter['include_none'] || $storeSectionFilter['ids'] !== []) {
                $query->where(function ($q) use ($storeSectionFilter) {
                    if ($storeSectionFilter['ids'] !== []) {
                        $q->whereIn('store_section_id', $storeSectionFilter['ids']);
                    }
                    if ($storeSectionFilter['include_none']) {
                        $method = $storeSectionFilter['ids'] !== [] ? 'orWhereNull' : 'whereNull';
                        $q->{$method}('store_section_id');
                    }
                });
            }
        }

        if ($admin && ! empty($filters['cost_price_status'])) {
            if ($filters['cost_price_status'] === 'with') {
                $query->whereHas('purchasePrices', fn ($q) => $q->where('price', '>', 0));
            } elseif ($filters['cost_price_status'] === 'without') {
                $query->whereDoesntHave('purchasePrices', fn ($q) => $q->where('price', '>', 0));
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    private function buildZip(array $filters, ?StockImageExport $export = null): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('خدمة ZIP غير مفعلة على السيرفر');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $dir = storage_path('app/stock-image-exports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fileName = 'doctor-bike-stock-images-'.now()->format('Y-m-d-H-i-s').'.zip';
        $zipPath = $dir.'/'.$fileName;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('تعذر إنشاء ملف الصور المضغوط');
        }

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        [$sortColumn, $sortDirection] = $this->sort($filters);

        if ($export !== null) {
            $countQuery = Product::query();
            $this->applyFilters($countQuery, $filters);
            $export->forceFill(['total_products' => (int) $countQuery->count()])->save();
        }

        $addedFiles = [];
        $addedImages = 0;
        $missingImages = 0;
        $processed = 0;
        $sourceSummary = $this->initialSourceSummary();

        $query
            ->orderBy($sortColumn, $sortDirection)
            ->chunk(100, function ($products) use ($zip, &$addedFiles, &$addedImages, &$missingImages, &$processed, &$sourceSummary, $export) {
                foreach ($products as $product) {
                    foreach ($this->productImageRows($product) as $row) {
                        $image = $this->imageBytes($row['url']);
                        if ($image === null) {
                            $missingImages++;
                            $this->recordSourceSummary($sourceSummary, $row, false, null);
                            continue;
                        }

                        $zipName = $this->zipImageName(
                            $product,
                            $row['kind'],
                            $row['index'],
                            $image['extension'],
                            $addedFiles,
                        );

                        if ($zip->addFromString($zipName, $image['bytes'])) {
                            $addedImages++;
                            $this->recordSourceSummary($sourceSummary, $row, true, $image['source']);
                        }
                    }

                    $processed++;
                    if ($export !== null && ($processed % 25 === 0)) {
                        $export->forceFill([
                            'processed_products' => $processed,
                            'images_added' => $addedImages,
                            'source_summary' => $this->finalizeSourceSummary($sourceSummary, $missingImages),
                        ])->save();
                    }
                }
            });

        if ($addedImages === 0) {
            $zip->addFromString('README.txt', "No product images were found.\n");
        }

        $zip->close();

        if ($export !== null) {
            $export->forceFill([
                'processed_products' => $processed,
                'images_added' => $addedImages,
                'source_summary' => $this->finalizeSourceSummary($sourceSummary, $missingImages),
            ])->save();
        }

        return $zipPath;
    }

    private function baseQuery()
    {
        return Product::query()
            ->with([
                'viewImages:id,itemId,imageUrl',
                'normalImages:id,itemId,imageUrl',
                'image3d:id,itemId,imageUrl',
                'sizes:id,itemId,size',
                'sizes.colorSizes:id,sizeId,colorAr,colorEn,colorAbbr,image_url',
            ])
            ->select(['id', 'product_code', 'nameAr', 'created_at', 'updated_at']);
    }

    private function sort(array $filters): array
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            match ($sortBy) {
                'name' => 'nameAr',
                'updated_at' => 'updated_at',
                default => 'created_at',
            },
            $sortDirection,
        ];
    }

    private function parseStoreSectionFilter($value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        $includeNone = false;

        foreach ($raw as $item) {
            $token = trim((string) $item);
            if ($token === '') {
                continue;
            }
            if (in_array($token, ['none', 'null', '0'], true)) {
                $includeNone = true;
                continue;
            }
            if (ctype_digit($token)) {
                $ids[] = (int) $token;
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'include_none' => $includeNone,
        ];
    }

    private function productImageRows(Product $product): array
    {
        $rows = [];
        foreach (['view' => $product->viewImages, 'normal' => $product->normalImages, '3d' => $product->image3d] as $kind => $images) {
            $index = 1;
            foreach ($images as $image) {
                $url = ProductImageResolver::urlFromRecord($image);
                if (ProductImageResolver::isValidUrl($url)) {
                    $rows[] = [
                        'kind' => $kind,
                        'index' => $index,
                        'url' => $url,
                        'table' => $this->imageKindTable($kind),
                        'field' => 'imageUrl',
                    ];
                    $index++;
                }
            }
        }

        $index = 1;
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $color) {
                $url = (string) ($color->image_url ?? '');
                if (ProductImageResolver::isValidUrl($url)) {
                    $rows[] = [
                        'kind' => 'variant',
                        'index' => $index,
                        'url' => $url,
                        'table' => 'size_colors',
                        'field' => 'image_url',
                    ];
                    $index++;
                }
            }
        }

        return $rows;
    }

    private function imageBytes(string $url): ?array
    {
        $localPath = $this->localImagePath($url);
        if ($localPath !== null) {
            $bytes = @file_get_contents($localPath);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            return [
                'bytes' => $bytes,
                'extension' => $this->extensionFromPath($localPath),
                'source' => $this->sourceLabelForLocalPath($localPath),
            ];
        }

        $downloadUrl = $this->downloadUrl($url);
        if ($downloadUrl === null) {
            return null;
        }

        try {
            $response = Http::timeout(25)->get($downloadUrl);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'extension' => $this->extensionFromContentType((string) $response->header('Content-Type'), $downloadUrl),
            'source' => $this->sourceLabelForUrl($downloadUrl),
        ];
    }

    private function imageKindTable(string $kind): string
    {
        return match ($kind) {
            'view' => 'view_image_products',
            '3d' => 'image3d_products',
            default => 'normal_image_products',
        };
    }

    private function initialSourceSummary(): array
    {
        return [
            'sources' => [],
            'tables' => [
                'normal_image_products.imageUrl' => [
                    'label' => 'صور المنتج الأساسية',
                    'links_seen' => 0,
                    'images_added' => 0,
                    'missing_images' => 0,
                ],
                'view_image_products.imageUrl' => [
                    'label' => 'صور عرض المنتج',
                    'links_seen' => 0,
                    'images_added' => 0,
                    'missing_images' => 0,
                ],
                'image3d_products.imageUrl' => [
                    'label' => 'صور 3D',
                    'links_seen' => 0,
                    'images_added' => 0,
                    'missing_images' => 0,
                ],
                'size_colors.image_url' => [
                    'label' => 'صور الألوان والمقاسات',
                    'links_seen' => 0,
                    'images_added' => 0,
                    'missing_images' => 0,
                ],
            ],
            'links_seen' => 0,
            'images_added' => 0,
            'missing_images' => 0,
        ];
    }

    private function recordSourceSummary(array &$summary, array $row, bool $added, ?string $source): void
    {
        $tableKey = ($row['table'] ?? 'unknown').'.'.($row['field'] ?? 'url');
        if (! isset($summary['tables'][$tableKey])) {
            $summary['tables'][$tableKey] = [
                'label' => $tableKey,
                'links_seen' => 0,
                'images_added' => 0,
                'missing_images' => 0,
            ];
        }

        $summary['links_seen']++;
        $summary['tables'][$tableKey]['links_seen']++;

        if ($added) {
            $summary['images_added']++;
            $summary['tables'][$tableKey]['images_added']++;
            $sourceKey = $source ?: 'unknown';
            if (! isset($summary['sources'][$sourceKey])) {
                $summary['sources'][$sourceKey] = [
                    'label' => $sourceKey,
                    'images_added' => 0,
                ];
            }
            $summary['sources'][$sourceKey]['images_added']++;
        } else {
            $summary['missing_images']++;
            $summary['tables'][$tableKey]['missing_images']++;
        }
    }

    private function finalizeSourceSummary(array $summary, int $missingImages): array
    {
        $summary['missing_images'] = $missingImages;
        $summary['sources'] = array_values($summary['sources']);
        $summary['tables'] = array_values($summary['tables']);

        return $summary;
    }

    private function sourceLabelForLocalPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $public = str_replace('\\', '/', public_path());
        $storagePublic = str_replace('\\', '/', storage_path('app/public'));

        if (str_starts_with($normalized, $storagePublic.'/product-uploads/')) {
            return 'storage/app/public/product-uploads';
        }
        if (str_starts_with($normalized, $public.'/SizeColorImages/')) {
            return 'public/SizeColorImages';
        }
        if (str_starts_with($normalized, $public.'/Images/Items/')) {
            return 'public/Images/Items';
        }
        if (str_starts_with($normalized, $public.'/storage/product-uploads/')) {
            return 'public/storage/product-uploads';
        }

        return 'local: '.$this->relativePathForDisplay($normalized);
    }

    private function sourceLabelForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $host
            ? 'remote: '.$host.($path !== '' ? '/'.dirname($path) : '')
            : 'remote';
    }

    private function relativePathForDisplay(string $path): string
    {
        $base = str_replace('\\', '/', base_path());
        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private function localImagePath(?string $url): ?string
    {
        if (! ProductImageResolver::isValidUrl($url)) {
            return null;
        }

        $normalized = ApiImageUrl::normalize($url);
        $path = $normalized;
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            $path = (string) parse_url($normalized, PHP_URL_PATH);
        }

        $relative = ltrim(rawurldecode(str_replace('\\', '/', $path)), '/');
        $withoutPublic = preg_replace('#^public/#', '', $relative) ?? $relative;

        foreach (array_unique([public_path($relative), public_path($withoutPublic)]) as $candidate) {
            if (is_string($candidate) && is_file($candidate) && @getimagesize($candidate) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    private function downloadUrl(string $url): ?string
    {
        $normalized = ApiImageUrl::normalize($url);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $relative = ltrim(str_replace('\\', '/', $normalized), '/');
        if ($relative === '') {
            return null;
        }

        $base = rtrim((string) config('store.domain'), '/');
        if ($base === '' && preg_match('#^Images/Items/[^/?]+$#i', $relative)) {
            $base = 'https://mjsall-001-site1.jtempurl.com';
        }

        return $base !== '' ? $base.'/'.$relative : null;
    }

    private function zipImageName(Product $product, string $kind, int $index, string $extension, array &$used): string
    {
        $code = trim((string) ($product->product_code ?? ''));
        $folder = 'product_'.$product->id.'_'.($code !== '' ? $this->zipSafeName($code) : 'no-code');
        $base = $this->zipSafeName($kind).'_'.$index;
        $name = $folder.'/'.$base.'.'.$extension;
        $counter = 2;

        while (isset($used[$name])) {
            $name = $folder.'/'.$base.'_'.$counter.'.'.$extension;
            $counter++;
        }

        $used[$name] = true;

        return $name;
    }

    private function zipSafeName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $safe = trim($safe, '-_.');

        return $safe !== '' ? $safe : 'item';
    }

    private function extensionFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }

    private function extensionFromContentType(string $contentType, string $url): string
    {
        $contentType = strtolower(strtok($contentType, ';') ?: '');

        return match ($contentType) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            default => $this->extensionFromPath((string) parse_url($url, PHP_URL_PATH)),
        };
    }
}
