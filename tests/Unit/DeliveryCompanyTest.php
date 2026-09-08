<?php

namespace Tests\Unit;

use App\Models\DeliveryCompany;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeliveryCompanyTest extends TestCase
{
    #[DataProvider('operationalTypes')]
    public function test_it_resolves_the_operational_type(string $code, ?string $type, string $expected): void
    {
        $company = new DeliveryCompany([
            'code' => $code,
            'delivery_type' => $type,
        ]);

        $this->assertSame($expected, $company->operationalType());
    }

    public static function operationalTypes(): array
    {
        return [
            'legacy internal delivery' => ['doctor_bike', null, 'internal'],
            'legacy self pickup' => ['self', null, 'pickup'],
            'custom office' => ['custom-fast-office-a1b2c', 'office', 'office'],
            'custom taxi' => ['custom-driver-a1b2c', 'taxi', 'taxi'],
            'shiply' => ['shiply', 'shiply', 'shiply'],
        ];
    }
}
