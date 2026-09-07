<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('automation_flow', 32)->nullable()->after('unread_count')->index();
            $table->string('automation_step', 64)->nullable()->after('automation_flow');
            $table->json('automation_data')->nullable()->after('automation_step');
            $table->timestamp('automation_started_at')->nullable()->after('automation_data');
            $table->timestamp('automation_completed_at')->nullable()->after('automation_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['automation_flow']);
            $table->dropColumn([
                'automation_flow',
                'automation_step',
                'automation_data',
                'automation_started_at',
                'automation_completed_at',
            ]);
        });
    }
};
