<?php

namespace App\Support;

use App\Enums\SalesOrderStatus;

final class SalesOrderDeletionPolicy
{
    public static function assess(string $status, bool $hasEffects, int $invoices, int $financialReturns, bool $hasChildren): array
    {
        $blocked = $invoices > 0 || $financialReturns > 0 || $hasChildren;

        return [
            'can_delete' => $status === SalesOrderStatus::Unconfirmed->value && ! $blocked,
            'requires_force' => $hasEffects,
            'message' => $status !== SalesOrderStatus::Unconfirmed->value
                ? 'الحذف متاح للطلبيات غير المؤكدة فقط، حتى مع الحذف الإجباري.'
                : ($blocked ? 'توجد فواتير أو مرتجعات مالية أو طلبيات فرعية مرتبطة. يجب معالجة ارتباطها أولاً.'
                    : ($hasEffects ? 'للطلبية آثار مالية أو مخزنية؛ الحذف الإجباري سيعكسها ويحفظ نسخة استرجاع.'
                        : 'سيتم تحرير الحجز وحذف الطلبية وارتباطات عروض المتجر، مع حفظ نسخة استرجاع.')),
        ];
    }
}
