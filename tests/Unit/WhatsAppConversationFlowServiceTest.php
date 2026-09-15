<?php

namespace Tests\Unit;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Services\WhatsApp\WhatsAppConversationFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WhatsAppConversationFlowServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_suspended')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_en');
            $table->timestamps();
        });
        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
        });

        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_account_id')->nullable();
            $table->unsignedBigInteger('whatsapp_contact_id')->nullable();
            $table->string('phone');
            $table->string('status')->default('open');
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('automation_flow')->nullable();
            $table->string('automation_step')->nullable();
            $table->json('automation_data')->nullable();
            $table->timestamp('automation_started_at')->nullable();
            $table->timestamp('automation_completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->nullable();
            $table->timestamps();
        });
        Schema::create('conversation_taggables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->string('channel');
            $table->unsignedBigInteger('conversation_id');
            $table->timestamps();
            $table->unique(['channel', 'conversation_id', 'tag_id']);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('nameAr');
            $table->boolean('isShow')->default(true);
            $table->integer('sortOrder')->default(0);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('nameAr');
            $table->boolean('isShow')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('meta_catalog_product_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_account_id')->nullable();
            $table->string('catalog_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('meta_catalog_item_id')->nullable();
            $table->string('meta_catalog_retailer_id');
            $table->string('sync_status')->nullable();
            $table->timestamps();
        });
    }

    public function test_sell_is_a_main_option_and_adds_the_sell_tag(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation, 'بيع');
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendInteractiveList')->once()->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle($api, $message, [
            'type' => 'interactive',
            'interactive' => ['list_reply' => ['id' => 'sell', 'title' => 'بيع']],
        ]);

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('sell', $conversation->automation_flow);
        $this->assertSame('item_type', $conversation->automation_step);
        $this->assertDatabaseHas('conversation_tags', ['name' => 'بيع']);
        $this->assertDatabaseHas('conversation_taggables', [
            'channel' => 'whatsapp',
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_maintenance_accepts_non_bicycle_item_types_and_moves_to_issue(): void
    {
        $conversation = $this->conversation([
            'automation_flow' => 'maintenance',
            'automation_step' => 'item_type',
            'automation_data' => [],
        ]);
        $message = $this->message($conversation, 'بطارية');
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendText')->once()->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle($api, $message, [
            'type' => 'interactive',
            'interactive' => ['list_reply' => [
                'id' => 'flow:maintenance:type:battery',
                'title' => 'بطارية',
            ]],
        ]);

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('issue', $conversation->automation_step);
        $this->assertSame('بطارية', $conversation->automation_data['item_type']);
    }

    public function test_products_flow_lists_only_current_synced_catalog_categories(): void
    {
        $conversation = $this->conversation(['whatsapp_account_id' => 7]);
        DB::table('categories')->insert([
            ['id' => 1, 'nameAr' => 'بطاريات', 'isShow' => true, 'sortOrder' => 1],
            ['id' => 2, 'nameAr' => 'مخفي', 'isShow' => false, 'sortOrder' => 2],
        ]);
        DB::table('products')->insert([
            ['id' => 10, 'category_id' => 1, 'nameAr' => 'بطارية', 'isShow' => true],
            ['id' => 20, 'category_id' => 2, 'nameAr' => 'منتج مخفي', 'isShow' => true],
        ]);
        DB::table('meta_catalog_product_syncs')->insert([
            ['whatsapp_account_id' => 7, 'catalog_id' => 'cat', 'product_id' => 10, 'meta_catalog_retailer_id' => 'p10', 'sync_status' => 'synced'],
            ['whatsapp_account_id' => 7, 'catalog_id' => 'cat', 'product_id' => 20, 'meta_catalog_retailer_id' => 'p20', 'sync_status' => 'synced'],
        ]);

        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendInteractiveList')
            ->once()
            ->withArgs(function ($phone, $header, $body, $button, $sections) {
                $titles = collect($sections[0]['rows'])->pluck('title')->all();

                return $phone === '970599000000'
                    && $header === 'تصنيفات المنتجات'
                    && in_array('بطاريات', $titles, true)
                    && ! in_array('مخفي', $titles, true);
            })
            ->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle(
            $api,
            $this->message($conversation, 'المنتجات'),
            [
                'type' => 'interactive',
                'interactive' => ['list_reply' => ['id' => 'products', 'title' => 'المنتجات']],
            ]
        );

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('products', $conversation->automation_flow);
        $this->assertSame('category', $conversation->automation_step);
    }

    public function test_products_flow_sends_synced_variants_from_the_selected_category(): void
    {
        $conversation = $this->conversation([
            'whatsapp_account_id' => 7,
            'automation_flow' => 'products',
            'automation_step' => 'category',
            'automation_data' => [],
        ]);
        DB::table('categories')->insert([
            'id' => 1, 'nameAr' => 'دراجات', 'isShow' => true, 'sortOrder' => 1,
        ]);
        DB::table('products')->insert([
            'id' => 10, 'category_id' => 1, 'nameAr' => 'دراجة متعددة الألوان', 'isShow' => true,
        ]);
        DB::table('meta_catalog_product_syncs')->insert([
            'whatsapp_account_id' => 7,
            'catalog_id' => 'cat',
            'product_id' => 10,
            'variant_id' => 55,
            'meta_catalog_retailer_id' => 'DRBIKE-V-55',
            'sync_status' => 'synced',
        ]);

        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendCatalogProductRetailerIds')
            ->once()
            ->with('970599000000', ['DRBIKE-V-55'])
            ->andReturn([]);
        $api->shouldReceive('sendText')->once()->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle(
            $api,
            $this->message($conversation, 'دراجات'),
            [
                'type' => 'interactive',
                'interactive' => ['list_reply' => [
                    'id' => 'flow:products:category:1',
                    'title' => 'دراجات',
                ]],
            ]
        );

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('awaiting_order', $conversation->automation_step);
        $this->assertSame('دراجات', $conversation->automation_data['category_name']);
    }

    public function test_inquiries_start_their_own_sequence_and_tag(): void
    {
        $conversation = $this->conversation();
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendInteractiveList')->once()->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle(
            $api,
            $this->message($conversation, 'الاستفسارات'),
            [
                'type' => 'interactive',
                'interactive' => ['list_reply' => ['id' => 'inquiries', 'title' => 'الاستفسارات']],
            ]
        );

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('inquiries', $conversation->automation_flow);
        $this->assertSame('topic', $conversation->automation_step);
        $this->assertDatabaseHas('conversation_tags', ['name' => 'استفسار']);
    }

    public function test_employee_option_hands_the_conversation_to_the_team(): void
    {
        $conversation = $this->conversation([
            'automation_flow' => 'maintenance',
            'automation_step' => 'issue',
            'automation_data' => ['item_type' => 'بطارية'],
        ]);
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendText')
            ->once()
            ->with(
                '970599000000',
                'تم تحويل محادثتك إلى فريق خدمة الزبائن، وسيقوم أحد الموظفين بالرد عليك قريبًا.',
                null,
                null,
                true
            )
            ->andReturn([]);

        $handled = app(WhatsAppConversationFlowService::class)->handle(
            $api,
            $this->message($conversation, 'التواصل مع موظف'),
            [
                'type' => 'interactive',
                'interactive' => ['list_reply' => ['id' => 'employee', 'title' => 'التواصل مع موظف']],
            ]
        );

        $this->assertTrue($handled);
        $conversation->refresh();
        $this->assertSame('pending', $conversation->status);
        $this->assertNull($conversation->automation_flow);
        $this->assertTrue($conversation->automation_data['handoff']);
        $this->assertDatabaseHas('conversation_tags', ['name' => 'تواصل مع موظف']);
    }

    public function test_sending_after_handoff_reuses_the_pending_conversation(): void
    {
        $contactId = DB::table('whatsapp_contacts')->insertGetId([
            'phone' => '970599000000',
        ]);
        $conversation = $this->conversation([
            'whatsapp_contact_id' => $contactId,
            'status' => 'pending',
        ]);

        $resolved = (new WhatsAppCloudApiService)->findOrCreateConversation('970599000000');

        $this->assertSame($conversation->id, $resolved->id);
        $this->assertSame('pending', $resolved->status);
        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    public function test_handoff_assigns_the_least_loaded_whatsapp_employee(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'واتساب',
            'name_en' => 'Social Center WhatsApp',
        ]);
        foreach ([10 => 'الموظف الأول', 20 => 'الموظف الثاني'] as $userId => $name) {
            DB::table('users')->insert(['id' => $userId, 'name' => $name]);
            $employeeId = DB::table('employee_details')->insertGetId([
                'user_id' => $userId,
                'is_suspended' => false,
            ]);
            DB::table('employee_permissions')->insert([
                'employee_id' => $employeeId,
                'permission_id' => $permissionId,
            ]);
        }
        $this->conversation(['assigned_admin_id' => 10]);
        $conversation = $this->conversation();
        $api = Mockery::mock(WhatsAppCloudApiService::class);
        $api->shouldReceive('sendText')->once()->andReturn([]);

        app(WhatsAppConversationFlowService::class)->handle(
            $api,
            $this->message($conversation, 'التواصل مع موظف'),
            [
                'type' => 'interactive',
                'interactive' => ['list_reply' => ['id' => 'employee']],
            ]
        );

        $this->assertSame(20, $conversation->fresh()->assigned_admin_id);
    }

    private function conversation(array $values = []): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create(array_merge([
            'phone' => '970599000000',
            'status' => 'open',
            'unread_count' => 0,
        ], $values));
    }

    private function message(WhatsAppConversation $conversation, string $body): WhatsAppMessage
    {
        $message = new WhatsAppMessage(['body' => $body]);
        $message->setRelation('conversation', $conversation);

        return $message;
    }
}
