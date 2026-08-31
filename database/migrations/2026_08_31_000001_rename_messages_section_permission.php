<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('permissions')
            ->where('name_en', 'Messages Section')
            ->update(['name' => 'مركز التواصل الاجتماعي']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name_en', 'Messages Section')
            ->update(['name' => 'قسم الرسائل']);
    }
};
