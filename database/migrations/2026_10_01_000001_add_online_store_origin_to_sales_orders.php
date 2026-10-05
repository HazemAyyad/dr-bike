<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'origin')) {
                $table->string('origin', 30)->nullable()->after('serial_number');
            }
            if (! Schema::hasColumn('sales_orders', 'origin_user_id')) {
                $table->foreignId('origin_user_id')->nullable()->after('origin')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_orders', 'client_request_id')) {
                $table->string('client_request_id', 100)->nullable()->after('origin_user_id');
            }
        });

        DB::table('sales_orders')->whereNull('origin')->update(['origin' => 'admin']);

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('origin', 30)->default('admin')->nullable(false)->change();
            $table->index(['origin', 'created_at'], 'sales_orders_origin_created_idx');
            $table->unique(
                ['origin', 'origin_user_id', 'client_request_id'],
                'sales_orders_origin_actor_request_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_origin_actor_request_unique');
            $table->dropIndex('sales_orders_origin_created_idx');
            $table->dropColumn('client_request_id');
            $table->dropConstrainedForeignId('origin_user_id');
            $table->dropColumn('origin');
        });
    }
};
