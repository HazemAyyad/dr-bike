<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['normal_image_products', 'image3d_products', 'view_image_products'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'product_id') && ! Schema::hasColumn($tableName, 'itemId')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->renameColumn('product_id', 'itemId');
                });
            }

            if (Schema::hasColumn($tableName, 'image_url') && ! Schema::hasColumn($tableName, 'imageUrl')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->renameColumn('image_url', 'imageUrl');
                });
            }
        }
    }

    public function down(): void
    {
        // Production uses itemId/imageUrl; rollback must not diverge from that authoritative schema.
    }
};
