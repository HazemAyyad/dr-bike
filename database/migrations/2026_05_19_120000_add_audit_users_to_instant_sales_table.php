<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('instant_sales', 'updated_by')) {
                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
            if (Schema::hasColumn('instant_sales', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};
