<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
            $table->string('source')->default('employee')->index()->after('id');
            $table->foreignId('requester_user_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->string('context_type')->default('general')->after('employee_suggestion_id');
            $table->foreignId('online_store_listing_id')->nullable()->after('context_type')
                ->constrained('online_store_listings')->nullOnDelete();
            $table->json('context_snapshot')->nullable()->after('online_store_listing_id');
            $table->unsignedInteger('requester_unread_count')->default(0)->after('employee_unread_count');
            $table->timestamp('first_support_response_at')->nullable()->after('last_message_at');
            $table->timestamp('last_requester_message_at')->nullable()->after('first_support_response_at');
            $table->timestamp('last_support_message_at')->nullable()->after('last_requester_message_at');

            $table->index(['source', 'status', 'last_message_at'], 'support_source_status_last_idx');
            $table->index(['requester_user_id', 'status'], 'support_requester_status_idx');
            $table->index(
                ['requester_user_id', 'online_store_listing_id', 'status'],
                'support_requester_listing_status_idx'
            );
        });

        DB::statement(<<<'SQL'
            UPDATE support_conversations sc
            LEFT JOIN employee_details ed ON ed.id = sc.employee_id
            SET sc.requester_user_id = COALESCE(ed.user_id, sc.created_by_user_id),
                sc.requester_unread_count = sc.employee_unread_count
            WHERE sc.source = 'employee'
        SQL);

        Schema::table('support_messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable()->after('support_conversation_id');
            $table->unique(
                ['support_conversation_id', 'client_message_id'],
                'support_messages_conversation_client_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropUnique('support_messages_conversation_client_unique');
            $table->dropColumn('client_message_id');
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropIndex('support_source_status_last_idx');
            $table->dropIndex('support_requester_status_idx');
            $table->dropIndex('support_requester_listing_status_idx');
            $table->dropConstrainedForeignId('online_store_listing_id');
            $table->dropConstrainedForeignId('requester_user_id');
            $table->dropColumn([
                'source',
                'context_type',
                'context_snapshot',
                'requester_unread_count',
                'first_support_response_at',
                'last_requester_message_at',
                'last_support_message_at',
            ]);
        });
    }
};
