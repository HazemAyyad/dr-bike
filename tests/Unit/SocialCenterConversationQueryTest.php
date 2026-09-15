<?php

namespace Tests\Unit;

use App\Http\Controllers\API\SocialCenterController;
use App\Models\WhatsAppConversation;
use ReflectionMethod;
use Tests\TestCase;

class SocialCenterConversationQueryTest extends TestCase
{
    public function test_whatsapp_search_uses_real_contact_columns_and_message_body(): void
    {
        $query = WhatsAppConversation::query();
        $method = new ReflectionMethod(SocialCenterController::class, 'applyConversationSearch');
        $method->invoke(new SocialCenterController, $query, '0599809', true);

        $sql = $query->toSql();

        self::assertStringContainsString('whatsapp_contacts', $sql);
        self::assertGreaterThanOrEqual(3, substr_count($sql, '`phone` like'));
        self::assertStringContainsString('whatsapp_messages', $sql);
        self::assertStringContainsString('body', $sql);
        self::assertStringNotContainsString('external_id', $sql);
    }
}
