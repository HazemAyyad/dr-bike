<?php

namespace App\Enums;

enum PurchaseReturnStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
