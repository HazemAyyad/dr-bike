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
                $table->unsignedBigInteger('delivery_company_id')->nullable();
                $table->string('delivery_company_name');
                $table->decimal('amount', 14, 2);
                $table->unsignedInteger('orders_count')->default(0);
                $table->unsignedBigInteger('sales_daily_session_id')->nullable();
                $table->unsignedBigInteger('box_id')->nullable();
                $table->string('idempotency_key', 100)->unique();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['delivery_company_id', 'delivery_company_name'], 'delivery_company_batches_account_idx');
                $table->foreign('delivery_company_id', 'dc_batch_company_fk')
                    ->references('id')->on('delivery_companies')->nullOnDelete();
                $table->foreign('sales_daily_session_id', 'dc_batch_session_fk')
                    ->references('id')->on('sales_daily_sessions')->nullOnDelete();
                $table->foreign('box_id', 'dc_batch_box_fk')
                    ->references('id')->on('boxes')->nullOnDelete();
                $table->foreign('created_by', 'dc_batch_created_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        // A previous deployment may have created the table before MySQL rejected
        // Laravel's generated (too long) constraint name. Complete that table safely.
        $this->ensureMysqlForeignKey(
            'delivery_company_settlement_batches',
            'delivery_company_id',
            'delivery_companies',
            'dc_batch_company_fk'
        );
        $this->ensureMysqlForeignKey(
            'delivery_company_settlement_batches',
            'sales_daily_session_id',
            'sales_daily_sessions',
            'dc_batch_session_fk'
        );
        $this->ensureMysqlForeignKey(
            'delivery_company_settlement_batches',
            'box_id',
            'boxes',
            'dc_batch_box_fk'
        );
        $this->ensureMysqlForeignKey(
            'delivery_company_settlement_batches',
            'created_by',
            'users',
            'dc_batch_created_by_fk'
        );

        if (Schema::hasTable('sales_order_settlements') &&
            ! Schema::hasColumn('sales_order_settlements', 'delivery_company_settlement_batch_id')) {
            Schema::table('sales_order_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_company_settlement_batch_id')
                    ->nullable()
                    ->after('id');
                $table->foreign(
                    'delivery_company_settlement_batch_id',
                    'so_settlement_dc_batch_fk'
                )->references('id')->on('delivery_company_settlement_batches')->nullOnDelete();
            });
        }

        $this->ensureMysqlForeignKey(
            'sales_order_settlements',
            'delivery_company_settlement_batch_id',
            'delivery_company_settlement_batches',
            'so_settlement_dc_batch_fk'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_settlements') &&
            Schema::hasColumn('sales_order_settlements', 'delivery_company_settlement_batch_id')) {
            Schema::table('sales_order_settlements', function (Blueprint $table) {
                $table->dropForeign('so_settlement_dc_batch_fk');
                $table->dropColumn('delivery_company_settlement_batch_id');
            });
        }
        Schema::dropIfExists('delivery_company_settlement_batches');
    }

    private function ensureMysqlForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $constraintName
    ): void {
        if (DB::getDriverName() !== 'mysql' ||
            ! Schema::hasTable($table) ||
            ! Schema::hasTable($referencedTable) ||
            ! Schema::hasColumn($table, $column)) {
            return;
        }

        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $column,
            $referencedTable,
            $constraintName
        ) {
            $blueprint->foreign($column, $constraintName)
                ->references('id')->on($referencedTable)->nullOnDelete();
        });
    }
};
