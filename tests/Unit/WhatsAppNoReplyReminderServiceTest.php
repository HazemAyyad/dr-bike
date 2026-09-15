<?php

namespace Tests\Unit;

use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Services\WhatsApp\WhatsAppNoReplyReminderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WhatsAppNoReplyReminderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_account_id');
            $table->string('phone');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_conversation_id');
            $table->string('direction');
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();
        });
        Schema::create('whatsapp_no_reply_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_conversation_id');
            $table->unsignedBigInteger('inbound_message_id')->unique();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_prompts_an_unanswered_customer_between_23_and_24_hours(): void
    {
        $conversation = $this->conversation();
        $inboundId = DB::table('whatsapp_messages')->insertGetId([
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'is_automatic' => false,
            'created_at' => now()->subHours(23)->subMinute(),
            'updated_at' => now()->subHours(23)->subMinute(),
        ]);
        DB::table('whatsapp_messages')->insert([
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_automatic' => true,
            'created_at' => now()->subHours(23),
            'updated_at' => now()->subHours(23),
        ]);
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('forAccount')->once()->andReturnSelf();
        $api->shouldReceive('sendReplyButtons')
            ->once()
            ->withArgs(fn ($phone, $body, $buttons) =>
                $phone === '970599000000'
                && str_contains($body, 'هل ما زلت بحاجة إلى المساعدة؟')
                && $buttons[0]['id'] === 'flow:no_reply:yes'
                && $buttons[1]['id'] === 'flow:no_reply:no')
            ->andReturn([]);

        $stats = (new WhatsAppNoReplyReminderService)->sendDue($api);

        $this->assertSame(['eligible' => 1, 'sent' => 1, 'failed' => 0], $stats);
        $this->assertDatabaseHas('whatsapp_no_reply_reminders', [
            'inbound_message_id' => $inboundId,
            'status' => 'sent',
        ]);
    }

    public function test_it_does_not_prompt_after_a_human_employee_reply(): void
    {
        $conversation = $this->conversation();
        DB::table('whatsapp_messages')->insert([
            [
                'whatsapp_conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'is_automatic' => false,
                'created_at' => now()->subHours(23)->subMinute(),
                'updated_at' => now()->subHours(23)->subMinute(),
            ],
            [
                'whatsapp_conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'is_automatic' => false,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
        ]);
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldNotReceive('forAccount');
        $api->shouldNotReceive('sendReplyButtons');

        $stats = (new WhatsAppNoReplyReminderService)->sendDue($api);

        $this->assertSame(['eligible' => 0, 'sent' => 0, 'failed' => 0], $stats);
    }

    private function conversation(): WhatsAppConversation
    {
        $accountId = DB::table('whatsapp_accounts')->insertGetId([
            'name' => 'Doctor Bike',
            'is_active' => true,
        ]);

        return WhatsAppConversation::query()->create([
            'whatsapp_account_id' => $accountId,
            'phone' => '970599000000',
            'status' => 'open',
        ]);
    }
}
