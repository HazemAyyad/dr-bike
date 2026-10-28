<?php

namespace Tests\Unit;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailySession;
use App\Models\User;
use App\Services\SalesDailySessionService;
use App\Services\SalesDailyTransferCorrectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesDailyTransferCorrectionServiceTest extends TestCase
{
    private string $originalConnection;

    private User $actor;

    private SalesDailySession $session;

    private SalesDailyClosingRequest $closing;

    private Box $daily;

    private Box $old;

    private Box $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config(['database.default' => 'closing_correction_test', 'database.connections.closing_correction_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('closing_correction_test');
        DB::setDefaultConnection('closing_correction_test');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->double('total');
            $table->string('currency');
            $table->boolean('is_shown')->default(true);
            $table->timestamps();
        });
        Schema::create('sales_daily_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('session_type');
            $table->date('business_date');
            $table->string('status');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_daily_closing_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->string('status');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->integer('instant_sales_count')->default(0);
            $table->integer('profit_sales_count')->default(0);
            $table->json('cash_counts')->nullable();
            $table->json('sales_orders_cash_counts')->nullable();
            $table->text('late_close_reason')->nullable();
            $table->json('transfers')->nullable();
            $table->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_box_id');
            $table->unsignedBigInteger('to_box_id');
            $table->text('description');
            $table->text('note')->nullable();
            $table->double('value');
            $table->string('type');
            $table->string('reason_code')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });
        $this->actor = new User;
        $this->actor->forceFill(['id' => 70, 'name' => 'محمود', 'type' => 'admin']);
        DB::table('users')->insert(['id' => 70, 'name' => 'محمود', 'type' => 'admin']);
        $this->daily = Box::create(['name' => 'اليومي', 'type' => 'daily_sales', 'user_id' => 70, 'currency' => 'شيكل', 'total' => 182, 'is_shown' => false]);
        $this->old = Box::create(['name' => 'تست', 'currency' => 'شيكل', 'total' => 5000, 'is_shown' => true]);
        $this->target = Box::create(['name' => 'الإدارة', 'currency' => 'شيكل', 'total' => 100, 'is_shown' => true]);
        $this->session = SalesDailySession::create(['user_id' => 70, 'session_type' => 'instant_sales', 'business_date' => '2026-10-27', 'status' => 'closed', 'closed_at' => '2026-10-28 05:16:01', 'closed_by_user_id' => 70]);
        $this->closing = SalesDailyClosingRequest::create([
            'session_id' => $this->session->id, 'requested_by_user_id' => 70, 'requested_at' => '2026-10-28 05:16:01',
            'status' => 'approved', 'reviewed_by_user_id' => 70, 'reviewed_at' => '2026-10-28 05:16:01',
            'instant_sales_count' => 3, 'cash_counts' => [[
                'currency' => 'شيكل', 'daily_box_id' => $this->daily->id, 'physical_count' => 2078,
                'float_to_keep' => 182, 'amount_to_transfer' => 1896,
            ]], 'transfers' => [[
                'currency' => 'شيكل', 'from_box_id' => $this->daily->id, 'to_box_id' => $this->old->id,
                'amount' => 1896, 'float_kept' => 182,
            ]],
        ]);
        BoxLog::create(['from_box_id' => $this->daily->id, 'to_box_id' => $this->old->id,
            'description' => 'الترحيل الأصلي', 'value' => 1896, 'type' => 'transfer']);
    }

    protected function tearDown(): void
    {
        DB::purge('closing_correction_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    private function correct(string $action = 'redirect', ?int $requestId = null): SalesDailyClosingRequest
    {
        return app(SalesDailyTransferCorrectionService::class)->correct($this->actor, $requestId ?? $this->closing->id,
            $action, [['currency' => 'شيكل', 'to_box_id' => $this->target->id]], 'اختيار وجهة خاطئة');
    }

    public function test_redirect_preserves_approval_and_original_history_without_touching_daily_float(): void
    {
        $replacement = $this->correct();
        $this->assertSame('superseded', $this->closing->fresh()->status);
        $this->assertSame($this->old->id, $this->closing->fresh()->transfers[0]['to_box_id']);
        $this->assertSame('approved', $replacement->status);
        $this->assertSame($this->target->id, $replacement->transfers[0]['to_box_id']);
        $this->assertEquals(3104, $this->old->fresh()->total);
        $this->assertEquals(1996, $this->target->fresh()->total);
        $this->assertEquals(182, $this->daily->fresh()->total);
        $this->assertSame('closed', $this->session->fresh()->status);
        $this->assertSame('2026-10-28 05:16:01', $this->session->fresh()->closed_at->toDateTimeString());
        $this->assertSame(2, BoxLog::count());
        $this->assertSame(70, BoxLog::latest('id')->first()->created_by);
        $this->assertStringContainsString('اختيار وجهة خاطئة', $replacement->review_notes);
    }

    public function test_history_exposes_readable_box_names(): void
    {
        $this->closing->setRelation('session', null);
        $row = app(SalesDailySessionService::class)->formatClosingRequestRow($this->closing);
        $this->assertSame('اليومي', $row['transfers'][0]['from_box_name']);
        $this->assertSame('تست', $row['transfers'][0]['to_box_name']);
    }

    public function test_repeated_old_request_cannot_transfer_twice(): void
    {
        $this->correct();
        try {
            $this->correct();
            $this->fail('Expected stale approval rejection');
        } catch (ValidationException) {
        }
        $this->assertEquals(3104, $this->old->fresh()->total);
        $this->assertSame(2, BoxLog::count());
    }

    public function test_second_currency_failure_rolls_back_the_entire_correction(): void
    {
        $dollarDaily = Box::create(['name' => 'اليومي دولار', 'type' => 'daily_sales', 'currency' => 'دولار', 'total' => 0]);
        $dollarOld = Box::create(['name' => 'الوجهة القديمة دولار', 'currency' => 'دولار', 'total' => 5]);
        $dollarTarget = Box::create(['name' => 'الوجهة الجديدة دولار', 'currency' => 'دولار', 'total' => 0, 'is_shown' => true]);
        $this->closing->update(['transfers' => array_merge($this->closing->transfers, [[
            'currency' => 'دولار', 'from_box_id' => $dollarDaily->id,
            'to_box_id' => $dollarOld->id, 'amount' => 10, 'float_kept' => 0,
        ]])]);
        try {
            app(SalesDailyTransferCorrectionService::class)->correct($this->actor, $this->closing->id, 'redirect', [
                ['currency' => 'شيكل', 'to_box_id' => $this->target->id],
                ['currency' => 'دولار', 'to_box_id' => $dollarTarget->id],
            ], 'تصحيح الوجهات');
            $this->fail('Expected second currency failure');
        } catch (ValidationException) {
        }
        $this->assertEquals(5000, $this->old->fresh()->total);
        $this->assertEquals(100, $this->target->fresh()->total);
        $this->assertEquals(5, $dollarOld->fresh()->total);
        $this->assertSame(1, SalesDailyClosingRequest::count());
        $this->assertSame(1, BoxLog::count());
    }

    public function test_latest_corrected_destination_is_used_for_reversal(): void
    {
        $replacement = $this->correct();
        $pending = $this->correct('return_to_review', $replacement->id);
        $this->assertSame('pending', $pending->status);
        $this->assertEquals(3104, $this->old->fresh()->total);
        $this->assertEquals(100, $this->target->fresh()->total);
        $this->assertEquals(2078, $this->daily->fresh()->total);
        $this->assertSame('reversed', $replacement->fresh()->status);
    }

    public function test_zero_transfer_can_be_returned_to_review_without_moving_cash(): void
    {
        $this->closing->update(['transfers' => [], 'cash_counts' => [[
            'currency' => 'شيكل', 'daily_box_id' => $this->daily->id,
            'physical_count' => 182, 'float_to_keep' => 182, 'amount_to_transfer' => 0,
        ]]]);
        $pending = $this->correct('return_to_review');
        $this->assertSame('pending', $pending->status);
        $this->assertEquals(182, $this->daily->fresh()->total);
        $this->assertSame(1, BoxLog::count());
    }

    public function test_return_to_review_restores_cash_and_reapproval_does_not_duplicate_it(): void
    {
        $pending = $this->correct('return_to_review');
        $this->assertSame('reversed', $this->closing->fresh()->status);
        $this->assertSame('pending', $pending->status);
        $this->assertSame([], $pending->transfers);
        $this->assertSame('closing_requested', $this->session->fresh()->status);
        $this->assertNull($this->session->fresh()->closed_at);
        $this->assertEquals(2078, $this->daily->fresh()->total);
        $this->assertEquals(3104, $this->old->fresh()->total);
        app(SalesDailySessionService::class)->approveClosing($this->actor, $pending->id,
            [['currency' => 'شيكل', 'to_box_id' => $this->target->id]]);
        $this->assertEquals(182, $this->daily->fresh()->total);
        $this->assertEquals(1996, $this->target->fresh()->total);
        $this->assertSame('closed', $this->session->fresh()->status);
        $this->assertSame(3, BoxLog::count());
    }

    public function test_newer_session_blocks_return_to_review_but_allows_redirect(): void
    {
        SalesDailySession::create(['user_id' => 70, 'session_type' => 'instant_sales', 'business_date' => '2026-10-28', 'status' => 'open']);
        $this->assertFalse(app(SalesDailyTransferCorrectionService::class)->canReturnToReview($this->session));
        try {
            $this->correct('return_to_review');
            $this->fail('Expected newer session rejection');
        } catch (ValidationException) {
        }
        $this->assertSame('approved', $this->closing->fresh()->status);
        $this->correct();
        $this->assertEquals(182, $this->daily->fresh()->total);
    }

    public function test_insufficient_balance_rolls_back_every_change(): void
    {
        $this->old->update(['total' => 100]);
        try {
            $this->correct();
            $this->fail('Expected insufficient cash rejection');
        } catch (ValidationException) {
        }
        $this->assertEquals(100, $this->old->fresh()->total);
        $this->assertEquals(100, $this->target->fresh()->total);
        $this->assertSame('approved', $this->closing->fresh()->status);
        $this->assertSame(1, SalesDailyClosingRequest::count());
        $this->assertSame(1, BoxLog::count());
    }

    public function test_changed_daily_float_blocks_reversal(): void
    {
        $this->daily->update(['total' => 180]);
        $this->expectException(ValidationException::class);
        $this->correct('return_to_review');
    }

    public function test_invalid_currency_and_daily_targets_are_rejected(): void
    {
        foreach (['دولار', 'شيكل'] as $currency) {
            $this->target->update(['currency' => $currency, 'type' => $currency === 'شيكل' ? 'daily_sales' : null]);
            try {
                $this->correct();
                $this->fail('Expected invalid target rejection');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, SalesDailyClosingRequest::count());
    }

    public function test_missing_permission_and_reason_are_rejected(): void
    {
        foreach (['', 'reason'] as $reason) {
            $actor = $reason === '' ? $this->actor : new User(['type' => 'customer']);
            try {
                app(SalesDailyTransferCorrectionService::class)->correct($actor, $this->closing->id, 'return_to_review', [], $reason);
                $this->fail('Expected permission or reason rejection');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('approved', $this->closing->fresh()->status);
    }
}
