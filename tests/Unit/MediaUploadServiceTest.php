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
}
