<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\MissingSaleCostService;
use App\Services\ProductStockService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\TestCase;

class InventoryCostCurrencyTest extends TestCase
{
    private $previousApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Facade::getFacadeApplication();
        $app = new Container;
        $app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $app));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        // No application bootstrap, connection, schema, or local test database.
        DB::swap(Mockery::mock());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        parent::tearDown();
    }

    public function test_shekel_aliases_are_canonicalized(): void
    {
        $costing = new InventoryCostingService(new ProductStockService);
        foreach ([null, '', '  ', 'شيكل', 'NIS', ' ils ', '₪', 'שקל'] as $currency) {
            $this->assertSame('شيكل', $costing->requireShekelCurrency($currency));
        }
    }

    public function test_summary_labels_and_foreign_costs_are_rejected_before_any_database_access(): void
    {
        $stock = new ProductStockService;
        $costing = new InventoryCostingService($stock);
        $adjustments = new InventoryAdjustmentService($costing, $stock);
        $product = new Product;
        $missing = new MissingSaleCostService($costing);
        $preview = new \ReflectionMethod($missing, 'buildPreview');

        foreach (['mixed', ' MIXED ', 'USD', 'دولار', 'JOD', 'دينار', 'invalid'] as $currency) {
            $operations = [
                fn () => $costing->requireShekelCurrency($currency),
                fn () => $costing->addOwnedStock($product, 1, 1, $currency, 'opening_stock', null),
                fn () => $costing->coverMissingCost($product, 1, $currency, 'inventory_cost_initialization', null),
                fn () => $costing->lockedBalance($product, null, null, $currency),
                fn () => $costing->assertCurrencyCompatible($product, $currency),
                fn () => $adjustments->adjustQuantity($product, 0, 'جرد', null, 1, $currency, null, null),
                fn () => $adjustments->revalue($product, 1, 'تصحيح', null, $currency, null, null),
                fn () => $adjustments->initializeMissingCost($product, 1, 'تصحيح', null, $currency, null, null),
                fn () => $adjustments->setReferenceCost($product, 1, 'مرجعية', null, $currency, null, null),
                fn () => $preview->invoke($missing, $product, null, 1, $currency),
            ];
            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('Invalid inventory cost currency was accepted: '.$currency);
                } catch (ValidationException $e) {
                    $this->assertSame(['currency' => ['عملة تكلفة المخزون يجب أن تكون الشيكل.']], $e->errors());
                }
            }
        }
    }

    public function test_reads_keep_legacy_currency_labels_visible_until_the_data_correction_runs(): void
    {
        $costing = new InventoryCostingService(new ProductStockService);
        $this->assertSame('mixed', $costing->normalizeCurrency('mixed'));
        $this->assertSame('دولار', $costing->normalizeCurrency('USD'));
    }

    public function test_correction_only_updates_mixed_currency_labels_in_a_transaction(): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($apply) => $apply());
        foreach (['inventory_adjustments', 'inventory_cost_balances', 'inventory_cost_layers', 'purchase_price_histories'] as $table) {
            $query = Mockery::mock();
            DB::shouldReceive('table')->once()->with($table)->andReturn($query);
            $query->shouldReceive('whereRaw')->once()->with('LOWER(TRIM(currency)) = ?', ['mixed'])->andReturnSelf();
            // No quantity, amount, source, or timestamp may be rewritten.
            $query->shouldReceive('update')->once()->with(['currency' => 'شيكل'])->andReturn(1);
        }
        $migration = require __DIR__.'/../../database/migrations/2026_11_08_130000_correct_mixed_inventory_cost_currency.php';
        $migration->up();
        $migration->down();
        $this->addToAssertionCount(1);
    }
}
