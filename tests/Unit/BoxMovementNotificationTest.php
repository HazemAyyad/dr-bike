<?php

namespace Tests\Unit;

use App\Models\AdminNotification;
use App\Models\Box;
use App\Models\BoxLog;
use App\Services\AccountingProjectionService;
use App\Services\AdminNotificationService;
use App\Services\BoxMovementNotificationService;
use App\Services\FirebaseService;
use App\Services\NotificationControlService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BoxMovementNotificationTest extends TestCase
{
    private string $originalConnection;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config(['database.default' => 'box_notification_test', 'database.connections.box_notification_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('box_notification_test');
        DB::setDefaultConnection('box_notification_test');

        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('currency')->default('شيكل');
            $table->decimal('total', 14, 4);
            $table->boolean('is_shown')->default(true);
            $table->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            foreach (['box_id', 'from_box_id', 'to_box_id', 'created_by', 'source_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            foreach (['type', 'description', 'note', 'reason_code', 'source_type'] as $column) {
                $table->text($column)->nullable();
            }
            $table->decimal('value', 14, 4)->nullable();
            $table->decimal('transfered_balance', 14, 4)->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        $accounting = \Mockery::mock(AccountingProjectionService::class);
        $accounting->shouldReceive('sync')->andReturnNull();
        $this->app->instance(AccountingProjectionService::class, $accounting);

        $notifications = \Mockery::mock(AdminNotificationService::class, [
            \Mockery::mock(FirebaseService::class), app(NotificationControlService::class),
        ])->makePartial();
        $notifications->shouldReceive('pushToAdminDevices')->andReturnUsing(function (AdminNotification $notification) {
            $this->pushes[] = $notification->data;

            return ['sent' => 1, 'failed' => 0, 'token_count' => 1];
        });
        $this->app->instance(AdminNotificationService::class, $notifications);
    }

    protected function tearDown(): void
    {
        DB::disconnect('box_notification_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    private function box(float $total, ?string $type = null): Box
    {
        return Box::create(['name' => $type ?: 'الرئيسي', 'total' => $total, 'type' => $type, 'currency' => 'شيكل']);
    }

    private function movement(Box $box, float $amount, array $extra = []): BoxLog
    {
        $box->update(['total' => (float) $box->total + $amount]);

        return BoxLog::forceCreate(array_merge([
            'box_id' => $box->id, 'type' => $amount < 0 ? 'minus' : 'add',
            'value' => abs($amount), 'description' => 'حركة تجريبية',
        ], $extra));
    }

    public function test_deposit_and_expense_explain_the_amount_actor_reason_note_and_balances(): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'أحمد']);
        $box = $this->box(100);
        $this->movement($box, 50, ['created_by' => 7, 'reason_code' => 'owner_contribution', 'note' => 'تمويل اليوم']);
        $this->movement($box, -20, ['created_by' => 7, 'description' => 'مصروف كهرباء']);

        $rows = AdminNotification::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertCount(2, $this->pushes);
        $this->assertSame(AdminNotificationService::TYPE_MAIN_BOX_MOVEMENT, $rows[0]->type);
        $this->assertNull($rows[0]->recipient_user_id);
        foreach (['أحمد', 'تمويل اليوم', 'إيداع من المالك', '100.00', '150.00'] as $text) {
            $this->assertStringContainsString($text, $rows[0]->body);
        }
        $this->assertStringContainsString('مصروف كهرباء', $rows[1]->body);
        $this->assertEquals(150, $rows[1]->data['boxes'][0]['balance_before']);
        $this->assertEquals(130, $rows[1]->data['boxes'][0]['balance_after']);
    }

    public function test_transfer_sends_one_notification_with_both_box_effects(): void
    {
        $from = $this->box(70);
        $to = $this->box(50);
        BoxLog::create(['from_box_id' => $from->id, 'to_box_id' => $to->id, 'type' => 'transfer', 'value' => 30]);

        $row = AdminNotification::sole();
        $this->assertCount(1, $this->pushes);
        $this->assertSame('transfer', $row->data['movement_type']);
        $this->assertCount(2, $row->data['boxes']);
        $this->assertEquals(100, $row->data['boxes'][0]['balance_before']);
        $this->assertEquals(20, $row->data['boxes'][1]['balance_before']);
    }

    public function test_daily_boxes_are_excluded_but_their_transfer_to_a_hidden_main_box_is_not(): void
    {
        foreach (['daily_sales', 'daily_sales_orders', 'daily_maintenance'] as $type) {
            $daily = $this->box(100, $type);
            $this->movement($daily, 20);
        }
        $this->assertSame(0, AdminNotification::count());
        $main = $this->box(120);
        $main->update(['is_shown' => false]);
        BoxLog::create(['from_box_id' => $daily->id, 'to_box_id' => $main->id, 'type' => 'transfer', 'value' => 20]);

        $data = AdminNotification::sole()->data;
        $this->assertCount(1, $data['boxes']);
        $this->assertSame($main->id, $data['boxes'][0]['box_id']);
    }

    public function test_notifications_wait_for_outer_commit_and_keep_each_movements_own_balance(): void
    {
        $box = $this->box(100);
        DB::beginTransaction();
        $this->movement($box, 10);
        DB::transaction(fn () => $this->movement($box, -5));
        $this->assertSame(0, AdminNotification::count());
        $this->assertCount(0, $this->pushes);
        DB::commit();

        $rows = AdminNotification::orderBy('related_id')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(110, $rows[0]->data['boxes'][0]['balance_after']);
        $this->assertEquals(105, $rows[1]->data['boxes'][0]['balance_after']);
    }

    public function test_rolled_back_movements_do_not_notify_even_after_a_later_successful_commit(): void
    {
        $box = $this->box(100);
        DB::beginTransaction();
        $this->movement($box, 20);
        DB::rollBack();
        DB::transaction(fn () => $this->movement($box->fresh(), 5));

        $this->assertSame(1, AdminNotification::count());
        $this->assertCount(1, $this->pushes);
        $this->assertEquals(105, $this->pushes[0]['boxes'][0]['balance_after']);
    }

    public function test_signed_legacy_values_and_zero_movements_are_handled(): void
    {
        $box = $this->box(100);
        $this->movement($box, -12.25, ['type' => 'payment', 'value' => -12.25]);
        $this->movement($box, 3, ['value' => null, 'transfered_balance' => 3]);
        $this->movement($box, 0);
        $this->assertSame(2, AdminNotification::count());
        $this->assertSame('withdrawal', $this->pushes[0]['movement_type']);
        $this->assertEquals(100, $this->pushes[0]['boxes'][0]['balance_before']);
        $this->assertEquals(90.75, $this->pushes[1]['boxes'][0]['balance_after']);
    }

    public function test_unlogged_reversal_is_notified_without_changing_the_ledger(): void
    {
        $box = $this->box(100);
        DB::transaction(function () use ($box) {
            $box->increment('total', 20);
            app(BoxMovementNotificationService::class)->balanceChanged($box->fresh(), 20, 'عكس صرف شيك', 'outgoing_check', 42);
        });
        $row = AdminNotification::sole();
        $this->assertStringContainsString('عكس صرف شيك', $row->body);
        $this->assertSame(42, $row->related_id);
        $this->assertEquals(100, $row->data['boxes'][0]['balance_before']);
        $this->assertEquals(120, $box->fresh()->total);
        $this->assertSame(0, BoxLog::count());
    }

    public function test_notification_failure_does_not_fail_or_reverse_the_financial_operation(): void
    {
        $this->app->instance(AdminNotificationService::class, \Mockery::mock(AdminNotificationService::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Delivery unavailable'));
        }));
        $box = $this->box(100);
        DB::transaction(fn () => $this->movement($box, -10));
        $this->assertEquals(90, $box->fresh()->total);
        $this->assertSame(1, BoxLog::count());
    }
}
