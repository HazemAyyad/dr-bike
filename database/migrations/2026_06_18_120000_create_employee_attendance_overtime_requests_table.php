<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'employee_attendance_overtime_requests';

    private const FK_EMPLOYEE = 'ea_ot_req_employee_id_fk';

    private const FK_ATTENDANCE = 'ea_ot_req_attendance_id_fk';

    private const FK_REVIEWED_BY = 'ea_ot_req_reviewed_by_fk';

    private const IDX_STATUS_DATE = 'ea_ot_req_status_date_idx';

    private const IDX_EMP_DATE = 'ea_ot_req_emp_date_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $this->defineColumns($table);
                $this->defineConstraints($table);
            });

            return;
        }

        $this->ensureConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function defineColumns(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('employee_id');
        $table->unsignedBigInteger('employee_attendance_id')->nullable();
        $table->date('work_date');
        $table->unsignedInteger('requested_minutes');
        $table->unsignedInteger('approved_minutes')->nullable();
        $table->string('status', 16)->default('pending');
        $table->string('checkout_source', 16)->nullable();
        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->text('admin_note')->nullable();
        $table->timestamps();
    }

    private function defineConstraints(Blueprint $table): void
    {
        $table->foreign('employee_id', self::FK_EMPLOYEE)
            ->references('id')->on('employee_details')->cascadeOnDelete();
        $table->foreign('employee_attendance_id', self::FK_ATTENDANCE)
            ->references('id')->on('employee_attendances')->nullOnDelete();
        $table->foreign('reviewed_by', self::FK_REVIEWED_BY)
            ->references('id')->on('users')->nullOnDelete();

        $table->index(['status', 'work_date'], self::IDX_STATUS_DATE);
        $table->index(['employee_id', 'work_date'], self::IDX_EMP_DATE);
    }

    private function ensureConstraints(): void
    {
        if (! $this->foreignKeyExists(self::FK_EMPLOYEE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('employee_id', self::FK_EMPLOYEE)
                    ->references('id')->on('employee_details')->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists(self::FK_ATTENDANCE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('employee_attendance_id', self::FK_ATTENDANCE)
                    ->references('id')->on('employee_attendances')->nullOnDelete();
            });
        }

        if (! $this->foreignKeyExists(self::FK_REVIEWED_BY)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('reviewed_by', self::FK_REVIEWED_BY)
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $this->indexExists(self::IDX_STATUS_DATE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['status', 'work_date'], self::IDX_STATUS_DATE);
            });
        }

        if (! $this->indexExists(self::IDX_EMP_DATE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['employee_id', 'work_date'], self::IDX_EMP_DATE);
            });
        }
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $row = $connection->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, self::TABLE, $constraintName, 'FOREIGN KEY']
        );

        return $row !== null;
    }

    private function indexExists(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('".self::TABLE."')");

            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();

        $row = $connection->selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, self::TABLE, $indexName]
        );

        return $row !== null;
    }
};
