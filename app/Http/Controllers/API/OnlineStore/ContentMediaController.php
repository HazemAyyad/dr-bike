<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class ContentMediaController extends Controller
{
    private const PERMISSION = 'Online Store Content Manage';

    private const DIRECTORY = 'OnlineStore/Content';

    private const PUBLIC_PATH_PREFIX = 'public/OnlineStore/Content';

    private const PRODUCT_PERMISSION = 'Online Store Products Manage';

    private const PRODUCT_DIRECTORY = 'OnlineStore/Products';

    private const PRODUCT_PUBLIC_PATH_PREFIX = 'public/OnlineStore/Products';

    public function store(Request $request, MediaUploadService $media): JsonResponse
    {
        return $this->storeImage($request, $media, self::PERMISSION, self::DIRECTORY, self::PUBLIC_PATH_PREFIX);
    }

    public function storeProductImage(Request $request, MediaUploadService $media): JsonResponse
    {
        return $this->storeImage($request, $media, self::PRODUCT_PERMISSION, self::PRODUCT_DIRECTORY, self::PRODUCT_PUBLIC_PATH_PREFIX);
    }

    public function storeProductMedia(Request $request, MediaUploadService $media): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($actor, self::PRODUCT_PERMISSION)->authorize();

        $allowedMimes = [...MediaUploadService::imageMimes(), ...MediaUploadService::videoMimes()];
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimetypes:'.implode(',', $allowedMimes)],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $mime = strtolower((string) $file->getMimeType());
        $filename = $media->storePublic($file, self::PRODUCT_DIRECTORY, $allowedMimes);

        return response()->json(['data' => [
            'path' => self::PRODUCT_PUBLIC_PATH_PREFIX.'/'.$filename,
            'media_type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'mime_type' => $mime,
        ]], 201);
    }

    private function storeImage(Request $request, MediaUploadService $media, string $permission, string $directory, string $publicPathPrefix): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($actor, $permission)->authorize();

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:'.implode(',', MediaUploadService::imageMimes()),
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $filename = $media->storePublic($file, $directory, MediaUploadService::imageMimes());

        return response()->json([
            'data' => [
                'image_path' => $publicPathPrefix.'/'.$filename,
            ],
        ], 201);
    }
}
