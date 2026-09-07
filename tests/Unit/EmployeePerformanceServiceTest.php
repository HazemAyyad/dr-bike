<?php

namespace Tests\Unit;

use App\Models\EmployeeDetail;
use App\Services\EmployeePerformanceService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeePerformanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('status');
            $table->boolean('is_canceled')->default(false);
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->dateTime('start_time');
            $table->timestamps();
        });

        Schema::create('employee_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('legacy_task_id')->nullable();
            $table->string('status');
            $table->boolean('is_canceled')->default(false);
            $table->dateTime('start_time');
            $table->date('scheduled_date')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_points_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedInteger('points');
            $table->string('operation_type');
            $table->string('reason')->nullable();
            $table->date('points_date')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_combines_employee_tasks_and_fixed_occurrences_in_monthly_score_and_chart(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');
        DB::table('employee_tasks')->insert([
            'employee_id' => 7,
            'status' => 'completed',
            'start_time' => '2026-09-03 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_task_occurrences')->insert([
            'employee_id' => 7,
            'status' => 'pending',
            'start_time' => '2026-09-04 09:00:00',
            'scheduled_date' => '2026-09-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_points_logs')->insert([
            [
                'employee_id' => 7,
                'points' => 12,
                'operation_type' => 'add',
                'reason' => 'إنجاز ممتاز',
                'points_date' => '2026-09-05',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => 7,
                'points' => 3,
                'operation_type' => 'deduct',
                'reason' => 'تأخير',
                'points_date' => '2026-09-06',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $employee = new EmployeeDetail(['user_id' => 70]);
        $employee->id = 7;
        $employee->exists = true;
        $report = app(EmployeePerformanceService::class)->report($employee, 'monthly');
        $tasks = collect($report['sections'])->firstWhere('key', 'tasks');

        $this->assertSame(50.0, $tasks['score']);
        $this->assertSame(2, $tasks['metrics']['total']);
        $this->assertSame(1, $tasks['metrics']['employee_tasks']);
        $this->assertSame(1, $tasks['metrics']['fixed_tasks']);
        $this->assertCount(7, $report['monthly_trend']['points']);
        $this->assertSame(100.0, $report['monthly_trend']['points'][2]['score']);
        $this->assertSame(0.0, $report['monthly_trend']['points'][3]['score']);
        $this->assertSame(12, $report['points_summary']['earned_points']);
        $this->assertSame(3, $report['points_summary']['deducted_points']);
        $this->assertSame(9, $report['points_summary']['net_points']);
        $this->assertSame('تأخير', $report['points_summary']['recent_movements'][0]['label']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
