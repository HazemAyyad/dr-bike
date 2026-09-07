<?php

namespace Tests\Unit;

use App\Models\EmployeeDetail;
use App\Services\AttendanceSalaryService;
use PHPUnit\Framework\TestCase;

class AttendancePayrollEligibilityTest extends TestCase
{
    public function test_unapproved_overtime_is_excluded_from_payroll(): void
    {
        $day = (new AttendanceSalaryService())->payrollEligibleMinutesForDay(600, 480, 0);

        $this->assertSame(480, $day['eligible_worked_minutes']);
        $this->assertSame(0, $day['approved_overtime_minutes']);
        $this->assertSame(120, $day['excluded_overtime_minutes']);
    }

    public function test_only_approved_part_of_overtime_is_eligible(): void
    {
        $day = (new AttendanceSalaryService())->payrollEligibleMinutesForDay(600, 480, 60);

        $this->assertSame(540, $day['eligible_worked_minutes']);
        $this->assertSame(60, $day['approved_overtime_minutes']);
        $this->assertSame(60, $day['excluded_overtime_minutes']);
    }

    public function test_salary_keeps_monthly_shortfall_offset_when_only_approved_overtime_is_supplied(): void
    {
        $employee = new EmployeeDetail([
            'hour_work_price' => 10,
            'overtime_work_price' => 11,
        ]);

        $salary = (new AttendanceSalaryService())->calculateSalary(
            $employee,
            208 * 60,
            13 * 60
        );

        $this->assertSame(2080.0, $salary['normal_salary']);
        $this->assertSame(143.0, $salary['overtime_salary']);
        $this->assertSame(2223.0, $salary['total_salary']);
    }
}
