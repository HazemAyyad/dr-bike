<?php

namespace App\Services;

use ArPHP\I18N\Arabic;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderMedia;
use App\Models\ShiplyCity;
use App\Models\ShiplyVillage;
use App\Support\SalesOrderMediaCategory;
use App\Support\ShiplyPhoneFormatter;
use App\Support\ShiplySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ShiplyService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function addressOptions(?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();

        return ShiplyCity::query()
            ->where('mode', $mode)
            ->whereNull('deleted_at_remote')
            ->orderBy('name')
            ->get()
            ->map(function (ShiplyCity $city) use ($mode) {
                $villages = ShiplyVillage::query()
                    ->where('mode', $mode)
                    ->where('shiply_city_id', $city->shiply_id)
                    ->whereNull('deleted_at_remote')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (ShiplyVillage $village) => [
                        'id' => $village->shiply_id,
                        'name' => $village->name,
                        'is_closed' => (bool) $village->is_closed,
                        'note' => $village->note,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $city->shiply_id,
                    'name' => $city->name,
                    'villages' => $villages,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{synced_cities: int, synced_villages: int}
     */
    public function syncAddresses(?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $cities = $this->request('POST', '/address/getCitiesAndVillages', [], $mode);

        if (! is_array($cities)) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_sync_failed')],
            ]);
        }

        $cityCount = 0;
        $villageCount = 0;

        DB::transaction(function () use ($cities, $mode, &$cityCount, &$villageCount) {
            foreach ($cities as $cityRow) {
                if (! is_array($cityRow) || empty($cityRow['id'])) {
                    continue;
                }

                ShiplyCity::query()->updateOrCreate(
                    ['shiply_id' => (int) $cityRow['id'], 'mode' => $mode],
                    [
                        'name' => (string) ($cityRow['name'] ?? ''),
                        'deleted_at_remote' => ! empty($cityRow['deleted_at']) ? now() : null,
                    ]
                );
                $cityCount++;

                foreach ($cityRow['villages'] ?? [] as $villageRow) {
                    if (! is_array($villageRow) || empty($villageRow['id'])) {
                        continue;
                    }

                    ShiplyVillage::query()->updateOrCreate(
                        ['shiply_id' => (int) $villageRow['id'], 'mode' => $mode],
                        [
                            'shiply_city_id' => (int) ($villageRow['city_id'] ?? $cityRow['id']),
                            'name' => (string) ($villageRow['name'] ?? ''),
                            'region_id' => isset($villageRow['region_id']) ? (int) $villageRow['region_id'] : null,
                            'region_type' => isset($villageRow['region_type']) ? (int) $villageRow['region_type'] : null,
                            'note' => $villageRow['note'] ?? null,
                            'is_closed' => (int) ($villageRow['is_closed'] ?? 0) === 1,
                            'deleted_at_remote' => ! empty($villageRow['deleted_at']) ? now() : null,
                        ]
                    );
                    $villageCount++;
                }
            }
        });

        return [
            'synced_cities' => $cityCount,
            'synced_villages' => $villageCount,
        ];
    }

    /**
     * @return array{parcel_code: string, qr_code: ?string}
     */
    public function createAndSubmitParcel(SalesOrder $order, ?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $this->assertOrderReadyForShiply($order, $mode);

        $payload = $this->buildCreateParcelPayload($order);
        $create = $this->request('POST', '/parcels/create', $payload, $mode);

        $parcelCode = (string) ($create['parcel_code'] ?? '');
        if ($parcelCode === '') {
            $errors = $create['errors'] ?? $create['error'] ?? $create['message'] ?? null;
            Log::warning('shiply.create_parcel_failed', [
                'mode' => $mode,
                'response' => $create,
            ]);
            throw ValidationException::withMessages([
                'shiply' => [$this->formatShiplyError($errors, '')],
            ]);
        }

        try {
            $this->syncOrderContentsToParcel($order, $parcelCode, $mode);
        } catch (\Throwable $e) {
            Log::warning('shiply.sync_parcel_content_failed', [
                'parcel_code' => $parcelCode,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        $qrCode = null;
        try {
            $submit = $this->request('GET', '/parcels/assignQRCode/'.rawurlencode($parcelCode), [], $mode);
            if (is_array($submit) && ! empty($submit['success'])) {
                $qrCode = $submit['qr_code'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('shiply.assign_qr_failed', [
                'parcel_code' => $parcelCode,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'parcel_code' => $parcelCode,
            'qr_code' => is_string($qrCode) ? $qrCode : null,
        ];
    }

    public function cancelParcel(string $parcelCode, ?string $mode = null): void
    {
        if ($parcelCode === '') {
            return;
        }

        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('GET', '/parcels/cancel/'.rawurlencode($parcelCode), [], $mode);

        if (is_array($response) && array_key_exists('success', $response) && ! $response['success']) {
            Log::warning('shiply.cancel_parcel_failed', [
                'parcel_code' => $parcelCode,
                'response' => $response,
            ]);
        }
    }

    public function registerWebhook(?string $mode = null): bool
    {
        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('PUT', '/customer/webhookURL', [
            'webhook_url' => ShiplySettings::webhookUrl(),
        ], $mode);

        return is_array($response) && ! empty($response['success']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParcel(string $parcelCode, ?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('GET', '/parcels/'.rawurlencode($parcelCode), [], $mode);

        return is_array($response) ? $response : [];
    }

    /**
     * Fetch Shiply's printable parcel label as a PDF.
     */
    public function getParcelPdf(string $parcelCode, ?string $mode = null): string
    {
        $parcelCode = trim($parcelCode);
        if ($parcelCode === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_request_failed')],
            ]);
        }

        if (! ShiplySettings::isEnabled()) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_disabled')],
            ]);
        }

        $mode = $mode ?? ShiplySettings::mode();
        $apiKey = ShiplySettings::apiKey($mode);
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_api_key_missing')],
            ]);
        }

        $url = rtrim(ShiplySettings::baseUrl($mode), '/').'/parcels/getParcelAsPDF';
        $body = [
            'id' => $parcelCode,
            'Shiply_API_KEY' => $apiKey,
        ];

        try {
            // Shiply documents a GET body, while some of their deployments only
            // expose GET parameters to Laravel. Try the same query convention
            // used by the other working Shiply GET endpoints first.
            $client = Http::timeout((int) config('shiply.http_timeout', 30))
                ->accept('application/pdf');
            $response = $client->withOptions(['query' => $body])->get($url);

            if (! str_starts_with(ltrim($response->body()), '%PDF-')) {
                $response = $client
                    ->withBody((string) json_encode($body), 'application/json')
                    ->send('GET', $url);
            }
        } catch (ConnectionException $e) {
            Log::error('shiply.print_parcel_connection_failed', [
                'parcel_code' => $parcelCode,
                'mode' => $mode,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_connection_failed')],
            ]);
        } catch (RequestException $e) {
            Log::error('shiply.print_parcel_http_failed', [
                'parcel_code' => $parcelCode,
                'mode' => $mode,
                'status' => $e->response?->status(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [$this->formatHttpErrorMessage($e)],
            ]);
        }

        $content = ltrim($response->body());
        if (! $response->failed() && str_starts_with($content, '%PDF-')) {
            return $content;
        }

        if (! $response->failed()
            && preg_match('/^<!doctype\s+html|^<html/i', $content) === 1) {
            try {
                $printHtml = $this->prepareShiplyPrintHtml($content, $url);
                $pdf = Pdf::loadHTML($printHtml)
                    ->setPaper('a4')
                    ->setOption('isRemoteEnabled', true)
                    ->output();

                if (is_string($pdf) && str_starts_with($pdf, '%PDF-')) {
                    return $pdf;
                }
            } catch (\Throwable $e) {
                Log::error('shiply.print_parcel_html_conversion_failed', [
                    'parcel_code' => $parcelCode,
                    'mode' => $mode,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($response->failed() || ! str_starts_with($content, '%PDF-')) {
            $json = $response->json();
            Log::warning('shiply.print_parcel_failed', [
                'parcel_code' => $parcelCode,
                'mode' => $mode,
                'status' => $response->status(),
                'body' => is_array($json) ? $json : mb_substr($content, 0, 500),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [
                    $this->extractResponseErrorMessage(is_array($json) ? $json : null)
                        ?? __('messages.shiply_request_failed'),
                ],
            ]);
        }

        return $content;
    }

    private function prepareShiplyPrintHtml(string $html, string $sourceUrl): string
    {
        // Shiply embeds an SVG XML declaration/doctype in the middle of the
        // HTML document. DomPDF treats those as document-level declarations.
        $html = preg_replace('/<\?xml\b.*?\?>/is', '', $html) ?? $html;
        $html = preg_replace('/<!DOCTYPE\s+svg\b.*?>/is', '', $html) ?? $html;
        // Fix the unclosed QR table cell in Shiply's current template.
        $html = preg_replace(
            '/(<\/div>)\s*<td>\s*(<\/tr>)/i',
            '$1</td>$2',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<td\s+style="width:\s*80%;">(\s*<table\s+style="height:\s*100%;\s*width:\s*100%;">)/i',
            '<td class="shiplyDetailsColumn"><table style="width: 100%;">',
            $html,
            1
        ) ?? $html;

        $html = $this->inlineShiplyPrintImages($html, $sourceUrl);

        // Shiply's HTML leaves a top padding that pushes a small overflow onto
        // a second PDF page in DomPDF.
        $printCss = <<<'CSS'
<style>
@page { size: A4 portrait; margin: 0 !important; }
* { box-sizing: border-box; }
html, body {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    font-family: "DejaVu Sans", sans-serif !important;
}
body {
    height: auto !important;
    padding: 0 !important;
}
table {
    max-width: 100% !important;
    border-spacing: 1px !important;
}
.shiplyDetailsColumn {
    width: 72% !important;
    vertical-align: top !important;
}
.qrImg {
    width: 28% !important;
    vertical-align: top !important;
}
.qrContainer {
    width: 100% !important;
    overflow: hidden !important;
}
.qrContainer > img {
    width: 38mm !important;
    height: 38mm !important;
}
.qrContainer > svg {
    width: 100% !important;
    height: 12mm !important;
}
img { max-width: 100% !important; }
[dir="rtl"] {
    font-family: "DejaVu Sans", sans-serif !important;
    /* Arabic text is converted below to visual order for DomPDF. Applying
       RTL again here reverses mixed numbers and Latin names a second time. */
    direction: ltr !important;
    text-align: right !important;
}
</style>
CSS;
        $html = preg_replace('/<\/head>/i', $printCss.'</head>', $html, 1) ?? $html;

        // DomPDF does not implement Arabic bidi. Shape each complete text node
        // so punctuation, Western digits and Latin names stay with the Arabic
        // phrase in the correct visual order.
        $arabic = new Arabic();
        $html = preg_replace_callback(
            '/>([^<>]*\p{Arabic}[^<>]*)</us',
            function (array $matches) use ($arabic): string {
                $text = $matches[1];
                preg_match('/^\s*/u', $text, $leading);
                preg_match('/\s*$/u', $text, $trailing);
                $content = trim($text);

                if ($content === '') {
                    return $matches[0];
                }

                $visualText = $arabic->utf8Glyphs(
                    $content,
                    1000,
                    false,
                    false
                );

                // DomPDF applies its own bidi pass even after Arabic glyph
                // shaping and ignores CSS direction for mixed text. LRO/PDF
                // keeps the prepared visual order (phone, colon, label).
                return '>'.($leading[0] ?? '')
                    ."\u{202D}".$visualText."\u{202C}"
                    .($trailing[0] ?? '').'<';
            },
            $html
        ) ?? $html;

        return $html;
    }

    private function inlineShiplyPrintImages(string $html, string $sourceUrl): string
    {
        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*)(["\'])(.*?)\2/si',
            function (array $matches) use ($sourceUrl): string {
                $src = html_entity_decode(trim($matches[3]), ENT_QUOTES | ENT_HTML5);
                if ($src === '' || str_starts_with($src, 'data:')) {
                    return $matches[0];
                }

                $imageUrl = $this->resolveShiplyPrintAssetUrl($src, $sourceUrl);
                if ($imageUrl === null) {
                    return $matches[0];
                }

                try {
                    $response = Http::timeout((int) config('shiply.http_timeout', 30))
                        ->get($imageUrl);
                    if ($response->failed() || $response->body() === '') {
                        return $matches[0];
                    }

                    $mime = trim(explode(';', (string) $response->header('Content-Type'))[0]);
                    if (! str_starts_with($mime, 'image/')) {
                        $mime = 'image/png';
                    }

                    $dataUri = 'data:'.$mime.';base64,'.base64_encode($response->body());

                    return $matches[1].$matches[2].$dataUri.$matches[2];
                } catch (\Throwable $e) {
                    Log::warning('shiply.print_parcel_image_failed', [
                        'url_host' => parse_url($imageUrl, PHP_URL_HOST),
                        'message' => $e->getMessage(),
                    ]);

                    return $matches[0];
                }
            },
            $html
        ) ?? $html;
    }

    private function resolveShiplyPrintAssetUrl(string $src, string $sourceUrl): ?string
    {
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        if (str_starts_with($src, '//')) {
            return $scheme.':'.$src;
        }

        if (str_starts_with($src, '/')) {
            return $scheme.'://'.$host.$src;
        }

        return rtrim(dirname($sourceUrl), '/').'/'.ltrim($src, '/');
    }

    /**
     * @return array{delivery_cost: float, extra_price: float, returned_extra_price: float}
     */
    public function calculateDeliveryCost(int $villageId, float $parcelPrice = 0, ?string $mode = null): array
    {
        $response = $this->request('POST', '/parcels/fees', [
            'village_id' => $villageId,
            'price' => max(0, $parcelPrice),
        ], $mode);

        return [
            'delivery_cost' => (float) ($response['delivery_cost'] ?? 0),
            'extra_price' => (float) ($response['extra_price'] ?? 0),
            'returned_extra_price' => (float) ($response['returned_extra_price'] ?? 0),
        ];
    }

    /**
     * Push order line items and uploaded photos to Shiply parcel content.
     */
    public function syncOrderContentsToParcel(SalesOrder $order, string $parcelCode, ?string $mode = null): void
    {
        $order->loadMissing(['items', 'media']);

        $photos = $order->media
            ->filter(fn (SalesOrderMedia $media) => $this->isImageMedia($media))
            ->sortBy(fn (SalesOrderMedia $media) => $this->shiplyMediaSortOrder($media))
            ->values();

        foreach ($photos as $media) {
            $this->tryUploadMediaAsParcelContent($parcelCode, $media, $mode);
        }

        foreach ($order->items->where('is_hidden', false) as $item) {
            $this->tryCreateParcelContent($parcelCode, $mode, [
                'item_name' => $this->parcelContentItemName($item),
                'quantity' => max(1, (int) $item->quantity),
                'item_price' => (int) round((float) $item->unit_price),
            ]);
        }
    }

    private function shiplyMediaSortOrder(SalesOrderMedia $media): int
    {
        $category = $this->resolveShiplyMediaCategory($media);

        return match ($category) {
            SalesOrderMediaCategory::ITEMS_GROUP => 1,
            SalesOrderMediaCategory::PACKAGED => 2,
            default => 3,
        };
    }

    private function resolveShiplyMediaCategory(SalesOrderMedia $media): string
    {
        if (! Schema::hasColumn('sales_order_media', 'category')) {
            return SalesOrderMediaCategory::ITEMS_GROUP;
        }

        return SalesOrderMediaCategory::normalize($media->category ?? SalesOrderMediaCategory::GENERAL);
    }

    private function isImageMedia(SalesOrderMedia $media): bool
    {
        if ($media->type === 'image') {
            return true;
        }

        $mime = strtolower(trim((string) $media->mime));

        return str_starts_with($mime, 'image/');
    }

    private function parcelContentItemName(SalesOrderItem $item): string
    {
        $name = trim((string) ($item->product_name ?? ''));
        if ($name === '') {
            $name = 'صنف #'.(int) $item->product_id;
        }

        return mb_substr($name, 0, 255);
    }

    private function tryUploadMediaAsParcelContent(
        string $parcelCode,
        SalesOrderMedia $media,
        ?string $mode
    ): void {
        if (! $this->isImageMedia($media)) {
            return;
        }

        $path = $this->prepareShiplyUploadPath($media);
        if ($path === null) {
            return;
        }

        $isTempFile = str_starts_with($path, sys_get_temp_dir());

        $category = $this->resolveShiplyMediaCategory($media);
        if (! in_array($category, [
            SalesOrderMediaCategory::ITEMS_GROUP,
            SalesOrderMediaCategory::PACKAGED,
        ], true)) {
            if ($isTempFile) {
                @unlink($path);
            }

            return;
        }

        $itemName = $this->shiplyMediaItemName($category);

        $attachments = [];
        if ($category === SalesOrderMediaCategory::PACKAGED) {
            $attachments['content_photo'] = $path;
        } else {
            $attachments['item_image'] = $path;
        }

        $this->tryCreateParcelContent($parcelCode, $mode, [
            'item_name' => mb_substr($itemName, 0, 255),
            'quantity' => 1,
        ], $attachments, (int) $media->id, $category);

        if ($isTempFile) {
            @unlink($path);
        }
    }

    private function shiplyMediaItemName(string $category): string
    {
        return match ($category) {
            SalesOrderMediaCategory::ITEMS_GROUP => __('messages.shiply_content_items_group'),
            SalesOrderMediaCategory::PACKAGED => __('messages.shiply_content_packaged'),
            default => __('messages.sales_order_media_category_'.$category),
        };
    }

    /**
     * @param  array<string, scalar|null>  $fields
     * @param  array<string, string>  $attachments  field name => absolute file path
     */
    private function tryCreateParcelContent(
        string $parcelCode,
        ?string $mode,
        array $fields,
        array $attachments = [],
        ?int $mediaId = null,
        ?string $mediaCategory = null,
    ): void {
        $validAttachments = array_filter(
            $attachments,
            fn ($filePath) => is_string($filePath) && is_file($filePath)
        );

        if ($attachments !== [] && $validAttachments === []) {
            Log::warning('shiply.parcel_content_missing_files', [
                'parcel_code' => $parcelCode,
                'media_id' => $mediaId,
                'category' => $mediaCategory,
                'attachments' => array_keys($attachments),
            ]);

            return;
        }

        try {
            $response = $this->requestMultipart('/parcel-content/create', array_merge($fields, [
                'parcel_code' => $parcelCode,
            ]), $validAttachments, $mode);

            if (isset($response['parcel_content'])) {
                Log::info('shiply.parcel_content_uploaded', [
                    'parcel_code' => $parcelCode,
                    'media_id' => $mediaId,
                    'category' => $mediaCategory,
                    'content_id' => $response['parcel_content']['id'] ?? null,
                    'has_item_image' => ! empty($response['parcel_content']['item_image'] ?? null),
                    'has_content_photo' => ! empty($response['parcel_content']['content_photo'] ?? null),
                ]);

                return;
            }

            Log::warning('shiply.parcel_content_rejected', [
                'parcel_code' => $parcelCode,
                'media_id' => $mediaId,
                'category' => $mediaCategory,
                'fields' => array_keys($fields),
                'attachments' => array_keys($validAttachments),
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            Log::warning('shiply.parcel_content_failed', [
                'parcel_code' => $parcelCode,
                'media_id' => $mediaId,
                'category' => $mediaCategory,
                'fields' => array_keys($fields),
                'attachments' => array_keys($validAttachments),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function prepareShiplyUploadPath(SalesOrderMedia $media): ?string
    {
        $absolute = $this->resolveLocalMediaPath($media);
        if ($absolute === null) {
            return null;
        }

        $maxBytes = (int) config('shiply.max_content_image_bytes', 2097152);
        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $size = @filesize($absolute) ?: 0;
        $shiplyReady = in_array($extension, ['jpg', 'jpeg'], true) && $size > 0 && $size <= $maxBytes;

        if ($shiplyReady) {
            return $absolute;
        }

        return $this->convertImageForShiply($absolute, $maxBytes, (int) $media->id);
    }

    private function resolveLocalMediaPath(SalesOrderMedia $media): ?string
    {
        $relative = trim((string) $media->path);
        if ($relative === '') {
            return null;
        }

        $candidates = [];
        if (Storage::disk('public')->exists($relative)) {
            $candidates[] = Storage::disk('public')->path($relative);
        }

        $publicPath = public_path('storage/'.ltrim(str_replace('\\', '/', $relative), '/'));
        if (is_file($publicPath)) {
            $candidates[] = $publicPath;
        }

        $storagePath = storage_path('app/public/'.ltrim(str_replace('\\', '/', $relative), '/'));
        if (is_file($storagePath)) {
            $candidates[] = $storagePath;
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];

        foreach (array_unique($candidates) as $absolute) {
            if (! is_file($absolute)) {
                continue;
            }

            $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            if (in_array($extension, $allowed, true)) {
                return $absolute;
            }
        }

        Log::warning('shiply.media_file_missing', [
            'media_id' => $media->id,
            'path' => $relative,
            'candidates' => $candidates,
        ]);

        return null;
    }

    private function convertImageForShiply(string $sourcePath, int $maxBytes, int $mediaId): ?string
    {
        if (! extension_loaded('gd')) {
            Log::warning('shiply.media_convert_unavailable', [
                'media_id' => $mediaId,
                'reason' => 'gd_missing',
            ]);

            return null;
        }

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->read($sourcePath);
            $image->scaleDown(1920, 1920);

            $tempBase = tempnam(sys_get_temp_dir(), 'shiply_');
            if ($tempBase === false) {
                return null;
            }
            @unlink($tempBase);
            $tempPath = $tempBase.'.jpg';

            for ($quality = 90; $quality >= 40; $quality -= 10) {
                $image->toJpeg(quality: $quality)->save($tempPath);
                $compressedSize = @filesize($tempPath);
                if ($compressedSize !== false && $compressedSize <= $maxBytes) {
                    return $tempPath;
                }
            }

            @unlink($tempPath);
            Log::warning('shiply.media_convert_failed', [
                'media_id' => $mediaId,
                'max_bytes' => $maxBytes,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('shiply.media_convert_failed', [
                'media_id' => $mediaId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function mimeForPath(string $path): string
    {
        $mime = @mime_content_type($path);

        return is_string($mime) && $mime !== '' ? $mime : 'image/jpeg';
    }

    /**
     * @param  array<string, scalar|null>  $fields
     * @param  array<string, string>  $attachments
     * @return array<string, mixed>
     */
    private function requestMultipart(
        string $path,
        array $fields,
        array $attachments = [],
        ?string $mode = null
    ): array {
        if (! ShiplySettings::isEnabled()) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_disabled')],
            ]);
        }

        $mode = $mode ?? ShiplySettings::mode();
        $apiKey = ShiplySettings::apiKey($mode);
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_api_key_missing')],
            ]);
        }

        $url = rtrim(ShiplySettings::baseUrl($mode), '/').'/'.ltrim($path, '/');
        $multipart = [
            ['name' => 'Shiply_API_KEY', 'contents' => $apiKey],
        ];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }
            $multipart[] = [
                'name' => $key,
                'contents' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ];
        }

        foreach ($attachments as $fieldName => $filePath) {
            if (! is_string($filePath) || ! is_file($filePath)) {
                continue;
            }

            $multipart[] = [
                'name' => $fieldName,
                'contents' => file_get_contents($filePath),
                'filename' => basename($filePath),
                'headers' => ['Content-Type' => $this->mimeForPath($filePath)],
            ];
        }

        try {
            $timeout = max(60, (int) config('shiply.http_timeout', 30));
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asMultipart()
                ->post($url, $multipart);
        } catch (ConnectionException $e) {
            Log::error('shiply.multipart_connection_failed', [
                'path' => $path,
                'mode' => $mode,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_connection_failed')],
            ]);
        } catch (RequestException $e) {
            Log::error('shiply.multipart_http_failed', [
                'path' => $path,
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [$this->formatHttpErrorMessage($e)],
            ]);
        }

        if ($response->failed()) {
            $json = $response->json();
            Log::warning('shiply.multipart_http_error', [
                'path' => $path,
                'mode' => $mode,
                'status' => $response->status(),
                'body' => $json ?? $response->body(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [$this->extractResponseErrorMessage(is_array($json) ? $json : null)
                    ?? __('messages.shiply_request_failed')],
            ]);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function assertOrderReadyForShiply(SalesOrder $order, string $mode): void
    {
        if (empty($order->shiply_village_id)) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_required')],
            ]);
        }

        if (trim((string) $order->customer_address) === '') {
            throw ValidationException::withMessages([
                'customer_address' => [__('messages.shiply_street_required')],
            ]);
        }

        if (trim((string) $order->customer_phone) === '') {
            throw ValidationException::withMessages([
                'customer_phone' => [__('messages.shiply_phone_required')],
            ]);
        }

        if (! ShiplyPhoneFormatter::isValidForParcel($order->customer_phone)) {
            throw ValidationException::withMessages([
                'customer_phone' => [__('messages.shiply_phone_invalid')],
            ]);
        }

        if (empty($order->shiply_city_id)) {
            throw ValidationException::withMessages([
                'shiply_city_id' => [__('messages.shiply_city_required')],
            ]);
        }

        $village = ShiplyVillage::query()
            ->where('mode', $mode)
            ->where('shiply_id', $order->shiply_village_id)
            ->first();

        if (! $village) {
            try {
                $this->syncAddresses($mode);
            } catch (\Throwable $e) {
                Log::warning('shiply.sync_before_parcel_failed', [
                    'mode' => $mode,
                    'message' => $e->getMessage(),
                ]);
            }

            $village = ShiplyVillage::query()
                ->where('mode', $mode)
                ->where('shiply_id', $order->shiply_village_id)
                ->first();
        }

        if (! $village) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_invalid')],
            ]);
        }

        if ($village->is_closed) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_closed')],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreateParcelPayload(SalesOrder $order): array
    {
        $order->loadMissing('items');
        $description = $order->items
            ->where('is_hidden', false)
            ->take(5)
            ->map(fn ($item) => $this->scalarString($item->product_name, 'صنف').' x'.(int) $item->quantity)
            ->implode(' | ');

        if ($description === '') {
            $description = 'طلبية '.($order->serial_number ?? '#'.$order->id);
        }

        $description = mb_substr($description, 0, 255);
        if (mb_strlen($description) < 3) {
            $description = 'طلبية';
        }

        $recipientName = $this->scalarString($order->customer_name, 'مستلم');
        if ($recipientName === '') {
            $recipientName = 'مستلم';
        }

        $notes = $this->scalarString($order->notes);

        return [
            'recipient' => [
                'first_name' => mb_substr($recipientName, 0, 100),
                'phone' => ShiplyPhoneFormatter::forParcel($order->customer_phone),
            ],
            'address' => [
                'city_id' => (int) $order->shiply_city_id,
                'village_id' => (int) $order->shiply_village_id,
                'street_name' => mb_substr($this->scalarString($order->customer_address), 0, 500),
            ],
            'total_price' => (int) round((float) $order->total),
            'actual_price' => (int) max(0, round((float) $order->subtotal - (float) $order->discount)),
            'description' => $description,
            'note' => $notes !== '' ? mb_substr($notes, 0, 1023) : null,
            'reference_number' => $order->serial_number ?: (string) $order->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function request(string $method, string $path, array $payload = [], ?string $mode = null): array
    {
        if (! ShiplySettings::isEnabled()) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_disabled')],
            ]);
        }

        $mode = $mode ?? ShiplySettings::mode();
        $apiKey = ShiplySettings::apiKey($mode);
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_api_key_missing')],
            ]);
        }

        $url = rtrim(ShiplySettings::baseUrl($mode), '/').'/'.ltrim($path, '/');
        $body = array_merge($payload, ['Shiply_API_KEY' => $apiKey]);
        $method = strtoupper($method);

        try {
            $client = Http::timeout((int) config('shiply.http_timeout', 30))->acceptJson();

            $response = match ($method) {
                'GET', 'HEAD', 'DELETE' => $client->withOptions([
                    'query' => $body,
                ])->send($method, $url),
                default => $client->asJson()->send($method, $url, ['json' => $body]),
            };
        } catch (ConnectionException $e) {
            Log::error('shiply.connection_failed', [
                'method' => $method,
                'path' => $path,
                'mode' => $mode,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_connection_failed')],
            ]);
        } catch (RequestException $e) {
            Log::error('shiply.http_failed', [
                'method' => $method,
                'path' => $path,
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [$this->formatHttpErrorMessage($e)],
            ]);
        }

        if ($response->failed()) {
            $json = $response->json();
            Log::warning('shiply.http_error', [
                'method' => $method,
                'path' => $path,
                'mode' => $mode,
                'status' => $response->status(),
                'body' => $json ?? $response->body(),
            ]);

            $errors = is_array($json) ? ($json['errors'] ?? $json['message'] ?? null) : null;
            if ($response->status() === 401 || $this->isUnauthorizedShiplyError($errors)) {
                throw ValidationException::withMessages([
                    'shiply' => [__('messages.shiply_api_unauthorized', ['mode' => $mode])],
                ]);
            }

            throw ValidationException::withMessages([
                'shiply' => [$this->extractResponseErrorMessage($json) ?? __('messages.shiply_request_failed')],
            ]);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_request_failed')],
            ]);
        }

        return $json;
    }

    private function formatHttpErrorMessage(RequestException $e): string
    {
        $json = $e->response?->json();
        if (is_array($json)) {
            $message = $this->extractResponseErrorMessage($json);
            if ($message !== null) {
                return $message;
            }
        }

        return __('messages.shiply_request_failed');
    }

    private function extractResponseErrorMessage(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $errors = $json['errors'] ?? null;

        if ($errors !== null && $this->isUnauthorizedShiplyError($errors)) {
            return $this->formatShiplyError($errors, '');
        }

        // الأخطاء التفصيلية قد تكون مصفوفة فارغة ([]) — في هذه الحالة نتجاهلها
        // ونعتمد على رسالة Shiply الأساسية (message) لأنها تحمل السبب الحقيقي.
        $flattened = $this->flattenShiplyErrors($errors);
        if ($flattened !== '') {
            return $this->localizeShiplyMessage($flattened);
        }

        $message = trim((string) ($json['message'] ?? ''));
        if ($message !== '') {
            return $this->localizeShiplyMessage($message);
        }

        $error = trim((string) ($json['error'] ?? ''));
        if ($error !== '') {
            return $this->localizeShiplyMessage($error);
        }

        return null;
    }

    /// ترجمة رسائل Shiply المعروفة إلى رسائل عربية واضحة للمبيعات.
    private function localizeShiplyMessage(string $message): string
    {
        $normalized = mb_strtolower($message);

        if (str_contains($normalized, 'total price more than')) {
            $limit = '2000';
            if (preg_match('/more than\s*([0-9]+)/i', $message, $matches)) {
                $limit = $matches[1];
            }

            return __('messages.shiply_total_price_limit', ['limit' => $limit]);
        }

        return $message;
    }

    private function formatShiplyError(mixed $errors, string $employeeEmail): string
    {
        if ($this->isUnauthorizedShiplyError($errors)) {
            return __('messages.shiply_unauthorized', [
                'email' => $employeeEmail !== '' ? $employeeEmail : '?',
            ]);
        }

        $text = $this->flattenShiplyErrors($errors);

        return $text !== '' ? $text : __('messages.shiply_create_parcel_failed');
    }

    private function flattenShiplyErrors(mixed $errors): string
    {
        if ($errors === null || $errors === '') {
            return '';
        }

        if (is_scalar($errors)) {
            $text = trim((string) $errors);

            return $text !== '' && $text !== 'Array' ? $text : '';
        }

        if (! is_array($errors)) {
            return '';
        }

        $parts = [];
        foreach ($errors as $key => $value) {
            $flat = $this->flattenShiplyErrors($value);
            if ($flat === '') {
                continue;
            }

            if (is_string($key) && ! is_numeric($key)) {
                $parts[] = $key.': '.$flat;
            } else {
                $parts[] = $flat;
            }
        }

        return implode(' ', $parts);
    }

    private function isUnauthorizedShiplyError(mixed $errors): bool
    {
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if ($this->isUnauthorizedShiplyError($error)) {
                    return true;
                }
            }

            return false;
        }

        return stripos(trim((string) $errors), 'unauthorized') !== false;
    }

    private function scalarString(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text !== '' ? $text : $default;
        }

        if (is_array($value)) {
            $flat = $this->flattenShiplyErrors($value);

            return $flat !== '' ? $flat : $default;
        }

        return $default;
    }
}
