<?php

namespace Tests\Unit;

use App\Models\Box;
use App\Models\OutgoingCheck;
use App\Services\OutgoingCheckSettlementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OutgoingCheckPartialSettlementTest extends TestCase
{
    private string $originalConnection;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.check_settlement_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('check_settlement_test');
        $this->dispatcher = OutgoingCheck::getEventDispatcher();
        OutgoingCheck::unsetEventDispatcher();

        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('boxes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->decimal('total', 14, 4);
            $t->string('currency');
            $t->timestamps();
        });
        Schema::create('outgoing_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('status')->default('not_cashed');
            $t->string('settlement_status')->default('unpaid');
            $t->decimal('total', 14, 4);
            $t->date('due_date')->nullable();
            $t->string('currency');
            $t->string('check_id')->nullable();
            $t->string('bank_name')->nullable();
            $t->unsignedBigInteger('box_id')->nullable();
            $t->timestamp('restructured_at')->nullable();
            $t->timestamps();
        });
        Schema::create('outgoing_check_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outgoing_check_id');
            $t->foreignId('box_id');
            $t->decimal('amount', 14, 4);
            $t->date('paid_at');
            $t->string('idempotency_key')->unique();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('outgoing_check_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outgoing_check_id');
            $t->decimal('amount', 14, 4);
            $t->date('due_date');
            $t->string('instrument_type');
            $t->string('check_id')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('status')->default('pending');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('box_id');
            $t->string('description')->nullable();
            $t->text('note')->nullable();
            $t->decimal('value', 14, 4)->nullable();
            $t->string('type')->nullable();
            $t->timestamps();
        });
        Schema::create('debt_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('source')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->decimal('amount', 14, 4);
        });
    }

    protected function tearDown(): void
    {
        OutgoingCheck::setEventDispatcher($this->dispatcher);
        DB::disconnect('check_settlement_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_partial_payment_restructures_remainder_without_touching_party_debt(): void
    {
        $boxId = DB::table('boxes')->insertGetId(['name' => 'الرئيسي', 'total' => 100000, 'currency' => 'شيكل']);
        $checkId = DB::table('outgoing_checks')->insertGetId([
            'seller_id' => 9, 'status' => 'not_cashed', 'settlement_status' => 'unpaid', 'total' => 70000,
            'currency' => 'شيكل', 'check_id' => 'OLD-70', 'bank_name' => 'فلسطين', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('debt_transactions')->insert(['source' => 'outgoing_check', 'source_id' => $checkId, 'amount' => 70000]);

        $payload = [
            'outgoing_check_id' => $checkId, 'box_id' => $boxId, 'amount' => 40000,
            'paid_at' => '2026-09-27', 'idempotency_key' => 'partial-70-40',
            'installments' => [
                ['amount' => 15000, 'due_date' => '2026-10-27', 'instrument_type' => 'replacement_check', 'check_id' => 'NEW-1', 'bank_name' => 'فلسطين'],
                ['amount' => 15000, 'due_date' => '2026-11-27', 'instrument_type' => 'replacement_check', 'check_id' => 'NEW-2', 'bank_name' => 'فلسطين'],
            ],
        ];

        $check = app(OutgoingCheckSettlementService::class)->settle($payload, null);
        $this->assertSame('restructured', $check->status);
        $this->assertEqualsWithDelta(40000, $check->settled_amount, 0.0001);
        $this->assertEqualsWithDelta(30000, $check->remaining_amount, 0.0001);
        $this->assertEqualsWithDelta(60000, Box::query()->findOrFail($boxId)->total, 0.0001);
        $this->assertSame(2, $check->installments()->count());
        $this->assertSame(1, DB::table('debt_transactions')->count());

        app(OutgoingCheckSettlementService::class)->settle($payload, null);
        $this->assertSame(1, DB::table('outgoing_check_settlements')->count());
        $this->assertEqualsWithDelta(60000, Box::query()->findOrFail($boxId)->total, 0.0001);
    }

    public function test_restructure_rejects_installments_that_do_not_equal_the_remainder(): void
    {
        $boxId = DB::table('boxes')->insertGetId(['name' => 'الرئيسي', 'total' => 100000, 'currency' => 'شيكل']);
        $checkId = DB::table('outgoing_checks')->insertGetId([
            'seller_id' => 9, 'status' => 'cashed_to_person', 'settlement_status' => 'unpaid', 'total' => 70000,
            'currency' => 'شيكل', 'check_id' => 'OLD-70', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(OutgoingCheckSettlementService::class)->settle([
                'outgoing_check_id' => $checkId, 'box_id' => $boxId, 'amount' => 40000,
                'paid_at' => '2026-09-27', 'idempotency_key' => 'invalid-schedule',
                'installments' => [
                    ['amount' => 10000, 'due_date' => '2026-10-27', 'instrument_type' => 'same_check'],
                    ['amount' => 10000, 'due_date' => '2026-11-27', 'instrument_type' => 'same_check'],
                ],
            ], null);
            $this->fail('Expected schedule validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('installments', $e->errors());
        }

        $this->assertEqualsWithDelta(100000, Box::query()->findOrFail($boxId)->total, 0.0001);
        $this->assertSame(0, DB::table('outgoing_check_settlements')->count());
    }
}
