<?php

namespace Tests\Unit;

use App\Support\ProfileImageUrl;
use Tests\TestCase;

class ProfileImageUrlTest extends TestCase
{
    public function test_it_keeps_absolute_urls_and_rejects_empty_paths(): void
    {
        $this->assertNull(ProfileImageUrl::resolve(null));
        $this->assertNull(ProfileImageUrl::resolve('  '));
        $this->assertSame(
            'https://cdn.example.com/customer.jpg',
            ProfileImageUrl::resolve('https://cdn.example.com/customer.jpg')
        );
    }

    public function test_it_builds_a_public_storage_url_for_relative_paths(): void
    {
        config(['app.url' => 'https://dr-bike.example']);

        $this->assertSame(
            'https://dr-bike.example/storage/profile/customer.jpg',
            ProfileImageUrl::resolve('/profile/customer.jpg')
        );
    }
}
