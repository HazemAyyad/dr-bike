<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('destructions')) {
            Schema::table('destructions', function (Blueprint $table) {
                if (! Schema::hasColumn('destructions', 'cost_method')) {
                    $table->string('cost_method', 40)->nullable();
                }
                if (! Schema::hasColumn('destructions', 'unit_cost')) {
                    $table->decimal('unit_cost', 14, 6)->nullable();
                }
                if (! Schema::hasColumn('destructions', 'total_cost')) {
                    $table->decimal('total_cost', 14, 6)->nullable()->index();
                }
                if (! Schema::hasColumn('destructions', 'created_by_user_id')) {
                    $table->unsignedBigInteger('created_by_user_id')->nullable();
                    $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('expenses') && ! Schema::hasColumn('expenses', 'destruction_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('destruction_id')->nullable()->unique();
                $table->foreign('destruction_id')->references('id')->on('destructions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Accounting and inventory history is intentionally retained.
    }
};
