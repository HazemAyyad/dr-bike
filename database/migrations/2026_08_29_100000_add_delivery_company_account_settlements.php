<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach ([
                ['name' => 'إعدادات المبيعات', 'name_en' => 'Sales Settings'],
                ['name' => 'حسابات شركات التوصيل', 'name_en' => 'Delivery Company Accounts'],
            ] as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['name_en' => $permission['name_en']],
                    [...$permission, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        if (! Schema::hasTable('delivery_company_settlement_batches')) {
            Schema::create('delivery_company_settlement_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_company_id')->nullable()->constrained('delivery_companies')->nullOnDelete();
                $table->string('delivery_company_name');
                $table->decimal('amount', 14, 2);
                $table->unsignedInteger('orders_count')->default(0);
                $table->foreignId('sales_daily_session_id')->nullable()->constrained('sales_daily_sessions')->nullOnDelete();
                $table->foreignId('box_id')->nullable()->constrained('boxes')->nullOnDelete();
                $table->string('idempotency_key', 100)->unique();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['delivery_company_id', 'delivery_company_name'], 'delivery_company_batches_account_idx');
            });
        }

        if (Schema::hasTable('sales_order_settlements') &&
            ! Schema::hasColumn('sales_order_settlements', 'delivery_company_settlement_batch_id')) {
            Schema::table('sales_order_settlements', function (Blueprint $table) {
                $table->foreignId('delivery_company_settlement_batch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('delivery_company_settlement_batches')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_settlements') &&
            Schema::hasColumn('sales_order_settlements', 'delivery_company_settlement_batch_id')) {
            Schema::table('sales_order_settlements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('delivery_company_settlement_batch_id');
            });
        }
        Schema::dropIfExists('delivery_company_settlement_batches');
    }
};
