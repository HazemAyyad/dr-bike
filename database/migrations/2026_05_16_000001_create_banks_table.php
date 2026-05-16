<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banks')) {
            return;
        }

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortcut')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $defaults = [
            ['name' => 'بنك فلسطين', 'shortcut' => 'ف', 'sort_order' => 1],
            ['name' => 'البنك العقاري المصري', 'shortcut' => 'عق', 'sort_order' => 2],
            ['name' => 'بنك القاهرة عمان', 'shortcut' => 'ق ع', 'sort_order' => 3],
            ['name' => 'بنك الاردن', 'shortcut' => 'ا', 'sort_order' => 4],
            ['name' => 'البنك العربي', 'shortcut' => 'ع', 'sort_order' => 5],
            ['name' => 'بنك الاستثمار', 'shortcut' => 'الاس', 'sort_order' => 6],
            ['name' => 'البنك الاهلي الاردني', 'shortcut' => 'الاه', 'sort_order' => 7],
            ['name' => 'بنك الإسكان', 'shortcut' => 'الإ', 'sort_order' => 8],
            ['name' => 'البنك الإسلامي الفلسطيني', 'shortcut' => 'الإس', 'sort_order' => 9],
            ['name' => 'البنك الإسلامي العربي', 'shortcut' => 'الإسل', 'sort_order' => 10],
            ['name' => 'بنك القدس', 'shortcut' => 'ق', 'sort_order' => 11],
            ['name' => 'بنك الوطني', 'shortcut' => 'و', 'sort_order' => 12],
            ['name' => 'بنك الصفا', 'shortcut' => 'ص', 'sort_order' => 13],
            ['name' => 'كمبيالة', 'shortcut' => 'ك', 'sort_order' => 14],
        ];
        foreach ($defaults as $row) {
            DB::table('banks')->insert(array_merge($row, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
