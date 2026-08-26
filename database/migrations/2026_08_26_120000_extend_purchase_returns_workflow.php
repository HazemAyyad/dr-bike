<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (! Schema::hasColumn('returns', 'number')) $table->string('number', 40)->nullable()->after('id');
            if (! Schema::hasColumn('returns', 'settled_amount')) $table->decimal('settled_amount', 14, 4)->default(0)->after('total');
            if (! Schema::hasColumn('returns', 'reason')) $table->string('reason', 60)->nullable()->after('resolution');
            if (! Schema::hasColumn('returns', 'notes')) $table->text('notes')->nullable()->after('note');
            if (! Schema::hasColumn('returns', 'confirmed_by')) $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('returns', 'delivered_by')) $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('returns', 'settled_by')) $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('returns', 'cancelled_by')) $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('returns', 'confirmed_at')) $table->timestamp('confirmed_at')->nullable();
            if (! Schema::hasColumn('returns', 'settled_at')) $table->timestamp('settled_at')->nullable();
            if (! Schema::hasColumn('returns', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('returns', 'cancellation_reason')) $table->text('cancellation_reason')->nullable();
        });

        DB::table('returns')->whereNull('number')->orderBy('id')->eachById(function ($row) {
            DB::table('returns')->where('id', $row->id)->update([
                'number' => 'PRT-'.str_pad((string) $row->id, 7, '0', STR_PAD_LEFT),
            ]);
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'line_total')) $table->decimal('line_total', 14, 4)->default(0)->after('price');
            if (! Schema::hasColumn('purchase_returns', 'reason')) $table->string('reason', 60)->nullable()->after('cost_total');
            if (! Schema::hasColumn('purchase_returns', 'notes')) $table->text('notes')->nullable()->after('note');
        });
        DB::table('purchase_returns')->where('line_total', 0)->update(['line_total' => DB::raw('price * quantity')]);

        if (! Schema::hasTable('purchase_return_settlements')) {
            Schema::create('purchase_return_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
                $table->string('type', 30);
                $table->decimal('amount', 14, 4);
                $table->string('currency', 20);
                $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
                $table->foreignId('box_id')->nullable()->constrained('boxes')->nullOnDelete();
                $table->foreignId('debt_transaction_id')->nullable()->constrained('debt_transactions')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['return_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_settlements');
        // Existing return data is preserved; additive columns intentionally remain.
    }
};
