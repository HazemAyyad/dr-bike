<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations already use these model/API column names.
        // The original fresh-install migration used snake_case instead.
        if (Schema::hasTable('categories')) {
            foreach ([
                'name_ar' => 'nameAr', 'name_eng' => 'nameEng', 'name_abree' => 'nameAbree',
                'description_ar' => 'descriptionAr', 'description_eng' => 'descriptionEng',
                'description_abree' => 'descriptionAbree', 'image_url' => 'imageUrl',
                'is_show' => 'isShow', 'user_add' => 'userAdd', 'date_add' => 'dateAdd',
                'user_edit' => 'userEdit', 'date_edit' => 'dateEdit',
            ] as $old => $current) {
                if (Schema::hasColumn('categories', $old) && ! Schema::hasColumn('categories', $current)) {
                    Schema::table('categories', fn (Blueprint $table) => $table->renameColumn($old, $current));
                }
            }
        }

        if (! Schema::hasTable('products')) {
            return;
        }
        foreach (['project_id', 'min_sale_price', 'min_stock', 'is_sold_with_paper', 'rotation_date'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                continue;
            }
            Schema::table('products', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'project_id' => $table->unsignedBigInteger($column)->nullable()->index(),
                    'min_sale_price' => $table->decimal($column, 14, 4)->nullable(),
                    'min_stock' => $table->integer($column)->default(0),
                    'is_sold_with_paper' => $table->boolean($column)->default(false),
                    'rotation_date' => $table->date($column)->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        // Older application revisions also expect these columns. Preserve
        // category names and product metadata when rolling application code back.
    }
};
