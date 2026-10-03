<?php

namespace Tests\Unit\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreCreditPolicy;
use App\Services\OnlineStore\StoreCreditService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OnlineStoreCreditPolicyTest extends TestCase
{
    public function test_policy_defaults_are_explicit_and_balance_is_never_persisted(): void
    {
        $policy = new OnlineStoreCreditPolicy;
        $policy->forceFill(['is_eligible' => false, 'credit_limit' => null, 'currency' => 'ILS']);

        $this->assertFalse($policy->is_eligible);
        $this->assertNull($policy->credit_limit);
        $this->assertSame('ILS', $policy->currency);
        $this->assertNotContains('current_debt', $policy->getFillable());
        $this->assertNotContains('available_credit', $policy->getFillable());
        $this->assertSame(['ILS', 'USD', 'JOD'], StoreCreditService::CURRENCIES);
    }

    public function test_policy_declares_decimal_limit_approval_and_expiry_casts(): void
    {
        $casts = (new OnlineStoreCreditPolicy)->getCasts();

        $this->assertSame('decimal:2', $casts['credit_limit']);
        $this->assertSame('datetime', $casts['approved_at']);
        $this->assertSame('datetime', $casts['expires_at']);
        $this->assertSame('boolean', $casts['is_eligible']);
    }

    public function test_active_verified_link_and_approval_window_are_explicit(): void
    {
        $at = CarbonImmutable::parse('2026-10-03 12:00:00');
        $link = new OnlineStoreAccountLink([
            'role' => 'customer', 'customer_id' => 10, 'status' => 'active', 'account_source' => 'admin_app',
        ]);
        $link->setRawAttributes(array_merge($link->getAttributes(), ['verified_at' => $at->subMinute()]));
        $policy = new OnlineStoreCreditPolicy(['account_link_id' => 1, 'is_eligible' => true, 'currency' => 'ILS']);
        $policy->setRawAttributes(array_merge($policy->getAttributes(), [
            'approved_at' => $at->subMinute(), 'expires_at' => $at->addMinute(),
        ]));

        $this->assertTrue($link->isVerifiedCreditIdentity());
        $this->assertTrue($policy->isApprovedAt($at));
        $policy->setRawAttributes(array_merge($policy->getAttributes(), ['expires_at' => $at]));
        $this->assertFalse($policy->isApprovedAt($at));
        $policy->is_eligible = false;
        $this->assertFalse($policy->isApprovedAt($at->subHour()));
    }

    public function test_limit_is_optional_rounded_and_negative_values_are_rejected(): void
    {
        $this->assertNull(OnlineStoreCreditPolicy::normalizeLimit(null));
        $this->assertSame(10.24, OnlineStoreCreditPolicy::normalizeLimit('10.235'));

        $this->expectException(\InvalidArgumentException::class);
        OnlineStoreCreditPolicy::normalizeLimit(-0.01);
    }

    public function test_schema_source_has_no_persisted_debt_or_available_balance(): void
    {
        $source = '';
        foreach (glob(dirname(__DIR__, 3).'/database/migrations/*online_store*.php') ?: [] as $file) {
            $source .= file_get_contents($file);
        }

        $this->assertStringNotContainsString("->decimal('current_debt'", $source);
        $this->assertStringNotContainsString("->decimal('available_credit'", $source);
    }

    public function test_credit_validation_precedes_stock_and_sales_order_effects(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/OnlineStore/OnlineStoreCheckoutService.php');
        $credit = strpos($source, '$this->credit->assertCanCheckout(');
        $stock = strpos($source, '$this->assertAuthoritativeAvailability($lines);');
        $order = strpos($source, '$this->orders->store(');

        $this->assertIsInt($credit);
        $this->assertIsInt($stock);
        $this->assertIsInt($order);
        $this->assertLessThan($stock, $credit);
        $this->assertLessThan($order, $stock);
    }
}
