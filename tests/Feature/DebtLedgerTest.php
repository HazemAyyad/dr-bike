<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtTransaction;
use App\Models\Seller;
use App\Models\User;
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
}
