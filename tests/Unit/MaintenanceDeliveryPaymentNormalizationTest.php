<?php

namespace Tests\Unit;

use App\Services\MaintenanceDeliveryService;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

class MaintenanceDeliveryPaymentNormalizationTest extends TestCase
{
    private function normalize(array $payload, float $remaining): array
    {
        $reflection = new ReflectionClass(MaintenanceDeliveryService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizePayments');
        $method->setAccessible(true);

        return $method->invoke($service, $payload, $remaining);
    }

    public function test_fully_prepaid_maintenance_does_not_create_zero_payment(): void
    {
        $this->assertSame([], $this->normalize([], 0));
    }

    public function test_default_delivery_payment_is_limited_to_remaining_amount(): void
    {
        $this->assertSame([
            ['method' => 'cash', 'amount' => 75.0, 'note' => null],
        ], $this->normalize([], 75));
    }

    public function test_delivery_rejects_payment_above_remaining_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->normalize([
            'payments' => [['method' => 'cash', 'amount' => 76]],
        ], 75);
    }
}
