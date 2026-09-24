<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('box_logs')) {
            return;
        }

        Schema::table('box_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('box_logs', 'reason_code')) {
                $table->string('reason_code', 80)->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('box_logs', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('reason_code')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('box_logs')) {
            return;
        }

        Schema::table('box_logs', function (Blueprint $table) {
            $columns = collect(['reason_code', 'created_by'])
                ->filter(fn (string $column) => Schema::hasColumn('box_logs', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
