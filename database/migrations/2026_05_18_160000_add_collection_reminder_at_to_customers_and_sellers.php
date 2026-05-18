<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'collection_reminder_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->date('collection_reminder_at')->nullable()->after('notes');
            });
        }

        if (Schema::hasTable('sellers') && ! Schema::hasColumn('sellers', 'collection_reminder_at')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->date('collection_reminder_at')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'collection_reminder_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('collection_reminder_at');
            });
        }

        if (Schema::hasTable('sellers') && Schema::hasColumn('sellers', 'collection_reminder_at')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->dropColumn('collection_reminder_at');
            });
        }
    }
};
