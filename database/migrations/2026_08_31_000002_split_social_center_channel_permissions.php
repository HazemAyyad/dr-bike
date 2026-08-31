<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const CHANNELS = [
        'Social Center WhatsApp' => 'واتساب',
        'Social Center Facebook' => 'فيسبوك',
        'Social Center Instagram' => 'إنستغرام',
    ];

    public function up(): void
    {
        foreach (self::CHANNELS as $nameEn => $name) {
            DB::table('permissions')->updateOrInsert(
                ['name_en' => $nameEn],
                ['name' => $name, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $mainId = DB::table('permissions')->where('name_en', 'Messages Section')->value('id');
        if (! $mainId) return;

        $employeeIds = DB::table('employee_permissions')
            ->where('permission_id', $mainId)
            ->pluck('employee_id');
        $channelIds = DB::table('permissions')->whereIn('name_en', array_keys(self::CHANNELS))->pluck('id');
        foreach ($employeeIds as $employeeId) {
            foreach ($channelIds as $permissionId) {
                DB::table('employee_permissions')->updateOrInsert([
                    'employee_id' => $employeeId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name_en', array_keys(self::CHANNELS))->pluck('id');
        DB::table('employee_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
