<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InstantSaleBonus
{
    public static function validate(array $payload, bool $isAdjustmentSale): void
    {
        if (! empty($payload['is_bonus']) && ! empty($payload['offer_package_id'])) {
            throw ValidationException::withMessages([
                'is_bonus' => ['يمكن تحديد منتج إضافي كبونص، لكن لا يمكن تحديد الباكيج نفسه كبونص.'],
            ]);
        }

        self::validateLine($payload, 'is_bonus', $isAdjustmentSale);
        foreach ($payload['other_products'] ?? [] as $index => $line) {
            self::validateLine($line, "other_products.$index.is_bonus", $isAdjustmentSale);
        }
    }

    private static function validateLine(array $line, string $field, bool $isAdjustmentSale): void
    {
        if (empty($line['is_bonus'])) {
            return;
        }

        if ($isAdjustmentSale || (float) ($line['cost'] ?? 0) !== 0.0) {
            throw ValidationException::withMessages([
                $field => [$isAdjustmentSale
                    ? 'البونص متاح في البيع الفوري العادي.'
                    : 'سعر بيع البونص يجب أن يكون صفراً.'],
            ]);
        }
    }
}
