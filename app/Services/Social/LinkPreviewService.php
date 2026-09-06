<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class LinkPreviewService
{
    private const MAX_HTML_BYTES = 524288;

    public function preview(string $url): array
    {
        $url = $this->assertPublicUrl($url);

        return Cache::remember(
            'social-link-preview:'.hash('sha256', $url),
            now()->addHours(12),
            fn () => $this->fetch($url)
        );
    }

    public function extract(string $url, string $html): array
    {
        $metadata = [];
        preg_match_all('/<meta\s+[^>]*>/iu', $html, $tags);

        foreach ($tags[0] as $tag) {
            preg_match_all('/([\w:-]+)\s*=\s*(["\'])(.*?)\2/isu', $tag, $attributes, PREG_SET_ORDER);
            $values = [];
            foreach ($attributes as $attribute) {
                $values[strtolower($attribute[1])] = html_entity_decode(trim($attribute[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $key = strtolower((string) ($values['property'] ?? $values['name'] ?? ''));
            if ($key !== '' && isset($values['content'])) {
                $metadata[$key] = $values['content'];
            }
        }

        $title = $metadata['og:title'] ?? $metadata['twitter:title'] ?? null;
        if (! $title && preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $match)) {
            $title = html_entity_decode(trim(strip_tags($match[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $image = $metadata['og:image'] ?? $metadata['twitter:image'] ?? null;
        $image = $image ? $this->absoluteUrl($url, $image) : null;

        return array_filter([
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'title' => $this->limit($title, 180),
            'description' => $this->limit($metadata['og:description'] ?? $metadata['description'] ?? $metadata['twitter:description'] ?? null, 280),
            'image_url' => $image,
            'site_name' => $this->limit($metadata['og:site_name'] ?? null, 80),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public static function firstUrl(?string $text): ?string
    {
        if (! $text || ! preg_match('~https?://[^\s<>]+~iu', $text, $match)) {
            return null;
        }

        return rtrim($match[0], '.,،؛:!?)]}');
    }

    private function fetch(string $url): array
    {
        for ($redirects = 0; $redirects < 4; $redirects++) {
            $response = Http::connectTimeout(3)
                ->timeout(7)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'DoctorBike-LinkPreview/1.0',
                ])
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! $location) break;
                $url = $this->assertPublicUrl($this->absoluteUrl($url, $location));
                continue;
            }

            if (! $response->successful()) {
                throw ValidationException::withMessages(['url' => 'تعذر تحميل معاينة الرابط.']);
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
                return [
                    'url' => $url,
                    'domain' => parse_url($url, PHP_URL_HOST),
                ];
            }

            $stream = $response->toPsrResponse()->getBody();
            $html = $stream->read(self::MAX_HTML_BYTES);

            return $this->extract($url, $html);
        }

        throw ValidationException::withMessages(['url' => 'الرابط أعاد توجيهات كثيرة.']);
    }

    private function assertPublicUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => 'الرابط غير صالح للمعاينة.']);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            throw ValidationException::withMessages(['url' => 'تعذر التحقق من نطاق الرابط.']);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['url' => 'لا يمكن معاينة رابط داخلي.']);
            }
        }

        return $url;
    }

    private function absoluteUrl(string $base, string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('~^https?://~i', $value)) return $value;
        if (str_starts_with($value, '//')) return parse_url($base, PHP_URL_SCHEME).':'.$value;

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
        if (str_starts_with($value, '/')) return $origin.$value;

        $path = (string) parse_url($base, PHP_URL_PATH);
        $directory = trim(str_replace('\\', '/', dirname($path)), '/.');

        return $origin.($directory ? '/'.$directory : '').'/'.$value;
    }

    private function limit(?string $value, int $length): ?string
    {
        if (! $value) return null;
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return mb_substr($value, 0, $length);
    }
}
