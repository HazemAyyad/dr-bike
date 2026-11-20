<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InventoryWebUser extends Command
{
    protected $signature = 'inventory-web:user {email} {--role=counter} {--name=}';

    protected $description = 'Create a dedicated inventory web account with a generated password (never modifies an existing account).';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $role = $this->option('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['admin', 'counter'], true)) {
            $this->error('Valid email and role admin/counter required.');

            return self::FAILURE;
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('Account already exists; no changes made.');

            return self::FAILURE;
        }
        $password = Str::password(20);
        $user = new User;
        $user->forceFill(['name' => $this->option('name') ?: ($role === 'admin' ? 'مسؤول الجرد' : 'موظف الجرد'), 'email' => $email, 'type' => 'inventory', 'inventory_web_role' => $role, 'is_blocked' => false, 'password' => Hash::make($password)])->save();
        if (! DB::table('inventory_count_sessions')->exists()) {
            DB::table('inventory_count_sessions')->insert(['name' => 'جرد '.now()->format('Y-m'), 'status' => 'open', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->line(json_encode(['email' => $email, 'password' => $password, 'role' => $role], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
