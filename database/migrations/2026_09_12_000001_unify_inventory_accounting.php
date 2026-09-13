<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_cost_balances')) {
            Schema::create('inventory_cost_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->string('identity_key', 191)->unique();
                $table->decimal('quantity', 14, 4)->default(0);
                $table->decimal('inventory_value', 18, 6)->default(0);
                $table->decimal('moving_average_unit_cost', 18, 6)->default(0);
                $table->string('currency', 20)->default('شيكل');
                $table->boolean('needs_review')->default(false);
                $table->text('review_reason')->nullable();
                $table->timestamps();
                $table->index(['product_id', 'size_color_id'], 'inventory_balances_product_variant_idx');
            });
        }

        if (! Schema::hasTable('inventory_adjustments')) {
            Schema::create('inventory_adjustments', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 40)->unique();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->string('adjustment_type', 40);
                $table->decimal('stock_before', 14, 4);
                $table->decimal('stock_after', 14, 4);
                $table->decimal('quantity_difference', 14, 4)->default(0);
                $table->decimal('old_unit_cost', 18, 6)->nullable();
                $table->decimal('new_unit_cost', 18, 6)->nullable();
                $table->decimal('old_value', 18, 6)->default(0);
                $table->decimal('new_value', 18, 6)->default(0);
                $table->decimal('value_difference', 18, 6)->default(0);
                $table->string('currency', 20)->default('شيكل');
                $table->string('costing_method', 40);
                $table->string('reason', 120);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['product_id', 'size_color_id', 'created_at'], 'inventory_adjustments_identity_date_idx');
            });
        }

        if (! Schema::hasTable('inventory_cost_revaluation_lines')) {
            Schema::create('inventory_cost_revaluation_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_adjustment_id')->constrained('inventory_adjustments')->cascadeOnDelete();
                $table->foreignId('inventory_cost_layer_id')->nullable()->constrained('inventory_cost_layers')->nullOnDelete();
                $table->decimal('quantity', 14, 4)->default(0);
                $table->decimal('old_unit_cost', 18, 6)->default(0);
                $table->decimal('new_unit_cost', 18, 6)->default(0);
                $table->decimal('old_value', 18, 6)->default(0);
                $table->decimal('new_value', 18, 6)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_cost_reviews')) {
            Schema::create('inventory_cost_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->string('identity_key', 191)->unique();
                $table->decimal('physical_quantity', 14, 4)->default(0);
                $table->decimal('costed_quantity', 14, 4)->default(0);
                $table->decimal('missing_quantity', 14, 4)->default(0);
                $table->string('status', 30)->default('pending');
                $table->string('reason', 120);
                $table->json('evidence')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('inventory_cost_layers')) {
            Schema::table('inventory_cost_layers', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_cost_layers', 'original_unit_cost')) {
                    $table->decimal('original_unit_cost', 18, 6)->nullable()->after('unit_cost');
                }
                if (! Schema::hasColumn('inventory_cost_layers', 'idempotency_key')) {
                    $table->string('idempotency_key', 191)->nullable()->unique()->after('source_id');
                }
            });

            DB::table('inventory_cost_layers')
                ->whereNull('original_unit_cost')
                ->update(['original_unit_cost' => DB::raw('unit_cost')]);
        }

        if (Schema::hasTable('inventory_cost_allocations')) {
            Schema::table('inventory_cost_allocations', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_cost_allocations', 'size_id')) {
                    $table->unsignedBigInteger('size_id')->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('inventory_cost_allocations', 'size_color_id')) {
                    $table->unsignedBigInteger('size_color_id')->nullable()->after('size_id');
                }
            });
        }

        if (Schema::hasTable('product_stock_movements')) {
            Schema::table('product_stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('product_stock_movements', 'costing_method')) {
                    $table->string('costing_method', 40)->nullable()->after('total_cost');
                }
                if (! Schema::hasColumn('product_stock_movements', 'reason')) {
                    $table->string('reason', 120)->nullable()->after('reference_id');
                }
                if (! Schema::hasColumn('product_stock_movements', 'reversal_of_id')) {
                    $table->unsignedBigInteger('reversal_of_id')->nullable()->after('created_by');
                    $table->index('reversal_of_id', 'product_stock_movements_reversal_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_stock_movements')) {
            Schema::table('product_stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('product_stock_movements', 'reversal_of_id')) {
                    $table->dropIndex('product_stock_movements_reversal_idx');
                    $table->dropColumn('reversal_of_id');
                }
                foreach (['reason', 'costing_method'] as $column) {
                    if (Schema::hasColumn('product_stock_movements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('inventory_cost_allocations')) {
            Schema::table('inventory_cost_allocations', function (Blueprint $table) {
                foreach (['size_color_id', 'size_id'] as $column) {
                    if (Schema::hasColumn('inventory_cost_allocations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('inventory_cost_layers')) {
            Schema::table('inventory_cost_layers', function (Blueprint $table) {
                if (Schema::hasColumn('inventory_cost_layers', 'idempotency_key')) {
                    $table->dropUnique('inventory_cost_layers_idempotency_key_unique');
                    $table->dropColumn('idempotency_key');
                }
                if (Schema::hasColumn('inventory_cost_layers', 'original_unit_cost')) {
                    $table->dropColumn('original_unit_cost');
                }
            });
        }

        Schema::dropIfExists('inventory_cost_revaluation_lines');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory_cost_reviews');
        Schema::dropIfExists('inventory_cost_balances');
    }
};
