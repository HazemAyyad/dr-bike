<?php

namespace Tests\Unit;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCommerceMessageService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WhatsAppCommerceMessageServiceTest extends TestCase
{
    public function test_it_normalizes_an_incoming_cart_using_current_inventory_values(): void
    {
        $service = $this->serviceWithCatalog();
        $message = new WhatsAppMessage([
            'direction' => 'inbound',
            'raw_payload' => [
                'type' => 'order',
                'order' => ['product_items' => [[
                    'product_retailer_id' => 'VAR-RED',
                    'quantity' => 2,
                    'item_price' => 999,
                    'currency' => 'ILS',
                ]]],
            ],
        ]);

        $details = $service->details($message);

        $this->assertSame('order', $details['kind']);
        $this->assertSame(2, $details['items_count']);
        $this->assertSame(50.0, $details['items'][0]['unit_price']);
        $this->assertSame(100.0, $details['estimated_total']);
        $this->assertSame('أحمر / كبير', $details['items'][0]['variant_label']);
        $this->assertTrue($details['can_convert']);
    }

    public function test_cart_conversion_is_disabled_when_requested_quantity_exceeds_stock(): void
    {
        $service = $this->serviceWithCatalog();
        $message = new WhatsAppMessage([
            'direction' => 'inbound',
            'raw_payload' => [
                'type' => 'order',
                'order' => ['product_items' => [[
                    'product_retailer_id' => 'VAR-RED',
                    'quantity' => 5,
                ]]],
            ],
        ]);

        $this->assertFalse($service->details($message)['can_convert']);
    }

    public function test_it_normalizes_an_outgoing_catalog_product_list(): void
    {
        $service = $this->serviceWithCatalog();
        $message = new WhatsAppMessage([
            'direction' => 'outbound',
            'raw_payload' => [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'product_list',
                    'action' => ['sections' => [[
                        'product_items' => [['product_retailer_id' => 'VAR-RED']],
                    ]]],
                ],
            ],
        ]);

        $details = $service->details($message);

        $this->assertSame('catalog', $details['kind']);
        $this->assertSame('كتالوج منتجات مُرسل', $details['title']);
        $this->assertSame('بطارية', $details['items'][0]['name']);
        $this->assertFalse($details['can_convert']);
    }

    private function serviceWithCatalog(): WhatsAppCommerceMessageService
    {
        return new class extends WhatsAppCommerceMessageService
        {
            protected function catalogItems(Collection $retailerIds, ?int $accountId): Collection
            {
                return collect([
                    'VAR-RED' => [
                        'product_id' => '10',
                        'size_color_id' => '20',
                        'size_id' => '30',
                        'size_label' => 'كبير',
                        'color_label' => 'أحمر',
                        'name' => 'بطارية',
                        'variant_label' => 'أحمر / كبير',
                        'unit_price' => 50.0,
                        'stock' => 3,
                        'image' => null,
                        'matched' => true,
                    ],
                ]);
            }
        };
    }
}
