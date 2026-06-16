<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeAllAttendanceData extends Command
{
    protected $signature = 'attendance:purge-all {--force : Confirm destructive purge}';

    protected $description = 'Delete all attendance records (daily, scans, fingerprint raw logs) — keeps devices and employee mappings';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('This permanently deletes all attendance data. Re-run with --force to confirm.');

            return self::FAILURE;
        }

        $tables = [
            'employee_attendances',
            'employee_attendance_scans',
            'fingerprint_raw_logs',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Skip missing table: {$table}");

                continue;
            }
            $count = (int) DB::table($table)->count();
            $this->line("{$table}: {$count} row(s)");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->info("Truncated {$table}");
            }
        }

        if (Schema::hasTable('attendance_devices')) {
            AttendanceDevice::query()->update([
                'last_sync_at' => null,
                'last_sync_status' => null,
                'last_sync_error' => null,
            ]);
            $this->info('Reset attendance_devices sync metadata');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->info('All attendance data purged. Device configs and employee↔PIN mappings were kept.');
        $this->line('New fingerprint punches will be recorded from scratch.');

        return self::SUCCESS;
    }
}
