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

    public function store(Request $request, MediaUploadService $media): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($actor, self::PERMISSION)->authorize();

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
        $filename = $media->storePublic($file, self::DIRECTORY, MediaUploadService::imageMimes());

        return response()->json([
            'data' => [
                'image_path' => self::PUBLIC_PATH_PREFIX.'/'.$filename,
            ],
        ], 201);
    }
}
