<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class OnlineStorePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'Online Store View',
        'Online Store Products Manage',
        'Online Store Categories Manage',
        'Online Store Content Manage',
        'Online Store Promotions Manage',
        'Online Store Reviews Manage',
        'Online Store Settings Manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->updateOrCreate(
                ['name_en' => $permission],
                ['name' => $permission, 'grant_policy' => 'permissions_manage']
            );
        }
    }
}
