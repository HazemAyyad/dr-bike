<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'last_edit_marked_at')) {
                $table->timestamp('last_edit_marked_at')->nullable()->after('store_section_id');
            }

            if (! Schema::hasColumn('products', 'last_edit_marked_by_user_id')) {
                $table->unsignedBigInteger('last_edit_marked_by_user_id')->nullable()->after('last_edit_marked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'last_edit_marked_by_user_id')) {
                $table->dropColumn('last_edit_marked_by_user_id');
            }

            if (Schema::hasColumn('products', 'last_edit_marked_at')) {
                $table->dropColumn('last_edit_marked_at');
            }
        });
    }
};
