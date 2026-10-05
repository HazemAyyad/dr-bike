<?php

namespace Tests\Unit\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\ListingReadinessService;
use App\Services\OnlineStore\MediaPresentationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class MediaPresentationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_replace_enforces_ownership_exclusivity_main_and_never_mutates_source_media(): void
    {
        $actor = OnlineStoreFixtureFactory::createAdminActor();
        $product = OnlineStoreFixtureFactory::createProduct();
        $other = OnlineStoreFixtureFactory::createProduct();
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id]);
        $source = NormalImageProduct::query()->forceCreate(['id' => 71, 'itemId' => $product->id, 'imageUrl' => 'source.jpg']);
        $foreign = NormalImageProduct::query()->forceCreate(['id' => 72, 'itemId' => $other->id, 'imageUrl' => 'foreign.jpg']);
        $snapshot = $source->getAttributes();
        $service = app(MediaPresentationService::class);

        $this->expectValidation(fn () => $service->replace($listing, [['source_type' => 'normal_image', 'source_id' => $foreign->id, 'is_main' => true, 'is_visible' => true]], $actor));
        $this->expectValidation(fn () => $service->replace($listing, [['source_type' => 'store_specific', 'source_id' => $source->id, 'store_media_path' => 'store.jpg', 'is_main' => true, 'is_visible' => true]], $actor));
        $this->expectValidation(fn () => $service->replace($listing, [['source_type' => 'normal_image', 'source_id' => $source->id, 'is_main' => false, 'is_visible' => true]], $actor));
        $this->expectValidation(fn () => $service->replace($listing, [['source_type' => 'normal_image', 'source_id' => $source->id, 'media_metadata' => ['is_360' => true], 'is_main' => true, 'is_visible' => true]], $actor));

        $service->replace($listing, [
            ['source_type' => 'store_specific', 'store_media_path' => 'store.jpg', 'media_metadata' => ['alt_translations' => ['en' => 'Store']], 'is_main' => false, 'is_visible' => false],
            ['source_type' => 'normal_image', 'source_id' => $source->id, 'is_main' => true, 'is_visible' => true],
        ], $actor);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());
        $this->assertSame(['store_specific', 'normal_image'], $listing->fresh()->mediaPresentations()->pluck('source_type')->all());

        $service->replace($listing, [
            ['source_type' => 'normal_image', 'source_id' => $source->id, 'is_main' => false, 'is_visible' => false],
            ['source_type' => 'store_specific', 'store_media_path' => 'store.jpg', 'media_metadata' => ['alt_translations' => ['en' => 'Store']], 'is_main' => true, 'is_visible' => true],
        ], $actor);
        $presentation = $listing->fresh()->mediaPresentations()->get();
        $this->assertSame(['normal_image', 'store_specific'], $presentation->pluck('source_type')->all());
        $this->assertFalse($presentation->first()->is_visible);
        $this->assertTrue($presentation->last()->is_main);
        $this->assertSame($snapshot, $source->fresh()->getAttributes());

        $service->replace($listing, [
            ['source_type' => 'normal_image', 'source_id' => $source->id, 'is_main' => true, 'is_visible' => true],
            ['source_type' => 'store_specific', 'store_media_path' => 'store.jpg', 'is_main' => false, 'is_visible' => false],
        ], $actor);
        $source->delete();
        $this->assertSame([], $service->resolved($listing->fresh(['product'])));
        $this->assertContains('missing_main_media', app(ListingReadinessService::class)->evaluate($listing->fresh(['product']))['issues']);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
