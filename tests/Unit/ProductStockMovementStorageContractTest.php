<?php

namespace Tests\Unit;

use App\Models\ProductStockMovement;
use App\Services\ProductStockService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Facades\Schema;
use LengthException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class ProductStockMovementStorageContractTest extends TestCase
{
    // The deployed MySQL column is VARCHAR(32); SQLite does not enforce this limit.
    private const TYPE_COLUMN_LENGTH = 32;

    public function test_all_movement_types_fit_the_existing_column(): void
    {
        foreach ((new ReflectionClass(ProductStockMovement::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'TYPE_')) {
                $this->assertLessThanOrEqual(self::TYPE_COLUMN_LENGTH, strlen($value), $name.' exceeds VARCHAR(32).');
            }
        }
    }

    public static function invoiceReturnCases(): array
    {
        return [
            'PUR-60 return 30' => [10],
            'PUR-61 return 29' => [50],
            'PUR-61 return 31' => [1],
        ];
    }

    #[DataProvider('invoiceReturnCases')]
    public function test_return_restore_log_fits_mysql_without_connecting_to_a_database(int $quantity): void
    {
        Schema::shouldReceive('hasTable')->with('product_stock_movements')->once()->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturn(true);
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getName')->andReturn('mock-no-database');
        $connection->shouldReceive('getQueryGrammar')->andReturn(new MySqlGrammar);
        $query = Mockery::mock(Builder::class, [$connection, new MySqlGrammar, new Processor])->makePartial();
        $stored = null;
        $query->shouldReceive('insertGetId')->once()->andReturnUsing(function (array $payload) use (&$stored) {
            if (strlen($payload['type']) > self::TYPE_COLUMN_LENGTH) {
                throw new LengthException("Data too long for column 'type': VARCHAR(32).");
            }
            $stored = $payload;

            return 1;
        });
        $connection->shouldReceive('query')->andReturn($query);
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $previousResolver = Model::getConnectionResolver();
        Model::setConnectionResolver($resolver);
        try {
            (new ProductStockService)->logMovement(
                productId: 1,
                sizeId: null,
                sizeColorId: null,
                type: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE_RETURN_RESTORE,
                quantity: $quantity,
                stockBefore: 0,
                stockAfter: $quantity,
                referenceType: 'purchase_invoice_delete',
                referenceId: 100,
                note: 'عكس مرتجع شراء',
                userId: null,
            );
        } finally {
            Model::setConnectionResolver($previousResolver);
        }
        $this->assertSame($quantity, $stored['quantity']);
        $this->assertSame('purchase_invoice_delete', $stored['reference_type']);
        $this->assertNull($stored['created_by']);
        $this->assertNotSame(ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE, $stored['type']);
        $this->assertNotSame(ProductStockMovement::TYPE_PURCHASE_RETURN, $stored['type']);
    }
}
