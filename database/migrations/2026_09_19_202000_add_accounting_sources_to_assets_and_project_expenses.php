<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'box_id')) {
                $table->unsignedBigInteger('box_id')->nullable()->after('months_number')->index();
            }
            if (! Schema::hasColumn('assets', 'currency')) {
                $table->string('currency', 20)->default('شيكل')->after('box_id');
            }
            if (! Schema::hasColumn('assets', 'acquired_at')) {
                $table->date('acquired_at')->nullable()->after('currency');
            }
        });
        Schema::table('project_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('project_expenses', 'box_id')) {
                $table->unsignedBigInteger('box_id')->nullable()->after('expenses')->index();
            }
            if (! Schema::hasColumn('project_expenses', 'currency')) {
                $table->string('currency', 20)->default('شيكل')->after('box_id');
            }
            if (! Schema::hasColumn('project_expenses', 'expense_date')) {
                $table->date('expense_date')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('project_expenses', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('notes')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $columns = collect(['box_id', 'currency', 'expense_date', 'created_by'])
                ->filter(fn (string $column) => Schema::hasColumn('project_expenses', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
        Schema::table('assets', function (Blueprint $table) {
            $columns = collect(['box_id', 'currency', 'acquired_at'])
                ->filter(fn (string $column) => Schema::hasColumn('assets', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
