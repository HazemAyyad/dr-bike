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
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'facebook_username')) {
                $table->string('facebook_username')->nullable();
            }
            if (! Schema::hasColumn('customers', 'facebook_link')) {
                $table->string('facebook_link')->nullable();
            }
            if (! Schema::hasColumn('customers', 'instagram_username')) {
                $table->string('instagram_username')->nullable();
            }
            if (! Schema::hasColumn('customers', 'instagram_link')) {
                $table->string('instagram_link')->nullable();
            }
            if (! Schema::hasColumn('customers', 'related_people')) {
                $table->string('related_people')->nullable();
            }
            if (! Schema::hasColumn('customers', 'ID_image')) {
                $table->string('ID_image')->nullable();
            }
            if (! Schema::hasColumn('customers', 'license_image')) {
                $table->string('license_image')->nullable();
            }
            if (! Schema::hasColumn('customers', 'work_address')) {
                $table->string('work_address')->nullable();
            }
            if (! Schema::hasColumn('customers', 'relative_phone')) {
                $table->string('relative_phone')->nullable();
            }
            if (! Schema::hasColumn('customers', 'relative_job_title')) {
                $table->string('relative_job_title')->nullable();
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            //
        });
    }
};
