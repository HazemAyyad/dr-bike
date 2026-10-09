<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SupportMessageAttachment;
use Illuminate\Support\Facades\Storage;

final class SupportAttachmentController extends Controller
{
    public function show(SupportMessageAttachment $attachment)
    {
        abort_unless($attachment->disk === 'local' && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download(
            $attachment->path,
            $attachment->original_name ?: basename($attachment->path),
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
    }
}
