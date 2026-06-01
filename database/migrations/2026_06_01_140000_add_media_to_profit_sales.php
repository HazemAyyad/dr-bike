<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('profit_sales', 'image_path')) {
                $table->string('image_path')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('profit_sales', 'video_path')) {
                $table->string('video_path')->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            if (Schema::hasColumn('profit_sales', 'video_path')) {
                $table->dropColumn('video_path');
            }
            if (Schema::hasColumn('profit_sales', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });
    }
};
