<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance')) {
            return;
        }

        Schema::table('maintenance', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance', 'service_lines')) {
                $table->json('service_lines')->nullable();
            }
            if (! Schema::hasColumn('maintenance', 'additional_charges')) {
                $table->json('additional_charges')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('maintenance')) {
            return;
        }

        Schema::table('maintenance', function (Blueprint $table) {
            $columns = collect(['service_lines', 'additional_charges'])
                ->filter(fn (string $column) => Schema::hasColumn('maintenance', $column))
                ->values()
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
