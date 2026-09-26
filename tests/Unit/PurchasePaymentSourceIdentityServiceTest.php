<?php

namespace Tests\Unit;

use App\Models\PurchasePayment;
use App\Services\PurchasePaymentSourceIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasePaymentSourceIdentityServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'purchase_payment_identity_test',
            'database.connections.purchase_payment_identity_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('purchase_payment_identity_test');
        DB::setDefaultConnection('purchase_payment_identity_test');

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('amount', 14, 4);
            $table->string('currency')->nullable();
            $table->string('type')->default('payment');
            $table->date('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamps();
        });
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type');
            $table->decimal('amount', 14, 4);
            $table->string('currency')->nullable();
            $table->decimal('balance_after', 14, 4)->default(0);
            $table->date('transaction_date')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('purchase_payment_identity_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_amount_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['amount' => 99]);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('amount_mismatch', $item['mismatch_reasons']);
    }

    public function test_missing_debt_transaction_is_ambiguous_with_reason(): void
    {
        $paymentId = $this->createPayment();
        DB::table('purchase_payments')->where('id', $paymentId)->update(['debt_transaction_id' => 999]);

        $item = app(PurchasePaymentSourceIdentityService::class)
            ->inspect()
            ->firstWhere('purchase_payment_id', $paymentId);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertSame(['missing_debt_transaction'], $item['mismatch_reasons']);
    }

    public function test_source_type_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['source' => 'purchase_initial_payment']);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('source_type_mismatch', $item['mismatch_reasons']);
    }

    public function test_customer_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['customer_id' => 18]);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('customer_mismatch', $item['mismatch_reasons']);
    }

    public function test_seller_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['seller_id' => 19]);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('seller_mismatch', $item['mismatch_reasons']);
    }

    public function test_box_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['box_id' => 4]);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('box_mismatch', $item['mismatch_reasons']);
    }

    public function test_currency_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['currency' => 'دولار']);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('currency_mismatch', $item['mismatch_reasons']);
    }

    public function test_payment_date_mismatch_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['transaction_date' => '2026-09-11']);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('payment_date_mismatch', $item['mismatch_reasons']);
    }

    public function test_identity_conflict_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment([], function (PurchasePayment $payment): void {
            DB::table('debt_transactions')->insert($this->transactionAttributes($payment));
        });

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('identity_conflict', $item['mismatch_reasons']);
    }

    public function test_legacy_source_id_only_mismatch_remains_safe_to_repair(): void
    {
        $item = $this->inspectLinkedPayment(['source_id' => 999]);

        $this->assertSame('SAFE_TO_REPAIR', $item['status']);
        $this->assertSame(['source_id_mismatch'], $item['mismatch_reasons']);
    }

    public function test_already_correct_payment_remains_correct_without_mismatch_reasons(): void
    {
        $item = $this->inspectLinkedPayment();

        $this->assertSame('ALREADY_CORRECT', $item['status']);
        $this->assertSame([], $item['mismatch_reasons']);
    }

    public function test_inactive_transaction_is_ambiguous_with_reason(): void
    {
        $item = $this->inspectLinkedPayment(['archived_at' => '2026-09-12 10:00:00']);

        $this->assertSame('AMBIGUOUS', $item['status']);
        $this->assertContains('transaction_inactive', $item['mismatch_reasons']);
    }

    /** @return array<string, mixed> */
    private function inspectLinkedPayment(array $transactionOverrides = [], ?callable $afterLink = null): array
    {
        $paymentId = $this->createPayment();
        $payment = PurchasePayment::query()->findOrFail($paymentId);
        $transactionId = DB::table('debt_transactions')->insertGetId(array_merge(
            $this->transactionAttributes($payment),
            $transactionOverrides,
        ));
        DB::table('purchase_payments')->where('id', $paymentId)->update(['debt_transaction_id' => $transactionId]);
        $payment = $payment->fresh();
        if ($afterLink) {
            $afterLink($payment);
        }

        return app(PurchasePaymentSourceIdentityService::class)
            ->inspect()
            ->firstWhere('purchase_payment_id', $paymentId);
    }

    private function createPayment(): int
    {
        return DB::table('purchase_payments')->insertGetId([
            'bill_id' => 50,
            'seller_id' => 7,
            'customer_id' => null,
            'box_id' => 3,
            'amount' => 100,
            'currency' => 'شيكل',
            'type' => 'payment',
            'paid_at' => '2026-09-10',
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-10 10:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function transactionAttributes(PurchasePayment $payment): array
    {
        return [
            'seller_id' => $payment->seller_id,
            'customer_id' => $payment->customer_id,
            'box_id' => $payment->box_id,
            'type' => 'given',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'balance_after' => -100,
            'transaction_date' => $payment->paid_at?->toDateString(),
            'source' => 'purchase_payment',
            'source_id' => $payment->id,
            'archived_at' => null,
            'deleted_at' => null,
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-10 10:00:00',
        ];
    }
}
