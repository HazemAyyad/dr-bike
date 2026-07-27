<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Customers;
use ReflectionMethod;
use Tests\TestCase;

class CustomerPhoneNormalizationTest extends TestCase
{
    private function normalize(?string $phone): ?string
    {
        $method = new ReflectionMethod(Customers::class, 'normalizePhoneForValidation');
        $method->setAccessible(true);

        return $method->invoke(new Customers(), $phone);
    }

    public function test_normalizes_international_phone_without_space(): void
    {
        $this->assertSame('+972 592555309', $this->normalize('+972592555309'));
    }

    public function test_normalizes_local_phone_with_leading_zero(): void
    {
        $this->assertSame('+972 592555309', $this->normalize('0592555309'));
    }

    public function test_keeps_unfixable_phone_for_validation_error(): void
    {
        $this->assertSame('123', $this->normalize('123'));
    }
}
