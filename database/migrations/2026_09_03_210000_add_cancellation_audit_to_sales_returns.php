<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->foreignId('replaces_sales_return_id')->nullable()->after('cancellation_reason')->constrained('sales_returns')->nullOnDelete();
            $table->foreignId('replacement_sales_return_id')->nullable()->after('replaces_sales_return_id')->constrained('sales_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_sales_return_id');
            $table->dropConstrainedForeignId('replaces_sales_return_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
