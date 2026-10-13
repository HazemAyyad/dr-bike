<?php

namespace Tests\Unit;

use App\Http\Controllers\PurchaseDataResetWebController;
use App\Models\Bill;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\DebtLedgerService;
use App\Services\InventoryCostingService;
use App\Services\PurchaseDataResetService;
use App\Services\PurchaseInvoicePurgeService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Every database facade is mocked. These tests never open a database connection.
class PurchaseDataResetSafetyTest extends TestCase
{
    public function test_employee_cannot_reset_even_with_a_preview(): void
    {
        DB::shouldReceive('transaction')->never();
        $service = new PurchaseDataResetService(Mockery::mock(PurchaseInvoicePurgeService::class));
        $this->expectException(HttpException::class);
        $service->reset('preview', new User(['type' => 'employee']), 'test reset');
    }

    public function test_blocked_admin_cannot_reset(): void
    {
        DB::shouldReceive('transaction')->never();
        $service = new PurchaseDataResetService(Mockery::mock(PurchaseInvoicePurgeService::class));
        $this->expectException(HttpException::class);
        $service->reset('preview', new User(['type' => 'admin', 'is_blocked' => true]), 'test reset');
    }

    public function test_changed_preview_is_rejected_before_any_purge(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);
        foreach (PurchaseDataResetService::TABLES as $table) {
            $query = Mockery::mock();
            $query->shouldReceive('orderBy')->with('id')->andReturnSelf();
            $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
            $query->shouldReceive('get')->once()->andReturn(collect());
            DB::shouldReceive('table')->with($table)->once()->andReturn($query);
        }
        $debts = Mockery::mock();
        $debts->shouldReceive('whereNull')->andReturnSelf();
        $debts->shouldReceive('where')->andReturnSelf();
        $debts->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $debts->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $debts->shouldReceive('get')->once()->andReturn(collect());
        DB::shouldReceive('table')->with('debt_transactions')->once()->andReturn($debts);
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $purge = Mockery::mock(PurchaseInvoicePurgeService::class);
        $purge->shouldNotReceive('purge');
        $purge->shouldNotReceive('purgeUnlinkedRecords');
        $service = new PurchaseDataResetService($purge);
        $this->expectException(ValidationException::class);
        $service->reset(str_repeat('a', 64), new User(['type' => 'admin']), 'test reset');
    }

    public function test_expired_preview_is_rejected_before_credentials_or_reset(): void
    {
        $session = Mockery::mock();
        $session->shouldReceive('get')->with('purchase_reset_preview')->once()->andReturn([
            'token' => str_repeat('a', 64), 'fingerprint' => 'test', 'expires_at' => now()->subMinute()->timestamp,
        ]);
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('validate')->once()->andReturn([
            'email' => 'admin@example.com', 'password' => 'unused', 'reason' => 'test',
            'confirmed' => '1', 'preview_token' => str_repeat('a', 64),
        ]);
        $request->shouldReceive('session')->once()->andReturn($session);
        $reset = Mockery::mock(PurchaseDataResetService::class);
        $reset->shouldNotReceive('reset');
        $this->expectException(ValidationException::class);
        (new PurchaseDataResetWebController)->destroy($request, $reset);
    }

    public function test_attachment_files_survive_an_outer_transaction_rollback(): void
    {
        $manager = new DatabaseTransactionsManager;
        $manager->begin('mock', 1);
        $manager->begin('mock', 2);
        $manager->commit('mock', 2, 1);
        DB::shouldReceive('afterCommit')->once()->andReturnUsing(fn ($callback) => $manager->addCallback($callback));
        Storage::shouldReceive('disk')->never();
        $this->purgeService()->deleteFilesAfterCommit([['disk' => 'public', 'path' => 'test.jpg']]);
        $manager->rollback('mock', 0);
        $manager->begin('mock', 1);
        $manager->commit('mock', 1, 0);
        $this->assertTrue(true); // Storage's never expectation proves no deletion happened.
    }

    public function test_attachment_files_are_deleted_only_when_outer_transaction_commits(): void
    {
        $manager = new DatabaseTransactionsManager;
        $manager->begin('mock', 1);
        $manager->begin('mock', 2);
        $manager->commit('mock', 2, 1);
        DB::shouldReceive('afterCommit')->once()->andReturnUsing(fn ($callback) => $manager->addCallback($callback));
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->with('test.jpg')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->once()->andReturn($disk);
        $this->purgeService()->deleteFilesAfterCommit([['disk' => 'public', 'path' => 'test.jpg']]);
        $manager->commit('mock', 1, 0);
        $this->assertTrue(true);
    }

    public function test_page_renders_preview_and_disables_reset_for_missing_schema(): void
    {
        $data = ['errors' => new ViewErrorBag, 'missing' => [], 'preview' => [
            'token' => str_repeat('a', 64), 'counts' => array_fill_keys(PurchaseDataResetService::TABLES, 1), 'totals' => [],
        ]];
        $html = view('purchase-data-reset', $data)->render();
        $this->assertStringContainsString('name="preview_token"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $html = view('purchase-data-reset', ['errors' => new ViewErrorBag, 'missing' => ['bills'], 'preview' => null])->render();
        $this->assertStringNotContainsString('id="reset-form"', $html);
    }

    public function test_reset_route_requires_security_session_and_is_throttled(): void
    {
        $routes = app('router')->getRoutes();
        $route = $routes->getByName('security-center.purchase-reset.destroy');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('security.center', $route->gatherMiddleware());
        $this->assertContains('throttle:2,1', $route->gatherMiddleware());
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_single_invoice_delete_calls_only_the_selected_invoice_purge(): void
    {
        $bill = new Bill;
        $bill->setRawAttributes(['id' => 42, 'final_total' => 150], true);
        $actor = new User(['type' => 'admin']);
        $version = PurchaseInvoicePurgeService::invoiceVersion($bill);
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('validate')->once()->andReturn([
            'email' => 'admin@example.com', 'password' => 'unused', 'reason' => '  test invoice  ',
            'confirmed' => '1', 'bill_version' => $version,
        ]);
        $session = Mockery::mock();
        $session->shouldReceive('forget')->with('purchase_reset_preview')->once();
        $request->shouldReceive('session')->once()->andReturn($session);
        $controller = Mockery::mock(PurchaseDataResetWebController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('authenticatedAdmin')->once()->andReturn($actor);
        Auth::shouldReceive('user')->once()->andReturn(null);
        Auth::shouldReceive('setUser')->with($actor)->once();
        Auth::shouldReceive('forgetUser')->once();
        $reset = Mockery::mock(PurchaseDataResetService::class);
        $reset->shouldReceive('missingTables')->once()->andReturn([]);
        $reset->shouldNotReceive('reset');
        $purge = Mockery::mock(PurchaseInvoicePurgeService::class);
        $purge->shouldReceive('purge')->with($bill, $actor, 'test invoice', true, $version)->once()->andReturn([
            'bill_id' => 42, 'backup_reference' => 'invoice-audit-test',
        ]);
        $response = $controller->destroyInvoice($request, $bill, $purge, $reset);
        $this->assertSame(route('security-center.purchase-reset.index'), $response->getTargetUrl());
        $this->assertSame(42, session('purchase_invoice_deleted.bill_id'));
        $this->assertSame('invoice-audit-test', session('purchase_invoice_deleted.backup_reference'));
    }

    public function test_invoice_version_ignores_list_counts_and_detects_changed_totals(): void
    {
        $bill = new Bill;
        $bill->setRawAttributes(['id' => 42, 'final_total' => '150.0000'], true);
        $original = PurchaseInvoicePurgeService::invoiceVersion($bill);
        $bill->setRawAttributes(['items_count' => 3, 'final_total' => '150.0000', 'id' => 42], true);
        $this->assertSame($original, PurchaseInvoicePurgeService::invoiceVersion($bill));
        $bill->setRawAttributes(['id' => 42, 'final_total' => '200.0000'], true);
        $this->assertNotSame($original, PurchaseInvoicePurgeService::invoiceVersion($bill));
    }

    public function test_invoice_list_has_a_separate_target_for_each_invoice(): void
    {
        $invoices = [];
        foreach ([42, 99] as $id) {
            $bill = new Bill;
            $bill->setRawAttributes([
                'id' => $id, 'final_total' => 150, 'paid_amount' => 0, 'currency' => 'شيكل',
                'workflow_status' => 'draft', 'items_count' => 2,
            ], true);
            $bill->setRelation('seller', null);
            $bill->setRelation('customer', null);
            $invoices[] = $bill;
        }
        $html = view('purchase-data-reset', [
            'errors' => new ViewErrorBag, 'missing' => [], 'search' => '',
            'invoices' => new LengthAwarePaginator($invoices, 2, 20),
            'preview' => ['token' => str_repeat('a', 64), 'counts' => array_fill_keys(PurchaseDataResetService::TABLES, 1), 'totals' => []],
        ])->render();
        foreach ([42, 99] as $id) {
            $this->assertStringContainsString('data-action="'.route('security-center.purchase-reset.invoices.destroy', $id).'"', $html);
            $this->assertStringContainsString('data-reference="PUR-'.$id.'"', $html);
        }
        $this->assertStringContainsString('name="bill_version"', $html);
        $this->assertStringContainsString('حذف الفاتورة المحددة', $html);
    }

    public function test_invoice_delete_route_is_protected_and_requires_a_numeric_bill(): void
    {
        $route = app('router')->getRoutes()->getByName('security-center.purchase-reset.invoices.destroy');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('security.center', $route->gatherMiddleware());
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertSame('[0-9]+', $route->wheres['bill']);
    }

    private function purgeService(): PurchaseInvoicePurgeService
    {
        return new PurchaseInvoicePurgeService(
            Mockery::mock(InventoryCostingService::class),
            Mockery::mock(DebtLedgerService::class),
            Mockery::mock(AccountingService::class),
        );
    }
}
