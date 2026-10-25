<?php

namespace Tests\Unit;

use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SalesOrderNoteSummaryTest extends TestCase
{
    public function test_list_summary_includes_legacy_notes_and_both_scopes_without_loading_history(): void
    {
        // No application boot or database connection: only preloaded list attributes.
        $service = (new ReflectionClass(SalesOrderService::class))->newInstanceWithoutConstructor();
        $format = new ReflectionMethod(SalesOrderService::class, 'formatListItem');
        foreach ([[null, 0, 0, 0, 0], ['  ', 0, 0, 0, 0], ['قديمة', 0, 0, 1, 0], ['قديمة', 2, 3, 3, 3], [null, 0, 2, 0, 2]] as [$legacy, $internal, $external, $expectedInternal, $expectedExternal]) {
            $order = new SalesOrder;
            $order->setRawAttributes(['id' => 42, 'notes' => $legacy, 'internal_notes_count' => $internal, 'external_notes_count' => $external]);
            $order->setRelation('city', null)->setRelation('createdByUser', null)->setRelation('deliveryCompany', null);
            $result = $format->invoke($service, $order);
            self::assertSame($expectedInternal, $result['internal_notes_count']);
            self::assertSame($expectedExternal, $result['external_notes_count']);
            self::assertFalse($order->relationLoaded('noteLog'));
        }
    }
}
