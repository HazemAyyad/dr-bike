<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pictures')) {
            return;
        }

        Schema::table('pictures', function (Blueprint $table) {
            if (! Schema::hasColumn('pictures', 'is_cancelled')) {
                $table->boolean('is_cancelled')->default(false)->index();
            }
            if (! Schema::hasColumn('pictures', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('pictures', 'cancelled_by_user_id')) {
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $table->foreign('cancelled_by_user_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Official-paper history is intentionally retained.
    }
};
