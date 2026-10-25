<?php

namespace Tests\Unit;

use App\Rules\SalesOrderNoteAttachment;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class SalesOrderNoteAttachmentTest extends TestCase
{
    public function test_images_and_videos_have_separate_size_limits(): void
    {
        foreach ([['image/jpeg', 8], ['image/png', 8], ['image/webp', 8], ['video/mp4', 50], ['video/quicktime', 50], ['video/webm', 50], ['video/3gpp', 50]] as [$mime, $limit]) {
            self::assertSame([], $this->validateFile($mime, $limit * 1024 * 1024));
            self::assertCount(1, $this->validateFile($mime, $limit * 1024 * 1024 + 1));
        }
    }

    public function test_unsupported_or_invalid_uploads_are_rejected(): void
    {
        foreach (['application/pdf', 'image/svg+xml', 'text/plain', 'application/octet-stream'] as $mime) {
            self::assertCount(1, $this->validateFile($mime, 100));
        }
        self::assertCount(1, $this->validateFile('image/jpeg', 100, false));
    }

    private function validateFile(string $mime, int $size, bool $valid = true): array
    {
        $file = $this->createStub(UploadedFile::class);
        $file->method('isValid')->willReturn($valid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $errors = [];
        (new SalesOrderNoteAttachment)->validate('images.0', $file, function (string $message) use (&$errors) {
            $errors[] = $message;
        });

        return $errors;
    }
}
