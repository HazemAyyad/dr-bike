<?php

namespace Tests\Unit;

use App\Models\EmployeeDetail;
use App\Services\EmployeePointsService;
use App\Services\EmployeePointRules\EmployeePointRuleEngineService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class EmployeePointRuleEngineServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config()->set('database.connections.point_rules_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('database.default', 'point_rules_test');
        DB::purge('point_rules_test');

        Schema::create('employee_attendance_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->dateTime('scanned_at');
            $table->string('direction');
        });
        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->time('arrived_at')->nullable();
        });
        Schema::create('employee_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->boolean('is_canceled')->default(false);
            $table->date('scheduled_date');
            $table->string('status');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->boolean('is_canceled')->nullable();
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->dateTime('start_time');
            $table->string('status');
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('point_rules_test');
        config()->set('database.default', $this->originalConnection);

        parent::tearDown();
    }

    public function test_attendance_condition_honors_the_employee_start_time_and_grace_period(): void
    {
        DB::table('employee_attendance_scans')->insert([
            'employee_id' => 1,
            'work_date' => '2026-09-07',
            'scanned_at' => '2026-09-07 08:07:00',
            'direction' => 'in',
        ]);

        $result = $this->invoke('employeeAttendedOnTime', [
            $this->employee(),
            Carbon::parse('2026-09-07 00:00:00'),
            Carbon::parse('2026-09-07 23:59:59'),
            ['grace_minutes' => 10],
        ]);

        $this->assertTrue($result['matched']);
        $this->assertSame(1, $result['details']['onTimeDays']);
        $this->assertSame(0, $result['details']['lateDays']);
    }

    public function test_perfect_condition_rejects_an_incomplete_task_even_with_on_time_attendance(): void
    {
        DB::table('employee_attendance_scans')->insert([
            'employee_id' => 1,
            'work_date' => '2026-09-07',
            'scanned_at' => '2026-09-07 08:00:00',
            'direction' => 'in',
        ]);
        DB::table('employee_task_occurrences')->insert([
            'employee_id' => 1,
            'is_canceled' => false,
            'scheduled_date' => '2026-09-07',
            'status' => 'pending',
            'created_at' => '2026-09-07 00:10:00',
            'updated_at' => '2026-09-07 00:10:00',
        ]);

        $result = $this->invoke('employeePerfectAttendanceAndTasks', [
            $this->employee(),
            Carbon::parse('2026-09-07 00:00:00'),
            Carbon::parse('2026-09-07 23:59:59'),
            ['grace_minutes' => 0],
        ]);

        $this->assertFalse($result['matched']);
        $this->assertSame('employee_has_incomplete_tasks', $result['reason']);
        $this->assertSame(1, $result['details']['tasks']['incomplete']);
    }

    private function employee(): EmployeeDetail
    {
        $employee = new EmployeeDetail();
        $employee->id = 1;
        $employee->start_work_time = '08:00:00';
        $employee->weekly_days_off = ['friday'];

        return $employee;
    }

    private function invoke(string $method, array $arguments): array
    {
        $service = new EmployeePointRuleEngineService(Mockery::mock(EmployeePointsService::class));
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
