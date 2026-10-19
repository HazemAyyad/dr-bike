<?php

namespace Tests\Unit;

use App\Enums\SalesOrderStatus;
use App\Support\SalesOrderDeletionPolicy;
use PHPUnit\Framework\TestCase;

class SalesOrderDeletionPolicyTest extends TestCase
{
    public function test_draft_without_posted_effects_does_not_require_force(): void
    {
        $result = SalesOrderDeletionPolicy::assess('unconfirmed', false, 0, 0, false);
        self::assertTrue($result['can_delete']);
        self::assertFalse($result['requires_force']);
    }

    public function test_posted_effects_require_the_admin_reversal_path(): void
    {
        $result = SalesOrderDeletionPolicy::assess('unconfirmed', true, 0, 0, false);
        self::assertTrue($result['can_delete']);
        self::assertTrue($result['requires_force']);
    }

    public function test_force_cannot_make_a_business_document_or_child_order_deletable(): void
    {
        foreach ([[1, 0, false], [0, 1, false], [0, 0, true]] as [$invoices, $returns, $children]) {
            foreach ([false, true] as $effects) {
                self::assertFalse(SalesOrderDeletionPolicy::assess('unconfirmed', $effects, $invoices, $returns, $children)['can_delete']);
            }
        }
    }

    public function test_all_non_draft_statuses_and_unknown_values_stay_protected(): void
    {
        foreach (array_merge(array_column(SalesOrderStatus::cases(), 'value'), ['unknown', '']) as $status) {
            if ($status === 'unconfirmed') {
                continue;
            }
            self::assertFalse(SalesOrderDeletionPolicy::assess($status, true, 0, 0, false)['can_delete']);
        }
    }
}
