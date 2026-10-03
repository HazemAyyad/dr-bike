<?php

namespace Tests\Feature\OnlineStore;

use App\Services\OnlineStore\OnlineStoreAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_governance_boundaries_capture_actor_entity_time_changes_and_redact_secrets_and_pii(): void
    {
        $actor = OnlineStoreFixtureFactory::createAdminActor();
        $audit = app(OnlineStoreAuditService::class);
        $cases = [
            ['listing', 'published'], ['promotion', 'activated'], ['coupon', 'updated'],
            ['settings', 'updated'], ['account_link', 'linked'], ['credit_policy', 'approved'],
            ['review', 'moderated'], ['pricing', 'previewed'],
        ];
        foreach ($cases as $index => [$entity, $action]) {
            $audit->record($actor, $action, $entity, $index + 1,
                ['status' => 'before', 'email' => 'private@example.invalid'],
                ['status' => 'after', 'password' => 'secret', 'nested' => ['phone' => 'private']],
                'audit-fixture-'.$index, '127.0.0.1');
        }

        $this->assertDatabaseCount('online_store_audit_events', count($cases));
        $event = DB::table('online_store_audit_events')->where('entity_type', 'settings')->first();
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertStringContainsString('[REDACTED]', (string) $event->before_values);
        $this->assertStringNotContainsString('private@example.invalid', (string) $event->before_values);
        $this->assertStringNotContainsString('secret', (string) $event->after_values);
    }

    public function test_audit_event_rolls_back_with_failed_governance_transaction(): void
    {
        $actor = OnlineStoreFixtureFactory::createAdminActor();
        try {
            DB::transaction(function () use ($actor) {
                app(OnlineStoreAuditService::class)->record($actor, 'updated', 'settings', 1, [], ['store_enabled' => true]);
                throw new \RuntimeException('Synthetic governance failure');
            });
        } catch (\RuntimeException) {
            // Expected fixture failure.
        }

        $this->assertDatabaseCount('online_store_audit_events', 0);
    }
}
