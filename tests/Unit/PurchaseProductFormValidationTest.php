<?php

namespace Tests\Unit;

use App\Services\InventoryCostingService;
use App\Services\ProductFormService;
use App\Services\ProductStockService;
use App\Services\ProductTagService;
use App\Services\StoreManageItemService;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class PurchaseProductFormValidationTest extends TestCase
{
    private function validateProduct(array $overrides = [], bool $forEdit = false): array
    {
        $payload = array_merge([
            'nameAr' => 'منتج شراء',
            'descriptionAr' => 'وصف المنتج',
            'discount' => 0,
            'normailPrice' => 100,
            'min_stock' => 0,
            'is_sold_with_paper' => 0,
            'save_scope' => 'local_only',
            'purchase_context' => '1',
            'store_section_id' => 7,
        ], $overrides);

        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $presence = $this->createStub(PresenceVerifierInterface::class);
        $presence->method('getCount')->willReturnCallback(
            fn ($table, $column, $value) => $table === 'store_sections' && (int) $value === 7 ? 1 : 0
        );
        $factory->setPresenceVerifier($presence);

        $request = new class($payload) extends Request
        {
            public Factory $validator;

            public function validate(array $rules): array
            {
                return $this->validator->make($this->all(), $rules)->validate();
            }
        };
        $request->validator = $factory;

        $service = new ProductFormService(
            $this->createStub(StoreManageItemService::class),
            $this->createStub(ProductTagService::class),
            $this->createStub(ProductStockService::class),
            $this->createStub(InventoryCostingService::class),
        );

        return $service->validateForm($request, $forEdit);
    }

    public function test_purchase_creation_accepts_location_without_categories(): void
    {
        $validated = $this->validateProduct();

        $this->assertSame(7, $validated['store_section_id']);
        $this->assertArrayNotHasKey('category_id', $validated);
        $this->assertArrayNotHasKey('sub_categories', $validated);
    }

    public function test_purchase_creation_accepts_an_explicit_null_category(): void
    {
        $this->assertNull($this->validateProduct(['category_id' => null])['category_id']);
    }

    public function test_supplied_category_must_still_exist(): void
    {
        $this->expectException(ValidationException::class);
        $this->validateProduct(['category_id' => 999]);
    }

    public function test_regular_local_creation_still_requires_category(): void
    {
        $this->expectException(ValidationException::class);
        $this->validateProduct(['purchase_context' => '0']);
    }

    public function test_store_synced_creation_still_requires_category(): void
    {
        $this->expectException(ValidationException::class);
        $this->validateProduct(['save_scope' => 'full']);
    }

    public function test_editing_still_requires_category(): void
    {
        try {
            $this->validateProduct([], true);
            $this->fail('Editing must require a category.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }
    }
}
