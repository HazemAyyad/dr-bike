<?php

namespace Tests\Unit;

use App\Models\EmployeeDetail;
use App\Support\EmployeeWorkSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class EmployeeWorkScheduleTest extends TestCase
{
    public function test_it_allows_only_times_inside_a_day_shift(): void
    {
        $employee = $this->employee('08:00:00', '16:00:00');

        $this->assertTrue(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-08-31 10:00', 'Asia/Hebron')));
        $this->assertFalse(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-08-31 16:00', 'Asia/Hebron')));
        $this->assertFalse(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-08-31 07:59', 'Asia/Hebron')));
    }

    public function test_it_supports_an_overnight_shift(): void
    {
        $employee = $this->employee('22:00:00', '06:00:00');

        $this->assertTrue(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-08-31 23:00', 'Asia/Hebron')));
        $this->assertTrue(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-09-01 02:00', 'Asia/Hebron')));
        $this->assertFalse(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-09-01 06:00', 'Asia/Hebron')));
    }

    public function test_it_rejects_days_off_and_missing_schedule(): void
    {
        $employee = $this->employee('08:00:00', '16:00:00', ['monday']);

        $this->assertFalse(EmployeeWorkSchedule::isWithin($employee, Carbon::parse('2026-08-31 10:00', 'Asia/Hebron')));
        $this->assertFalse(EmployeeWorkSchedule::isWithin(new EmployeeDetail(), Carbon::parse('2026-08-31 10:00', 'Asia/Hebron')));
    }

    private function employee(string $start, string $end, array $daysOff = ['friday']): EmployeeDetail
    {
        $employee = new EmployeeDetail();
        $employee->start_work_time = $start;
        $employee->end_work_time = $end;
        $employee->weekly_days_off = $daysOff;

        return $employee;
    }
}
