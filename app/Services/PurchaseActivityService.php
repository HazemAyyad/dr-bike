<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\PurchaseActivityLog;

class PurchaseActivityService
{
    public function log(
        Bill $bill,
        string $event,
        string $title,
        ?string $description = null,
        ?array $before = null,
        ?array $after = null,
        ?array $meta = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $userId = null,
    ): PurchaseActivityLog {
        return PurchaseActivityLog::create([
            'bill_id' => $bill->id,
            'event' => $event,
            'title' => $title,
            'description' => $description,
            'before_values' => $before,
            'after_values' => $after,
            'meta' => $meta,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $userId,
        ]);
    }
}
