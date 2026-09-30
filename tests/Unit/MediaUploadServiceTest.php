<?php

namespace Tests\Unit;

use App\Services\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaUploadServiceTest extends TestCase
{
    public function test_it_stores_with_generated_name_and_detected_extension(): void
    {
        $file = UploadedFile::fake()->image('اسم مكرر.png');
        $name = app(MediaUploadService::class)->storePublic(
            $file,
            'test-media-upload',
            MediaUploadService::imageMimes(),
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $name);
        $this->assertFileExists(public_path('test-media-upload/'.$name));

        unlink(public_path('test-media-upload/'.$name));
        @rmdir(public_path('test-media-upload'));
    }

    public function test_it_rejects_mime_outside_the_endpoint_profile(): void
    {
        $file = UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf');

        $this->expectException(ValidationException::class);

        app(MediaUploadService::class)->storePublic(
            $file,
            'test-media-upload',
            MediaUploadService::imageMimes(),
        );
    }

    public function test_it_uses_detected_mime_instead_of_client_extension(): void
    {
        $source = UploadedFile::fake()->image('source.jpg', 20, 10);
        $file = new UploadedFile(
            $source->getPathname(),
            'misleading.pdf',
            null,
            UPLOAD_ERR_OK,
            true,
        );
        $name = app(MediaUploadService::class)->storePublic(
            $file,
            'test-media-upload',
            MediaUploadService::imageMimes(),
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.jpg$/', $name);
        $this->assertFileExists(public_path('test-media-upload/'.$name));

        unlink(public_path('test-media-upload/'.$name));
        @rmdir(public_path('test-media-upload'));
    }

    public function test_it_rejects_an_invalid_uploaded_file(): void
    {
        $file = new UploadedFile(
            __DIR__.'/missing-image.jpg',
            'missing-image.jpg',
            'image/jpeg',
            UPLOAD_ERR_NO_FILE,
            true,
        );

        $this->expectException(ValidationException::class);

        app(MediaUploadService::class)->storePublic(
            $file,
            'test-media-upload',
            MediaUploadService::imageMimes(),
        );
    }
}
