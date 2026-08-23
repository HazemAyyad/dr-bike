<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasColumn('goals', 'current_value')) {
                $table->decimal('current_value', 14, 4)->default(0)->after('targeted_value');
            }

            if (! Schema::hasColumn('goals', 'calculation_mode')) {
                $table->string('calculation_mode', 20)->default('total')->after('type');
            }

            if (! Schema::hasColumn('goals', 'form')) {
                $table->string('form', 50)->nullable()->after('notes');
            }

            if (! Schema::hasColumn('goals', 'scope')) {
                $table->string('scope', 20)->default('public')->after('form');
            }

            if (! Schema::hasColumn('goals', 'start_date')) {
                $table->date('start_date')->nullable()->after('scope');
            }

            if (! Schema::hasColumn('goals', 'due_date')) {
                $table->date('due_date')->nullable()->after('start_date');
            }

            if (! Schema::hasColumn('goals', 'seller_id')) {
                $table->unsignedBigInteger('seller_id')->nullable()->after('customer_id');
            }

            if (! Schema::hasColumn('goals', 'box_id')) {
                $table->unsignedBigInteger('box_id')->nullable()->after('employee_id');
            }
        });

        if (Schema::hasColumn('goals', 'main_value') && Schema::hasColumn('goals', 'current_value')) {
            DB::table('goals')
                ->where(function ($query) {
                    $query->whereNull('current_value')->orWhere('current_value', 0);
                })
                ->whereNotNull('main_value')
                ->update(['current_value' => DB::raw('main_value')]);
        }
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            foreach (['calculation_mode', 'start_date'] as $column) {
                if (Schema::hasColumn('goals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
