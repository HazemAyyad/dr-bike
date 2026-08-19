<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bills') && ! Schema::hasColumn('bills', 'status')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('status')->default('unfinished')->after('seller_id');
            });
        }

        if (Schema::hasTable('boxes') && ! Schema::hasColumn('boxes', 'currency')) {
            Schema::table('boxes', function (Blueprint $table) {
                $table->string('currency', 50)->nullable()->after('updated_at');
            });
        }

        if (Schema::hasTable('sizes') && ! Schema::hasColumn('sizes', 'itemId')) {
            Schema::table('sizes', function (Blueprint $table) {
                $table->unsignedBigInteger('itemId')->nullable()->after('id');
            });
            if (Schema::hasColumn('sizes', 'product_id')) {
                DB::table('sizes')->whereNull('itemId')->update(['itemId' => DB::raw('product_id')]);
            }
        }

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('seller_id');
            }
            if (! Schema::hasColumn('bills', 'currency')) {
                $table->string('currency', 20)->default('شيكل')->after('total');
            }
            if (! Schema::hasColumn('bills', 'workflow_status')) {
                $table->string('workflow_status', 40)->default('awaiting_receiving')->after('status');
            }
            if (! Schema::hasColumn('bills', 'final_total')) {
                $table->decimal('final_total', 14, 4)->default(0)->after('total');
            }
            if (! Schema::hasColumn('bills', 'paid_amount')) {
                $table->decimal('paid_amount', 14, 4)->default(0)->after('final_total');
            }
            if (! Schema::hasColumn('bills', 'payment_status')) {
                $table->string('payment_status', 30)->default('unpaid')->after('paid_amount');
            }
            if (! Schema::hasColumn('bills', 'notes')) {
                $table->text('notes')->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('bills', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('bills', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('finalized_at');
            }
            if (! Schema::hasColumn('bills', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            }
        });

        if (Schema::hasTable('bill_items')) {
            Schema::table('bill_items', function (Blueprint $table) {
                if (! Schema::hasColumn('bill_items', 'price')) {
                    $table->decimal('price', 14, 4)->default(0)->after('quantity');
                }
                if (! Schema::hasColumn('bill_items', 'status')) {
                    $table->string('status')->default('unfinished')->after('price');
                }
                if (! Schema::hasColumn('bill_items', 'extra_amount')) {
                    $table->integer('extra_amount')->nullable()->after('status');
                }
                if (! Schema::hasColumn('bill_items', 'missing_amount')) {
                    $table->integer('missing_amount')->nullable()->after('extra_amount');
                }
                if (! Schema::hasColumn('bill_items', 'not_compatible_amount')) {
                    $table->integer('not_compatible_amount')->nullable()->after('missing_amount');
                }
                if (! Schema::hasColumn('bill_items', 'not_compatible_description')) {
                    $table->text('not_compatible_description')->nullable()->after('not_compatible_amount');
                }
            });
        }

        Schema::table('bill_items', function (Blueprint $table) {
            if (! Schema::hasColumn('bill_items', 'ordered_quantity')) {
                $table->decimal('ordered_quantity', 14, 4)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('bill_items', 'received_owned_quantity')) {
                $table->decimal('received_owned_quantity', 14, 4)->default(0)->after('ordered_quantity');
            }
            if (! Schema::hasColumn('bill_items', 'custody_quantity')) {
                $table->decimal('custody_quantity', 14, 4)->default(0)->after('received_owned_quantity');
            }
            if (! Schema::hasColumn('bill_items', 'damaged_quantity')) {
                $table->decimal('damaged_quantity', 14, 4)->default(0)->after('custody_quantity');
            }
            if (! Schema::hasColumn('bill_items', 'mismatched_quantity')) {
                $table->decimal('mismatched_quantity', 14, 4)->default(0)->after('damaged_quantity');
            }
            if (! Schema::hasColumn('bill_items', 'final_unit_price')) {
                $table->decimal('final_unit_price', 14, 4)->nullable()->after('price');
            }
            if (! Schema::hasColumn('bill_items', 'size_id')) {
                $table->unsignedBigInteger('size_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('bill_items', 'size_color_id')) {
                $table->unsignedBigInteger('size_color_id')->nullable()->after('size_id');
            }
        });

        DB::table('bill_items')
            ->where(function ($q) {
                $q->whereNull('ordered_quantity')->orWhere('ordered_quantity', 0);
            })
            ->update(['ordered_quantity' => DB::raw('quantity')]);

        if (! Schema::hasTable('purchase_receipts')) {
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->string('receipt_number')->nullable();
            $table->date('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('purchase_receipt_items')) {
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('bill_item_id')->constrained('bill_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('accepted_quantity', 14, 4)->default(0);
            $table->decimal('missing_quantity', 14, 4)->default(0);
            $table->decimal('extra_quantity', 14, 4)->default(0);
            $table->decimal('damaged_quantity', 14, 4)->default(0);
            $table->decimal('mismatched_quantity', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->string('resolution', 60)->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('purchase_amanat_stocks')) {
        Schema::create('purchase_amanat_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('bill_item_id')->constrained('bill_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->nullOnDelete();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('remaining_quantity', 14, 4)->default(0);
            $table->string('status', 40)->default('in_custody');
            $table->decimal('negotiated_unit_price', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('purchase_price_histories')) {
        Schema::create('purchase_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->foreignId('bill_item_id')->nullable()->constrained('bill_items')->nullOnDelete();
            $table->foreignId('purchase_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->nullOnDelete();
            $table->decimal('unit_price', 14, 4);
            $table->decimal('quantity', 14, 4)->default(0);
            $table->string('currency', 20)->default('شيكل');
            $table->date('priced_at')->nullable();
            $table->boolean('manual_override')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'seller_id'], 'pph_product_seller_idx');
            $table->index(['product_id', 'unit_price'], 'pph_product_price_idx');
        });
        }

        if (! Schema::hasTable('purchase_payments')) {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->decimal('amount', 14, 4);
            $table->string('currency', 20)->default('شيكل');
            $table->string('type', 40)->default('payment');
            $table->date('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('debt_transaction_id')->nullable()->constrained('debt_transactions')->nullOnDelete();
            $table->foreignId('box_log_id')->nullable()->constrained('box_logs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('purchase_attachments')) {
        Schema::create('purchase_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->cascadeOnDelete();
            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('category', 60)->default('evidence');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id'], 'purchase_attachable_idx');
        });
        }

        if (! Schema::hasTable('purchase_activity_logs')) {
        Schema::create('purchase_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bill_id', 'event'], 'purchase_activity_bill_event_idx');
        });
        }

        if (! Schema::hasTable('inventory_cost_layers')) {
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('remaining_quantity', 14, 4);
            $table->decimal('unit_cost', 14, 6);
            $table->string('currency', 20)->default('شيكل');
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'size_color_id', 'remaining_quantity'], 'cost_layers_product_variant_remaining_idx');
        });
        }

        if (! Schema::hasTable('inventory_cost_allocations')) {
        Schema::create('inventory_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_cost_layer_id')->nullable()->constrained('inventory_cost_layers')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 6);
            $table->decimal('total_cost', 14, 6);
            $table->string('method', 40);
            $table->string('reference_type', 80);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'inventory_costing_method'],
            [
                'value' => 'fifo',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_allocations');
        Schema::dropIfExists('inventory_cost_layers');
        Schema::dropIfExists('purchase_activity_logs');
        Schema::dropIfExists('purchase_attachments');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_price_histories');
        Schema::dropIfExists('purchase_amanat_stocks');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
    }
};
