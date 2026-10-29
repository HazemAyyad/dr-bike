<?php

namespace Tests\Unit;

use App\Models\CronJobLog;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTaskTemplate;
use App\Services\AttendanceSalaryService;
use App\Services\CronJobLogger;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use Carbon\Carbon;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class FrameworkIntervalCompatibilityTest extends TestCase
{
    public function test_finished_jobs_keep_positive_integer_durations_with_carbon_three(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 12:01:30'));
        foreach ([false, true] as $failed) {
            $log = Mockery::mock(CronJobLog::class)->makePartial();
            $log->forceFill(['started_at' => now()->subSeconds(90)]);
            $log->shouldReceive('update')->once()->with(Mockery::on(fn ($values) =>
                $values['duration_seconds'] === 90
                && $values['status'] === ($failed ? 'failed' : 'success')))->andReturn(true);
            $logger = new CronJobLogger;
            if ($failed) {
                $logger->finishFailed($log, 'Fixture failure');
            } else {
                $logger->finishSuccess($log);
            }
        }
        $this->travelBack();
    }

    public function test_daily_interval_recurrence_preserves_whole_calendar_days_across_dst(): void
    {
        $service = new EmployeeTaskRecurrenceService(new EmployeeTaskTimelineService);
        $method = new ReflectionMethod($service, 'matchesDailyInterval');
        $template = new EmployeeTaskTemplate;
        $config = ['anchor_date' => '2026-10-24', 'interval' => 2];
        $this->assertTrue($method->invoke($service, $template, Carbon::parse('2026-10-26', 'Asia/Hebron'), $config));
        $this->assertFalse($method->invoke($service, $template, Carbon::parse('2026-10-27', 'Asia/Hebron'), $config));
    }

    public function test_attendance_truncates_each_segment_before_totaling_payroll_minutes(): void
    {
        $scans = collect([
            new EmployeeAttendanceScan(['direction' => 'in', 'scanned_at' => '2026-10-29 08:00:00']),
            new EmployeeAttendanceScan(['direction' => 'out', 'scanned_at' => '2026-10-29 08:01:59']),
            new EmployeeAttendanceScan(['direction' => 'in', 'scanned_at' => '2026-10-29 08:03:58']),
            new EmployeeAttendanceScan(['direction' => 'out', 'scanned_at' => '2026-10-29 08:05:57']),
        ]);

        $this->assertSame(2, EmployeeAttendanceScan::computeWorkedMinutes($scans));
        $this->assertSame(1, EmployeeAttendanceScan::computeAwayMinutes($scans));
        $this->assertSame(2, EmployeeAttendanceScan::computeWorkedMinutesAsOf(
            $scans->take(3), Carbon::parse('2026-10-29 08:05:57')
        ));
    }

    public function test_overtime_grace_keeps_the_existing_whole_minute_boundary(): void
    {
        $service = new class extends AttendanceSalaryService {
            public function overtimeGraceMinutesForDate(string|Carbon|null $workDate = null): int
            {
                return 15;
            }
        };
        $employee = new EmployeeDetail(['end_work_time' => '17:00:00']);
        $withinGrace = $service->countedCheckoutAt($employee, '2026-10-29', Carbon::parse('2026-10-29 17:15:59'));
        $afterGrace = $service->countedCheckoutAt($employee, '2026-10-29', Carbon::parse('2026-10-29 17:16:00'));

        $this->assertSame('17:00:00', $withinGrace->format('H:i:s'));
        $this->assertSame('17:16:00', $afterGrace->format('H:i:s'));
    }
}
