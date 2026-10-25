<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SalesOrderNoteAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('تعذر قراءة مرفق الملاحظة.');

            return;
        }

        $mime = $value->getMimeType();
        $image = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
        $video = in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp'], true);
        if (! $image && ! $video) {
            $fail('أرفق صورة JPG أو PNG أو WebP، أو فيديو MP4 أو MOV أو WebM أو 3GP.');
        } elseif ($value->getSize() > ($image ? 8 : 50) * 1024 * 1024) {
            $fail($image ? 'حجم الصورة يجب ألا يتجاوز 8 MB.' : 'حجم الفيديو يجب ألا يتجاوز 50 MB.');
        }
    }
}
