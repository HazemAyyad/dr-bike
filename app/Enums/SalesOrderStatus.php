<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Unconfirmed = 'unconfirmed';
    case Confirmed = 'confirmed';
    case Ready = 'ready';
    case Postponed = 'postponed';
    case WithDelivery = 'with_delivery';
    case Delivered = 'delivered';
    case Archived = 'archived';
    case Review = 'review';
    case Stuck = 'stuck';
    case Returned = 'returned';
    case PartialDelivered = 'partial_delivered';
    case PartialReturn = 'partial_return';
    case AlternativeReturn = 'alternative_return';
    case Canceled = 'canceled';

    public function reservesStock(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Ready,
            self::Postponed,
            self::Review,
        ], true);
    }

    public function isEditable(): bool
    {
        return ! in_array($this, [
            self::Archived,
            self::Canceled,
        ], true);
    }

    public function canCancel(): bool
    {
        return ! in_array($this, [
            self::Archived,
            self::Canceled,
            self::WithDelivery,
            self::Delivered,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function listTabValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    public static function normalize(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Unconfirmed;
    }
}
