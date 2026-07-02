<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_notification_rules', function (Blueprint $table) {
            $table->string('check_direction', 20)->default('incoming')->after('type');
            $table->string('channel', 20)->default('sms')->after('check_direction');
            $table->string('recipient', 20)->default('check_owner')->after('channel');
            $table->index(['check_direction', 'type', 'is_active'], 'check_notification_direction_idx');
        });

        $legacyRules = DB::table('check_notification_rules')->orderBy('id')->get();

        foreach ($legacyRules as $rule) {
            DB::table('check_notification_rules')
                ->where('id', $rule->id)
                ->update([
                    'check_direction' => 'incoming',
                    'channel' => 'sms',
                    'recipient' => $rule->type === 'before_due' ? 'admin' : 'check_owner',
                ]);

            DB::table('check_notification_rules')->insert([
                'type' => $rule->type,
                'check_direction' => 'outgoing',
                'channel' => 'push',
                'recipient' => 'admin',
                'days' => $rule->days,
                'trigger_mode' => $rule->trigger_mode,
                'send_time' => $rule->send_time,
                'message' => $rule->message,
                'is_active' => $rule->is_active,
                'created_at' => $rule->created_at,
                'updated_at' => $rule->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('check_notification_rules', function (Blueprint $table) {
            $table->dropIndex('check_notification_direction_idx');
            $table->dropColumn(['check_direction', 'channel', 'recipient']);
        });
    }
};
