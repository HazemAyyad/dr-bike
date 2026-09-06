<?php

namespace Tests\Unit;

use App\Services\Social\LinkPreviewService;
use Tests\TestCase;

class SocialLinkPreviewServiceTest extends TestCase
{
    public function test_extracts_open_graph_preview_and_resolves_relative_image(): void
    {
        $preview = (new LinkPreviewService)->extract('https://example.com/news/item', <<<'HTML'
            <html><head>
                <meta property="og:title" content="عنوان الخبر">
                <meta property="og:description" content="وصف مختصر للخبر">
                <meta property="og:image" content="/images/preview.jpg">
                <meta property="og:site_name" content="Example News">
            </head></html>
            HTML);

        $this->assertSame('عنوان الخبر', $preview['title']);
        $this->assertSame('وصف مختصر للخبر', $preview['description']);
        $this->assertSame('https://example.com/images/preview.jpg', $preview['image_url']);
        $this->assertSame('example.com', $preview['domain']);
    }

    public function test_extracts_first_link_without_trailing_punctuation(): void
    {
        $this->assertSame(
            'https://doctor-bike.example/products/1',
            LinkPreviewService::firstUrl('شاهد المنتج https://doctor-bike.example/products/1،')
        );
        $this->assertNull(LinkPreviewService::firstUrl('رسالة بدون رابط'));
    }

    public function test_resolves_an_image_relative_to_the_page_directory(): void
    {
        $preview = (new LinkPreviewService)->extract(
            'https://example.com/news/item',
            '<meta property="og:image" content="preview.jpg">'
        );

        $this->assertSame('https://example.com/news/preview.jpg', $preview['image_url']);
    }
}
