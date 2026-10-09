<?php

namespace Tests\Unit\OnlineStore;

use Tests\TestCase;

class PublicStoreProductPageViewTest extends TestCase
{
    public function test_public_product_page_uses_real_branding_share_metadata_and_no_fake_rating(): void
    {
        $product = $this->product();
        $html = view('store.products.show', [
            'product' => $product,
            'relatedProducts' => [],
            'structuredData' => $this->structuredData($product),
        ])->render();

        $this->assertStringContainsString(asset('assets/doctor-bike-logo.png'), $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/public/store/products/42">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.test/products/bike.png">', $html);
        $this->assertStringContainsString('دراجة كهربائية أصلية', $html);
        $this->assertStringContainsString('doctorbike-store:\/\/products\/42', $html);
        $this->assertStringContainsString('لا توجد تقييمات منشورة بعد', $html);
        $this->assertStringNotContainsString('<span class="stars-meter"', $html);
        $this->assertStringNotContainsString('aggregateRating', $html);
    }

    public function test_public_product_page_renders_the_authoritative_rating_value(): void
    {
        $product = $this->product([
            'rating' => 3.7,
            'review_count' => 12,
        ]);
        $structuredData = $this->structuredData($product);
        $structuredData['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => 3.7,
            'reviewCount' => 12,
        ];

        $html = view('store.products.show', [
            'product' => $product,
            'relatedProducts' => [],
            'structuredData' => $structuredData,
        ])->render();

        $this->assertStringContainsString('<span class="stars-meter" style="--rating: 3.7"', $html);
        $this->assertStringContainsString('<strong>3.7</strong> (12 تقييم)', $html);
        $this->assertStringContainsString('"ratingValue":3.7', $html);
    }

    public function test_public_product_route_keeps_product_id_as_navigation_identity(): void
    {
        $this->assertStringEndsWith(
            '/store/products/42',
            route('store.products.show', ['product' => 42]),
        );
    }

    public function test_public_product_page_contains_mobile_first_responsive_guards(): void
    {
        $product = $this->product();
        $html = view('store.products.show', [
            'product' => $product,
            'relatedProducts' => [],
            'structuredData' => $this->structuredData($product),
        ])->render();

        $this->assertStringContainsString('width=device-width, initial-scale=1', $html);
        $this->assertStringContainsString('@media (max-width: 640px)', $html);
        $this->assertStringContainsString('@media (max-width: 360px)', $html);
        $this->assertStringContainsString('width: min(520px, calc(100% - 20px))', $html);
        $this->assertStringContainsString('overflow-x: auto', $html);
        $this->assertStringContainsString('aspect-ratio: 1 / 1', $html);
    }

    private function product(array $overrides = []): array
    {
        return array_replace([
            'id' => 42,
            'listing_id' => 900,
            'name' => 'دراجة كهربائية أصلية',
            'description' => 'وصف حقيقي للمنتج.',
            'description_excerpt' => 'وصف حقيقي للمنتج.',
            'code' => 'DB-42',
            'price' => 4750.0,
            'old_price' => null,
            'discount_percent' => 0,
            'available' => true,
            'purchasable' => true,
            'available_quantity' => 4,
            'rating' => null,
            'review_count' => 0,
            'media' => [[
                'url' => 'https://cdn.example.test/products/bike.png',
                'is_main' => true,
                'is_video' => false,
                'alt' => 'دراجة كهربائية أصلية',
            ]],
            'main_image' => 'https://cdn.example.test/products/bike.png',
            'quick_specs' => [['label' => 'البطارية', 'value' => '48V']],
            'shipping_warranty' => 'تظهر الشروط المعتمدة هنا.',
            'return_policy' => 'تظهر سياسة الإرجاع المعتمدة هنا.',
            'categories' => ['دراجات كهربائية'],
            'canonical_url' => 'https://example.test/public/store/products/42',
            'app_deep_link' => 'doctorbike-store://products/42',
        ], $overrides);
    }

    private function structuredData(array $product): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'url' => $product['canonical_url'],
            'image' => [$product['main_image']],
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'ILS',
                'price' => '4750.00',
            ],
        ];
    }
}
