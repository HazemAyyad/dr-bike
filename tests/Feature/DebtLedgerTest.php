<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtTransaction;
use App\Models\InstantSale;
use App\Models\Seller;
use App\Models\User;
use App\Services\DebtLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DebtLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['type' => 'admin', 'name' => 'Admin']);
        Sanctum::actingAs($this->user);
    }

    public function test_create_transaction_for_customer_calculates_balance_after(): void
    {
        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '0599000001', 'is_canceled' => false]);

        $first = $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 100,
            'transaction_date' => '2026-05-01',
        ]);

        $first->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('transaction.balance_after', 100);

        $second = $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 30,
            'transaction_date' => '2026-05-02',
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('transaction.balance_after', 70)
            ->assertJsonPath('balance', 70);
    }

    public function test_create_transaction_for_seller(): void
    {
        $seller = Seller::create(['name' => 'Test Seller', 'phone' => '0599000002', 'is_canceled' => false]);

        $response = $this->postJson('/api/debt-ledger/transaction', [
            'seller_id' => $seller->id,
            'type' => 'taken',
            'amount' => 50,
            'transaction_date' => '2026-05-01',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('debt_transactions', [
            'seller_id' => $seller->id,
            'customer_id' => null,
            'type' => 'taken',
            'amount' => 50,
        ]);
    }

    public function test_validation_prevents_both_customer_and_seller(): void
    {
        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '0599000001', 'is_canceled' => false]);
        $seller = Seller::create(['name' => 'Test Seller', 'phone' => '0599000002', 'is_canceled' => false]);

        $response = $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'type' => 'taken',
            'amount' => 10,
            'transaction_date' => '2026-05-01',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'error');
    }

    public function test_report_filtering_by_period(): void
    {
        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '0599000001', 'is_canceled' => false]);

        DebtTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 20,
            'balance_after' => 20,
            'transaction_date' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $response = $this->postJson('/api/debt-ledger/person/report', [
            'customer_id' => $customer->id,
            'period' => 'today',
            'json_response' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('report.transactions_count', 1);
    }

    public function test_public_share_uses_the_selected_period_and_currency(): void
    {
        $customer = Customer::create(['name' => 'Amro Amer', 'phone' => '0599000099', 'is_canceled' => false]);

        DebtTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 40,
            'currency' => 'دولار',
            'balance_after' => 40,
            'transaction_date' => '2026-08-01',
            'source' => 'manual',
        ]);
        DebtTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 90,
            'currency' => 'دولار',
            'balance_after' => 130,
            'transaction_date' => '2026-08-20',
            'source' => 'manual',
        ]);
        DebtTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 500,
            'currency' => 'شيكل',
            'balance_after' => 500,
            'transaction_date' => '2026-08-20',
            'source' => 'manual',
        ]);

        $shareResponse = $this->postJson('/api/debt-ledger/person/share-link', [
            'customer_id' => $customer->id,
            'period' => 'custom',
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-25',
            'currency' => 'دولار',
            'report_detail_level' => 'summary',
        ]);

        $shareResponse->assertStatus(200)->assertJsonPath('status', 'success');

        $page = $this->get($shareResponse->json('share_url'));
        $page->assertOk()
            ->assertSee('2026-08-10 إلى 2026-08-25')
            ->assertSee('90.00 دولار')
            ->assertDontSee('40.00 دولار')
            ->assertDontSee('500.00 شيكل');
    }

    public function test_migration_command_does_not_duplicate_rows(): void
    {
        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '0599000001', 'is_canceled' => false]);

        $debt = Debt::create([
            'customer_id' => $customer->id,
            'type' => 'owed to us',
            'due_date' => '2026-04-01',
            'total' => 75,
            'notes' => 'test',
        ]);

        Artisan::call('debts:migrate-to-ledger');
        Artisan::call('debts:migrate-to-ledger');

        $this->assertEquals(1, DebtTransaction::where('source', 'old_debt_migration')
            ->where('source_id', $debt->id)
            ->count());

        $transaction = DebtTransaction::where('source_id', $debt->id)->first();
        $this->assertEquals('taken', $transaction->type);
    }

    public function test_instant_sale_debt_moves_when_customer_is_changed(): void
    {
        $oldCustomer = Customer::create(['name' => 'Osama Amer', 'phone' => '0599000101', 'is_canceled' => false]);
        $newCustomer = Customer::create(['name' => 'Anas Amer', 'phone' => '0599000102', 'is_canceled' => false]);

        $oldCustomerTransaction = DebtTransaction::create([
            'customer_id' => $oldCustomer->id,
            'type' => 'taken',
            'amount' => 100,
            'currency' => 'شيكل',
            'balance_after' => 100,
            'transaction_date' => '2026-08-01',
            'source' => 'manual',
        ]);

        $sale = InstantSale::create([
            'total_cost' => 40,
            'buyer_type' => 'customer',
            'buyer_id' => $oldCustomer->id,
            'buyer_name' => $oldCustomer->name,
            'status' => 'active',
        ]);

        $ledger = app(DebtLedgerService::class);
        $ledger->syncInstantSaleToLedger($sale);

        $sale->update([
            'buyer_id' => $newCustomer->id,
            'buyer_name' => $newCustomer->name,
        ]);
        $ledger->syncInstantSaleToLedger($sale->fresh());

        $this->assertDatabaseHas('debt_transactions', [
            'source' => 'instant_sale',
            'source_id' => $sale->id,
            'customer_id' => $newCustomer->id,
            'seller_id' => null,
            'type' => 'given',
            'amount' => 40,
            'balance_after' => -40,
        ]);
        $this->assertSame(100.0, (float) $oldCustomerTransaction->fresh()->balance_after);
    }
}
