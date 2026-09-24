<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\OutgoingCheck;
use App\Models\Permission;
use App\Models\Seller;
use App\Models\User;
use App\Services\AccountingProjectionService;
use App\Services\AccountingReconciliationService;
use App\Services\AccountingService;
use App\Services\DebtLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class DebtLedgerAccountingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['type' => 'admin', 'name' => 'Accounting Admin']);
        Sanctum::actingAs($this->user);
    }

    public function test_manual_given_is_atomic_and_posts_cash_and_receivable_without_duplicates(): void
    {
        $customer = $this->customer('Given Customer');
        $box = $this->box(1000);

        $response = $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 500,
            'transaction_date' => '2026-09-01',
            'box_id' => $box->id,
            'currency' => 'دولار',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $transaction = DebtTransaction::query()->firstOrFail();
        $this->assertSame('شيكل', $transaction->currency);
        $this->assertEqualsWithDelta(-500, (float) $transaction->balance_after, 0.001);
        $this->assertEqualsWithDelta(500, (float) $box->fresh()->total, 0.001);

        $projection = app(AccountingProjectionService::class);
        $projection->sync($transaction->fresh());
        $projection->sync($transaction->fresh());

        $this->assertSame(1, DB::table('accounting_journal_entries')
            ->where('source_type', 'debt_transaction')->where('source_id', $transaction->id)->count());
        $this->assertJournalLine('debt_transaction', $transaction->id, 'cash', 0, 500, $box->id, $customer->id);
        $this->assertJournalLine('debt_transaction', $transaction->id, 'accounts_receivable', 500, 0, $box->id, $customer->id);
    }

    public function test_manual_taken_increases_cash_and_posts_payable(): void
    {
        $customer = $this->customer('Taken Customer');
        $box = $this->box(100);

        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 300,
            'transaction_date' => '2026-09-01',
            'box_id' => $box->id,
        ])->assertJsonPath('status', 'success');

        $transaction = DebtTransaction::query()->firstOrFail();
        app(AccountingProjectionService::class)->sync($transaction->fresh());

        $this->assertEqualsWithDelta(400, (float) $box->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(300, (float) $transaction->balance_after, 0.001);
        $this->assertJournalLine('debt_transaction', $transaction->id, 'cash', 300, 0, $box->id, $customer->id);
        $this->assertJournalLine('debt_transaction', $transaction->id, 'accounts_payable', 0, 300, $box->id, $customer->id);
    }

    public function test_manual_debt_requires_box_and_rejects_insufficient_cash_without_partial_writes(): void
    {
        $customer = $this->customer('Safety Customer');

        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'taken',
            'amount' => 10,
            'transaction_date' => '2026-09-01',
        ])->assertJsonPath('status', 'error');

        $box = $this->box(100);
        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 101,
            'transaction_date' => '2026-09-01',
            'box_id' => $box->id,
        ])->assertJsonPath('message', 'رصيد الصندوق غير كافٍ لتنفيذ الحركة.');

        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DB::table('accounting_journal_entries')->count());
        $this->assertEqualsWithDelta(100, (float) $box->fresh()->total, 0.001);
    }

    public function test_employee_cannot_use_hidden_box_in_debt_create_update_or_payment_receive_but_admin_can(): void
    {
        $employeeUser = User::factory()->create(['type' => 'employee']);
        $employee = EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $permission = Permission::query()->firstOrCreate(
            ['name_en' => 'Debts'],
            ['name' => 'الديون'],
        );
        EmployeePermission::query()->create(['employee_id' => $employee->id, 'permission_id' => $permission->id]);
        $customer = $this->customer('Box Access Customer');
        $boxA = $this->box(500, 'شيكل', 'Visible A');
        $boxB = $this->box(500, 'شيكل', 'Hidden B');
        $employee->visibleBoxes()->attach($boxA->id);
        Sanctum::actingAs($employeeUser);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $beforeJournals = DB::table('accounting_journal_entries')->count();
        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 50,
            'transaction_date' => '2026-09-01', 'box_id' => $boxB->id,
        ])->assertJsonPath('message', 'الصندوق غير متاح لك.');
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame($beforeJournals, DB::table('accounting_journal_entries')->count());
        $this->assertEqualsWithDelta(500, (float) $boxB->fresh()->total, 0.001);

        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 50,
            'transaction_date' => '2026-09-01', 'box_id' => $boxA->id,
        ])->assertJsonPath('status', 'success');
        $transaction = DebtTransaction::query()->firstOrFail();
        $beforeLogs = BoxLog::query()->count();
        $beforeJournals = DB::table('accounting_journal_entries')->count();

        $this->postJson("/api/debt-ledger/transaction/{$transaction->id}/update", [
            'type' => 'given', 'amount' => 50, 'transaction_date' => '2026-09-01', 'box_id' => $boxB->id,
        ])->assertJsonPath('message', 'الصندوق غير متاح لك.');
        $this->assertSame($boxA->id, $transaction->fresh()->box_id);
        $this->assertSame($beforeLogs, BoxLog::query()->count());
        $this->assertSame($beforeJournals, DB::table('accounting_journal_entries')->count());
        $this->assertEqualsWithDelta(450, (float) $boxA->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(500, (float) $boxB->fresh()->total, 0.001);

        $this->postJson('/api/add/transaction', [
            'type' => 'receive',
            'customer_id' => $customer->id,
            'box_id' => $boxA->id,
            'box_value' => 300,
            'box_log_note' => 'قبض يجب أن يرجع بالكامل',
            'debts' => [['total' => 25, 'box_id' => $boxB->id, 'due_date' => '2026-09-02']],
        ])->assertJsonPath('message', 'الصندوق غير متاح لك.');
        $this->assertSame(1, DebtTransaction::query()->count());
        $this->assertSame($beforeLogs, BoxLog::query()->count());
        $this->assertEqualsWithDelta(450, (float) $boxA->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(500, (float) $boxB->fresh()->total, 0.001);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/debt-ledger/transaction', [
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 25,
            'transaction_date' => '2026-09-03', 'box_id' => $boxB->id,
        ])->assertJsonPath('status', 'success');
        $this->assertEqualsWithDelta(475, (float) $boxB->fresh()->total, 0.001);
    }

    public function test_payment_receive_preflight_rejects_combined_cash_out_without_partial_writes(): void
    {
        $customer = $this->customer('Combined Cash Customer');
        $box = $this->box(1000);

        $response = $this->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 600,
            'debts' => [[
                'total' => 500,
                'box_id' => $box->id,
                'due_date' => '2026-09-05',
            ]],
        ]);

        $response->assertOk()->assertJsonPath('status', 'error');
        $this->assertEqualsWithDelta(1000, (float) $box->fresh()->total, 0.001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, OutgoingCheck::query()->count());
        $this->assertSame(0, DB::table('accounting_journal_entries')->count());
    }

    public function test_payment_receive_commits_valid_cash_check_and_debt_as_one_unit(): void
    {
        $customer = $this->customer('Atomic Success Customer');
        $box = $this->box(2000);

        $this->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 300,
            'checks' => [[
                'check_value' => 200,
                'check_currency' => 'شيكل',
                'check_id' => 'ATOMIC-1',
                'bank_name' => 'Test Bank',
                'due_date' => '2026-09-10',
                'notes' => 'atomic check',
            ]],
            'debts' => [[
                'total' => 400,
                'box_id' => $box->id,
                'due_date' => '2026-09-06',
            ]],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertEqualsWithDelta(1300, (float) $box->fresh()->total, 0.001);
        $this->assertSame(2, BoxLog::query()->count());
        $this->assertSame(1, OutgoingCheck::query()->where('check_id', 'ATOMIC-1')->count());
        $this->assertSame(2, DebtTransaction::query()->where('customer_id', $customer->id)->count());

        $projection = app(AccountingProjectionService::class);
        $check = OutgoingCheck::query()->where('check_id', 'ATOMIC-1')->firstOrFail();
        $manual = DebtTransaction::query()->where('source', 'manual')->firstOrFail();
        $projection->syncOrFail($check);
        $projection->syncOrFail($check->fresh());
        $projection->syncOrFail($manual);
        $projection->syncOrFail($manual->fresh());
        $this->assertSame(1, DB::table('accounting_journal_entries')
            ->where('source_type', 'outgoing_check')->where('source_id', $check->id)->count());
        $this->assertSame(1, DB::table('accounting_journal_entries')
            ->where('source_type', 'debt_transaction')->where('source_id', $manual->id)->count());
    }

    public function test_payment_receive_rolls_back_early_cash_when_later_component_throws(): void
    {
        $customer = $this->customer('Late Failure Customer');
        $box = $this->box(1000);
        $this->mock(DebtLedgerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createTransaction')
                ->once()
                ->andThrow(new \RuntimeException('forced later component failure'));
        });

        $this->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 300,
            'debts' => [[
                'total' => 200,
                'box_id' => $box->id,
                'due_date' => '2026-09-07',
            ]],
        ])->assertOk()->assertJsonPath('status', 'error');

        $this->assertEqualsWithDelta(1000, (float) $box->fresh()->total, 0.001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, OutgoingCheck::query()->count());
        $this->assertSame(0, DB::table('accounting_journal_entries')->count());
    }

    public function test_backdated_insert_recalculates_only_the_affected_party_currency_in_date_and_id_order(): void
    {
        $customer = $this->customer('Backdated Customer');
        $box = $this->box(1000);
        $ledger = app(DebtLedgerService::class);

        $first = $ledger->createTransaction($this->manualPayload($customer, $box, 'taken', 100, '2026-09-01'), $this->user->id, logActivity: false);
        $last = $ledger->createTransaction($this->manualPayload($customer, $box, 'given', 40, '2026-09-10'), $this->user->id, logActivity: false);
        $middle = $ledger->createTransaction($this->manualPayload($customer, $box, 'taken', 20, '2026-09-05'), $this->user->id, logActivity: false);

        $this->assertEqualsWithDelta(100, (float) $first->fresh()->balance_after, 0.001);
        $this->assertEqualsWithDelta(120, (float) $middle->fresh()->balance_after, 0.001);
        $this->assertEqualsWithDelta(80, (float) $last->fresh()->balance_after, 0.001);
    }

    public function test_source_linked_update_archive_delete_and_bulk_restore_are_blocked(): void
    {
        $customer = $this->customer('Linked Customer');
        $box = $this->box(1000);
        $transaction = DebtTransaction::query()->create([
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 50,
            'currency' => 'شيكل',
            'balance_after' => -50,
            'transaction_date' => '2026-09-01',
            'box_id' => null,
            'source' => 'instant_sale',
            'source_id' => 99,
        ]);

        $payload = [
            'type' => 'given',
            'amount' => 60,
            'transaction_date' => '2026-09-02',
            'box_id' => $box->id,
        ];
        $this->postJson("/api/debt-ledger/transaction/{$transaction->id}/update", $payload)
            ->assertJsonPath('status', 'error')->assertJsonPath('source', 'instant_sale')->assertJsonPath('source_id', 99);
        $this->postJson("/api/debt-ledger/transaction/{$transaction->id}/archive")
            ->assertJsonPath('status', 'error');
        $this->postJson("/api/debt-ledger/transaction/{$transaction->id}/delete")
            ->assertJsonPath('status', 'error');
        $this->postJson('/api/debt-ledger/transactions/archive', ['transaction_ids' => [$transaction->id]])
            ->assertJsonPath('status', 'error');

        $transaction->update(['archived_at' => now()]);
        $this->postJson('/api/debt-ledger/transactions/restore', ['transaction_ids' => [$transaction->id]])
            ->assertJsonPath('status', 'error');
        $this->assertEqualsWithDelta(50, (float) $transaction->fresh()->amount, 0.001);
    }

    public function test_box_create_edit_and_delete_safety_rules(): void
    {
        $this->postJson('/api/add/box', ['name' => 'Invalid', 'total' => 5, 'currency' => 'شيكل'])
            ->assertJsonPath('status', 'error');
        $this->assertDatabaseMissing('boxes', ['name' => 'Invalid']);

        $this->postJson('/api/add/box', ['name' => 'Safe', 'total' => 0, 'currency' => 'شيكل'])
            ->assertJsonPath('status', 'success');
        $box = Box::query()->where('name', 'Safe')->firstOrFail();

        $this->postJson('/api/edit/box', [
            'box_id' => $box->id, 'name' => 'Safe', 'total' => 1, 'currency' => 'شيكل', 'is_shown' => 1,
        ])->assertJsonPath('status', 'error');
        $this->postJson('/api/edit/box', [
            'box_id' => $box->id, 'name' => 'Safe', 'total' => 0, 'currency' => 'دولار', 'is_shown' => 1,
        ])->assertJsonPath('status', 'error');
        $this->postJson('/api/edit/box', [
            'box_id' => $box->id, 'name' => 'Renamed', 'total' => 0, 'currency' => 'شيكل', 'is_shown' => 0,
        ])->assertJsonPath('status', 'success');
        $box->refresh();
        $this->assertSame('Renamed', $box->name);
        $this->assertFalse((bool) $box->is_shown);
        $this->assertEqualsWithDelta(0, (float) $box->total, 0.001);

        $this->postJson('/api/delete/box', ['box_id' => $box->id])->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('boxes', ['id' => $box->id]);

        $used = $this->box(0, 'شيكل', 'Used');
        BoxLog::query()->create(['box_id' => $used->id, 'type' => 'add', 'value' => 1, 'description' => 'history']);
        $this->postJson('/api/delete/box', ['box_id' => $used->id])
            ->assertJsonPath('message', 'لا يمكن حذف صندوق يحتوي على رصيد أو حركات مالية. قم بإخفائه بدلًا من حذفه.');
        $this->assertDatabaseHas('boxes', ['id' => $used->id]);
    }

    public function test_manual_box_adjustment_reasons_post_to_the_correct_accounts(): void
    {
        $box = $this->box(500);
        $cases = [
            ['amount' => 500, 'reason' => 'owner_contribution', 'counter' => 'owner_equity', 'cash_debit' => 500.0],
            ['amount' => -200, 'reason' => 'owner_withdrawal', 'counter' => 'owner_equity', 'cash_debit' => 0.0],
            ['amount' => 50, 'reason' => 'cash_overage', 'counter' => 'cash_overage_income', 'cash_debit' => 50.0],
            ['amount' => -40, 'reason' => 'cash_shortage', 'counter' => 'cash_shortage_expense', 'cash_debit' => 0.0],
            ['amount' => 10, 'reason' => 'accounting_correction', 'counter' => 'clearing', 'cash_debit' => 10.0],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/add/box/balance', [
                'box_id' => $box->id,
                'total' => $case['amount'],
                'reason_code' => $case['reason'],
                'note' => in_array($case['reason'], ['owner_contribution', 'owner_withdrawal'], true) ? null : 'Reviewed count',
            ])->assertJsonPath('status', 'success');

            $log = BoxLog::query()->latest('id')->firstOrFail();
            $this->assertSame($case['reason'], $log->reason_code);
            app(AccountingProjectionService::class)->sync($log->fresh());
            $accounts = $this->journalAccounts('box_adjustment', $log->id);
            $this->assertContains('cash', $accounts);
            $this->assertContains($case['counter'], $accounts);
            $cash = $this->journalLine('box_adjustment', $log->id, 'cash');
            $this->assertEqualsWithDelta($case['cash_debit'], (float) $cash->debit, 0.001);
        }

        $this->postJson('/api/add/box/balance', [
            'box_id' => $box->id, 'total' => 10, 'reason_code' => 'accounting_correction',
        ])->assertJsonPath('status', 'error');
    }

    public function test_box_transfer_is_atomic_balanced_idempotent_and_rejects_insufficient_funds(): void
    {
        $from = $this->box(1000, 'شيكل', 'From');
        $to = $this->box(200, 'شيكل', 'To');

        $this->postJson('/api/transfer/box/balance', [
            'from_box_id' => $from->id, 'to_box_id' => $to->id, 'total' => 300,
        ])->assertJsonPath('status', 'success');
        $this->assertEqualsWithDelta(700, (float) $from->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(500, (float) $to->fresh()->total, 0.001);

        $log = BoxLog::query()->where('type', 'transfer')->firstOrFail();
        $projection = app(AccountingProjectionService::class);
        $projection->sync($log);
        $projection->sync($log->fresh());
        $this->assertSame(1, DB::table('accounting_journal_entries')
            ->where('source_type', 'box_transfer')->where('source_id', $log->id)->count());
        $this->assertJournalLine('box_transfer', $log->id, 'cash', 300, 0, $to->id);
        $this->assertJournalLine('box_transfer', $log->id, 'cash', 0, 300, $from->id);

        $beforeLogs = BoxLog::query()->count();
        $this->postJson('/api/transfer/box/balance', [
            'from_box_id' => $from->id, 'to_box_id' => $to->id, 'total' => 701,
        ])->assertJsonPath('status', 'error');
        $this->assertSame($beforeLogs, BoxLog::query()->count());
        $this->assertEqualsWithDelta(700, (float) $from->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(500, (float) $to->fresh()->total, 0.001);
    }

    public function test_reconciliation_exposes_equal_and_opposite_customer_mismatches_per_party(): void
    {
        $customerA = $this->customer('Customer A');
        $customerB = $this->customer('Customer B');
        foreach ([$customerA, $customerB] as $customer) {
            DebtTransaction::query()->create([
                'customer_id' => $customer->id,
                'type' => 'given',
                'amount' => 100,
                'currency' => 'شيكل',
                'balance_after' => -100,
                'transaction_date' => '2026-09-01',
                'source' => 'legacy_test',
                'source_id' => 1000 + $customer->id,
            ]);
        }
        app(AccountingService::class)->post('test:customer-b', 'test', 1, '2026-09-01', 'شيكل', 'Per party test', [
            ['account_key' => 'accounts_receivable', 'debit' => 200, 'credit' => 0, 'customer_id' => $customerB->id],
            ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 200],
        ]);

        $rows = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons'])
            ->where('scope', 'accounts_receivable_customer')
            ->whereIn('dimension_id', [$customerA->id, $customerB->id]);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (array $row) => $row['matches'] === false));
        $this->assertEqualsCanonicalizing([-100.0, 100.0], $rows->pluck('difference')->map(fn ($v) => (float) $v)->all());
    }

    public function test_supplier_and_delivery_company_reconciliation_keep_their_own_dimensions(): void
    {
        $seller = Seller::query()->create(['name' => 'Supplier A', 'phone' => '0599777777', 'is_canceled' => false]);
        DebtTransaction::query()->create([
            'seller_id' => $seller->id,
            'type' => 'taken',
            'amount' => 70,
            'currency' => 'شيكل',
            'balance_after' => 70,
            'transaction_date' => '2026-09-01',
            'source' => 'legacy_test',
            'source_id' => 700,
        ]);
        DB::table('sales_orders')->insert([
            'serial_number' => 'CARRIER-1',
            'delivery_company_id' => 88,
            'carrier_receivable_balance' => 55,
            'status' => 'delivered',
            'is_debt_collection' => 0,
            'total' => 55,
            'payment_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons']);
        $supplier = $rows->first(fn (array $row) => $row['scope'] === 'accounts_payable_seller' && $row['dimension_id'] === $seller->id);
        $carrier = $rows->first(fn (array $row) => $row['scope'] === 'carrier_receivable' && $row['dimension_id'] === 88);

        $this->assertNotNull($supplier);
        $this->assertSame('seller', $supplier['dimension_type']);
        $this->assertFalse($supplier['matches']);
        $this->assertNotNull($carrier);
        $this->assertSame('delivery_company', $carrier['dimension_type']);
        $this->assertFalse($carrier['matches']);
    }

    public function test_balance_check_command_dry_run_does_not_write_and_repair_changes_only_balance_after(): void
    {
        $customer = $this->customer('Repair Customer');
        $first = DebtTransaction::query()->create([
            'customer_id' => $customer->id, 'type' => 'taken', 'amount' => 100, 'currency' => 'شيكل',
            'balance_after' => 999, 'transaction_date' => '2026-09-01', 'source' => 'legacy_test', 'source_id' => 1,
        ]);
        $second = DebtTransaction::query()->create([
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 40, 'currency' => 'شيكل',
            'balance_after' => 888, 'transaction_date' => '2026-09-10', 'source' => 'legacy_test', 'source_id' => 2,
        ]);
        $before = $second->updated_at;

        Artisan::call('debt-ledger:check-balances', ['--dry-run' => true]);
        $this->assertEqualsWithDelta(999, (float) $first->fresh()->balance_after, 0.001);
        $this->assertEqualsWithDelta(888, (float) $second->fresh()->balance_after, 0.001);

        Artisan::call('debt-ledger:check-balances', ['--repair' => true]);
        $this->assertEqualsWithDelta(100, (float) $first->fresh()->balance_after, 0.001);
        $this->assertEqualsWithDelta(60, (float) $second->fresh()->balance_after, 0.001);
        $this->assertTrue($before->equalTo($second->fresh()->updated_at));
    }

    private function customer(string $name): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'phone' => '0599'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'is_canceled' => false,
        ]);
    }

    private function box(float $total, string $currency = 'شيكل', string $name = 'Main'): Box
    {
        return Box::query()->create([
            'name' => $name.' '.uniqid(),
            'total' => $total,
            'currency' => $currency,
            'is_shown' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function manualPayload(Customer $customer, Box $box, string $type, float $amount, string $date): array
    {
        return [
            'customer_id' => $customer->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => $box->currency,
            'transaction_date' => $date,
            'box_id' => $box->id,
            'source' => 'manual',
        ];
    }

    /** @return array<int, string> */
    private function journalAccounts(string $sourceType, int $sourceId): array
    {
        return DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.source_type', $sourceType)
            ->where('entries.source_id', $sourceId)
            ->pluck('accounts.system_key')->all();
    }

    private function journalLine(string $sourceType, int $sourceId, string $accountKey, ?int $boxId = null): object
    {
        return DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.source_type', $sourceType)
            ->where('entries.source_id', $sourceId)
            ->where('accounts.system_key', $accountKey)
            ->when($boxId !== null, fn ($query) => $query->where('lines.box_id', $boxId))
            ->select('lines.*')->firstOrFail();
    }

    private function assertJournalLine(
        string $sourceType,
        int $sourceId,
        string $accountKey,
        float $debit,
        float $credit,
        ?int $boxId = null,
        ?int $customerId = null,
    ): void {
        $line = $this->journalLine($sourceType, $sourceId, $accountKey, $boxId);
        $this->assertEqualsWithDelta($debit, (float) $line->debit, 0.001);
        $this->assertEqualsWithDelta($credit, (float) $line->credit, 0.001);
        if ($customerId !== null) {
            $this->assertSame($customerId, (int) $line->customer_id);
        }
    }
}
