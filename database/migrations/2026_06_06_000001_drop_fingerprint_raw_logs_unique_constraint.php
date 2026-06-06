<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fingerprint_raw_logs')) {
            return;
        }

        Schema::table('fingerprint_raw_logs', function (Blueprint $table) {
            if ($this->indexExists('fingerprint_raw_logs', 'frl_device_user_time_unique')) {
                $table->dropUnique('frl_device_user_time_unique');
            }
        });

        Schema::table('fingerprint_raw_logs', function (Blueprint $table) {
            if (! $this->indexExists('fingerprint_raw_logs', 'frl_device_user_time_index')) {
                $table->index(
                    ['attendance_device_id', 'device_user_id', 'scan_time'],
                    'frl_device_user_time_index'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fingerprint_raw_logs')) {
            return;
        }

        Schema::table('fingerprint_raw_logs', function (Blueprint $table) {
            if ($this->indexExists('fingerprint_raw_logs', 'frl_device_user_time_index')) {
                $table->dropIndex('frl_device_user_time_index');
            }
        });

        Schema::table('fingerprint_raw_logs', function (Blueprint $table) {
            if (! $this->indexExists('fingerprint_raw_logs', 'frl_device_user_time_unique')) {
                $table->unique(
                    ['attendance_device_id', 'device_user_id', 'scan_time'],
                    'frl_device_user_time_unique'
                );
            }
        });
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return ((int) ($result[0]->c ?? 0)) > 0;
    }
};
