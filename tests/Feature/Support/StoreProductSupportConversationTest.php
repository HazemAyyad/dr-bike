<?php

namespace Tests\Feature\Support;

use Tests\TestCase;

class StoreProductSupportConversationTest extends TestCase
{
    public function test_product_context_is_derived_from_an_eligible_listing_and_open_thread_is_reused(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/API/Store/StoreSupportConversationController.php'));

        $this->assertStringContainsString('StorefrontCatalogService', $controller);
        $this->assertStringContainsString('$this->catalog->isEligible($listing)', $controller);
        $this->assertStringContainsString("where('online_store_listing_id', \$listing->getKey())", $controller);
        $this->assertStringContainsString('SupportConversation::STATUS_PENDING', $controller);
        $this->assertStringContainsString("'product_id' => (int) \$listing->product_id", $controller);
        $this->assertStringNotContainsString("'product_id' => (int) \$request", $controller);
    }
}
