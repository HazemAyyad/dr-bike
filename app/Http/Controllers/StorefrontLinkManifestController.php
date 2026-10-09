<?php

namespace App\Http\Controllers;

use App\Services\OnlineStore\StorefrontLinkManifestService;

class StorefrontLinkManifestController extends Controller
{
    public function android(StorefrontLinkManifestService $manifests)
    {
        return response()->json($manifests->android(), 200, [
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES);
    }

    public function ios(StorefrontLinkManifestService $manifests)
    {
        return response()->json($manifests->ios(), 200, [
            'Cache-Control' => 'public, max-age=3600',
            'Content-Type' => 'application/json',
        ], JSON_UNESCAPED_SLASHES);
    }
}
