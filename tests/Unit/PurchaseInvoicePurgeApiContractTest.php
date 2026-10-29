<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Bills;
use App\Models\Bill;
use App\Models\User;
use App\Services\PurchaseInvoicePurgeService;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

// All model reads/writes are intercepted; no database connection is opened.
class PurchaseInvoicePurgeApiContractTest extends TestCase
{
    public function test_authenticated_purge_accepts_reason_without_password(): void
    {
        $user = new User(['type' => 'admin']);
        $user->id = 12;
        $request = $this->request($user);
        $purge = Mockery::mock(PurchaseInvoicePurgeService::class);
        $purge->shouldReceive('purge')->with(
            Mockery::on(fn ($bill) => $bill instanceof Bill && $bill->id === 61),
            $user,
            'فاتورة تجريبية',
        )->once()->andReturn(['backup_reference' => 'audit-test']);
        Schema::shouldReceive('hasColumn')->with('logs', 'source_type')->once()->andReturn(false);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('mock-no-database');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $grammar = new MySqlGrammar($connection);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('query')->andReturnUsing(function () use ($connection, $grammar) {
            $query = Mockery::mock(Builder::class, [$connection, $grammar, new Processor])->makePartial();
            $query->shouldReceive('get')->andReturnUsing(function () use ($query) {
                $this->assertSame('bills', $query->from);

                return collect([(object) ['id' => 61]]);
            });
            $query->shouldReceive('insertGetId')->andReturnUsing(function ($payload) use ($query) {
                $this->assertSame('logs', $query->from);
                $this->assertSame('bills', $payload['type']);

                return 1;
            });

            return $query;
        });
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $previousResolver = Model::getConnectionResolver();
        Model::setConnectionResolver($resolver);
        try {
            $response = (new Bills)->purgePurchaseInvoice($request, $purge);
        } finally {
            Model::setConnectionResolver($previousResolver);
        }
        $this->assertSame('success', $response->getData(true)['status']);
    }

    public function test_unauthenticated_request_cannot_purge(): void
    {
        $purge = Mockery::mock(PurchaseInvoicePurgeService::class);
        $purge->shouldNotReceive('purge');
        $response = (new Bills)->purgePurchaseInvoice($this->request(null), $purge);
        $this->assertSame('error', $response->getData(true)['status']);
        $this->assertSame('يجب تسجيل الدخول لحذف فاتورة الشراء', $response->getData(true)['message']);
    }

    public function test_api_route_keeps_authentication_and_delete_permission(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === 'api/purchase/purge');
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('check.permission:Delete Purchase Invoices', $route->gatherMiddleware());
    }

    private function request(?User $user): Request
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('validate')->with(Mockery::on(fn ($rules) => array_keys($rules) === ['bill_id', 'reason']))
            ->once()->andReturn(['bill_id' => 61, 'reason' => '  فاتورة تجريبية  ']);
        $request->shouldReceive('user')->once()->andReturn($user);

        return $request;
    }
}
