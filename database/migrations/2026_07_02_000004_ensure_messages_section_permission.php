<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Permission::query()->updateOrCreate(
            ['id' => 17],
            ['name' => 'قسم الرسائل', 'name_en' => 'Messages Section']
        );
    }

    public function down(): void
    {
        // This is a shared system permission and must not be removed on rollback.
    }
};
