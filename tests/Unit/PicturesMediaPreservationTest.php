<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Pictures;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PicturesMediaPreservationTest extends TestCase
{
    public function test_missing_file_preserves_the_existing_picture_media(): void
    {
        $request = Request::create('/pictures/edit', 'POST', [
            'picture_id' => 123,
            'name' => 'Updated name',
            'description' => 'Updated description',
        ]);

        $this->assertSame(
            'old-image.jpg',
            Pictures::storeImage($request, 'file', 'Pictures', 'old-image.jpg'),
        );
    }

    public function test_create_without_file_still_returns_null(): void
    {
        $request = Request::create('/pictures', 'POST');

        $this->assertNull(Pictures::storeImage($request, 'file', 'Pictures'));
    }

    public function test_existing_remote_reference_keeps_its_basename(): void
    {
        $request = Request::create('/pictures/edit', 'POST', [
            'file' => 'https://example.test/public/Pictures/old-image.jpg',
        ]);

        $this->assertSame(
            'old-image.jpg',
            Pictures::storeImage($request, 'file', 'Pictures', 'unused.jpg'),
        );
    }

    public function test_uploaded_jpeg_replaces_the_existing_media_with_a_uuid_filename(): void
    {
        $request = Request::create(
            '/pictures/edit',
            'POST',
            [],
            [],
            ['file' => UploadedFile::fake()->image('replacement.jpeg', 20, 10)],
        );

        $name = Pictures::storeImage(
            $request,
            'file',
            'test-pictures-upload',
            'old-image.jpg',
        );

        try {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.jpg$/', $name);
            $this->assertFileExists(public_path('test-pictures-upload/'.$name));
        } finally {
            if (is_string($name)) {
                @unlink(public_path('test-pictures-upload/'.$name));
            }
            @rmdir(public_path('test-pictures-upload'));
        }
    }
}
