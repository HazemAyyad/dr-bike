<?php

namespace Tests\Unit;

use Tests\TestCase;

class WhatsAppProductsPdfViewTest extends TestCase
{
    public function test_product_offer_contains_customer_quantities_total_and_ai_notice(): void
    {
        $html = view('whatsapp.products-pdf', [
            'products' => [[
                'name' => 'بطارية تجريبية',
                'unit_price' => 50.0,
                'quantity' => 2,
                'total' => 100.0,
                'code' => 'BAT-1',
                'stock' => 4,
                'model' => null,
                'category' => 'بطاريات',
                'description' => null,
                'image' => null,
            ]],
            'grandTotal' => 100.0,
            'generatedAt' => '2026-09-07 07:30 PM',
            'offerNumber' => 'WA-22-260907193000',
            'customerName' => 'زبون تجريبي',
            'customerPhone' => '970599000000',
            'qr' => 'data:image/svg+xml;base64,test',
            'logo' => 'data:image/jpeg;base64,test',
        ])->render();

        $this->assertStringContainsString('دكتور بايك - عرض منتجات', $html);
        $this->assertStringContainsString('زبون تجريبي', $html);
        $this->assertStringContainsString('بطارية تجريبية', $html);
        $this->assertStringContainsString('الكمية: 2', $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringContainsString('الذكاء الاصطناعي', $html);
        $this->assertStringContainsString('أخطاء في الأسعار أو الكميات', $html);
        $this->assertStringNotContainsString('الرمز:', $html);
        $this->assertStringNotContainsString('التصنيف:', $html);
        $this->assertStringNotContainsString('المتوفر:', $html);
    }

    public function test_product_cards_are_split_into_six_products_per_page(): void
    {
        $product = [
            'name' => 'منتج تجريبي',
            'unit_price' => 10.0,
            'quantity' => 1,
            'total' => 10.0,
            'code' => null,
            'stock' => 10,
            'model' => null,
            'category' => null,
            'description' => null,
            'image' => null,
        ];

        $html = view('whatsapp.products-pdf', [
            'products' => array_fill(0, 7, $product),
            'grandTotal' => 70.0,
            'generatedAt' => '2026-09-07 07:30 PM',
            'offerNumber' => 'WA-22-260907193000',
            'customerName' => 'زبون تجريبي',
            'customerPhone' => '970599000000',
            'qr' => 'data:image/svg+xml;base64,test',
            'logo' => 'data:image/jpeg;base64,test',
        ])->render();

        $this->assertSame(2, substr_count($html, 'class="offer-page'));
        $this->assertStringContainsString('1 / 2', $html);
        $this->assertStringContainsString('2 / 2', $html);
        $this->assertStringContainsString('منتج #7', $html);
    }
}
