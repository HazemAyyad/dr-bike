<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('partnerships', 'department_id')) {
            return;
        }

        Schema::table('partnerships', function (Blueprint $table) {
            if (Schema::hasColumn('partnerships', 'user_id')) {
                $table->dropForeign('partners_user_id_foreign');
                $table->dropColumn('user_id');
            }
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
            $table->unsignedBigInteger('sub_department_id')->nullable();
            $table->foreign('sub_department_id')->references('id')->on('sub_departments')->onDelete('cascade');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->string('status')->default('ongoing');
            $table->string('type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            //
        });
    }
};
