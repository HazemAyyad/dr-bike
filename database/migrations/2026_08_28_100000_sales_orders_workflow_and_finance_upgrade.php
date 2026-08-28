<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_addresses')) {
            $hasCities = Schema::hasTable('cities');
            Schema::create('partner_addresses', function (Blueprint $table) use ($hasCities) {
                $table->id();
                $table->morphs('addressable');
                $table->string('label')->default('العنوان الرئيسي');
                $cityId = $table->unsignedBigInteger('city_id')->nullable();
                if ($hasCities) {
                    $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
                }
                $table->unsignedBigInteger('shiply_city_id')->nullable();
                $table->unsignedBigInteger('shiply_village_id')->nullable();
                $table->string('shiply_city_name')->nullable();
                $table->string('shiply_village_name')->nullable();
                $table->string('street_address', 500)->default('----');
                $table->string('phone', 50)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('delivery_notes')->nullable();
                $table->boolean('is_default')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['addressable_type', 'addressable_id', 'is_default'], 'partner_addresses_default_idx');
            });
        }

        if (Schema::hasTable('sales_orders')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_orders', 'partner_address_id')) {
                    $table->foreignId('partner_address_id')->nullable()->after('customer_address')
                        ->constrained('partner_addresses')->nullOnDelete();
                }
                if (! Schema::hasColumn('sales_orders', 'partner_type')) {
                    $table->string('partner_type', 30)->nullable()->after('customer_id');
                    $table->unsignedBigInteger('partner_id')->nullable()->after('partner_type');
                    $table->index(['partner_type', 'partner_id'], 'sales_orders_partner_idx');
                }
                if (! Schema::hasColumn('sales_orders', 'address_snapshot')) {
                    $table->json('address_snapshot')->nullable()->after('partner_address_id');
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_previous_status')) {
                    $table->string('stuck_previous_status', 40)->nullable()->after('status');
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_type')) {
                    $table->string('stuck_type', 50)->nullable()->after('stuck_previous_status');
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_reason')) {
                    $table->text('stuck_reason')->nullable()->after('stuck_type');
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_assigned_to')) {
                    $table->foreignId('stuck_assigned_to')->nullable()->after('stuck_reason')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_follow_up_at')) {
                    $table->timestamp('stuck_follow_up_at')->nullable()->after('stuck_assigned_to');
                }
                if (! Schema::hasColumn('sales_orders', 'stuck_resolved_at')) {
                    $table->timestamp('stuck_resolved_at')->nullable()->after('stuck_follow_up_at');
                }
                if (! Schema::hasColumn('sales_orders', 'customer_debt_balance')) {
                    $table->decimal('customer_debt_balance', 14, 2)->default(0)->after('total');
                }
                if (! Schema::hasColumn('sales_orders', 'carrier_receivable_balance')) {
                    $table->decimal('carrier_receivable_balance', 14, 2)->default(0)->after('customer_debt_balance');
                }
            });
        }

        if (! Schema::hasTable('sales_order_settlements')) {
            Schema::create('sales_order_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('sales_daily_session_id')->nullable()->constrained('sales_daily_sessions')->nullOnDelete();
                $table->foreignId('box_id')->nullable()->constrained('boxes')->nullOnDelete();
                $table->string('source', 40)->default('carrier');
                $table->decimal('amount', 14, 2);
                $table->decimal('customer_debt_before', 14, 2)->default(0);
                $table->decimal('customer_debt_after', 14, 2)->default(0);
                $table->decimal('carrier_receivable_before', 14, 2)->default(0);
                $table->decimal('carrier_receivable_after', 14, 2)->default(0);
                $table->string('idempotency_key', 100)->nullable()->unique();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['sales_order_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('sales_order_stock_shortages')) {
            Schema::create('sales_order_stock_shortages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('sales_order_item_id')->nullable()->constrained('sales_order_items')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->unsignedInteger('requested_qty');
                $table->integer('available_qty');
                $table->unsignedInteger('shortage_qty');
                $table->string('status', 30)->default('open');
                $table->timestamp('last_notified_at')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['sales_order_id', 'product_id', 'size_color_id'], 'sales_order_shortage_unique');
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_stock_shortages');
        Schema::dropIfExists('sales_order_settlements');

        if (Schema::hasTable('sales_orders')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                foreach (['partner_address_id', 'stuck_assigned_to'] as $foreign) {
                    if (Schema::hasColumn('sales_orders', $foreign)) {
                        $table->dropConstrainedForeignId($foreign);
                    }
                }
                foreach ([
                    'partner_type', 'partner_id', 'address_snapshot', 'stuck_previous_status', 'stuck_type', 'stuck_reason',
                    'stuck_follow_up_at', 'stuck_resolved_at', 'customer_debt_balance',
                    'carrier_receivable_balance',
                ] as $column) {
                    if (Schema::hasColumn('sales_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('partner_addresses');
    }
};
