<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LegacyStoreImageController extends Controller
{
    private const LEGACY_BASE = 'https://mjsall-001-site1.jtempurl.com/';

    /**
     * بروكسي لصور المتجر القديم (.NET) حتى تعمل على Flutter Web دون CORS من نطاق mjsall.
     * GET /api/legacy-store-image?path=Images%2FItems%2Ffile.png
     */
    public function show(Request $request)
    {
        $path = $request->query('path', '');
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || ! preg_match('#^images/items/[^/]+$#i', $path)) {
            abort(400, 'Invalid path');
        }

        $url = self::LEGACY_BASE.$path;

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            abort(502, 'Upstream unreachable');
        }

        if (! $response->successful()) {
            abort($response->status() === 404 ? 404 : 502);
        }

        $contentType = $response->header('Content-Type');
        if (! $contentType || str_starts_with(strtolower($contentType), 'text/html')) {
            $contentType = 'image/jpeg';
        }

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
