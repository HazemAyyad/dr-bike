<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'buyer_type')) {
                $table->string('buyer_type', 20)
                    ->nullable()
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->after('type');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_id')) {
                $table->unsignedBigInteger('buyer_id')->nullable()->after('buyer_type');
                $table->foreign('buyer_id')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_name')) {
                $table->string('buyer_name')
                    ->nullable()
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->after('buyer_id');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_phone')) {
                $table->string('buyer_phone', 50)
                    ->nullable()
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->after('buyer_name');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_address')) {
                $table->text('buyer_address')
                    ->nullable()
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->after('buyer_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'buyer_id')) {
                $table->dropForeign(['buyer_id']);
            }
            foreach (['buyer_address', 'buyer_phone', 'buyer_name', 'buyer_id', 'buyer_type'] as $col) {
                if (Schema::hasColumn('instant_sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
