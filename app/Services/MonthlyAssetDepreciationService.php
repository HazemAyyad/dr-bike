<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetLog;
use Illuminate\Support\Facades\DB;

class MonthlyAssetDepreciationService
{
    /**
     * @return array{processed:int, skipped:int}
     */
    public function run(?string $period = null, ?int $userId = null, ?int $assetId = null): array
    {
        $period = $period ?: now()->format('Y-m');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid depreciation period.');

        $processed = 0;
        $skipped = 0;

        Asset::query()
            ->when($assetId, fn ($query) => $query->whereKey($assetId))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($period, $userId, &$processed, &$skipped) {
                $didProcess = DB::transaction(function () use ($id, $period, $userId) {
                    $asset = Asset::query()->lockForUpdate()->findOrFail($id);

                    if (AssetLog::query()->where('asset_id', $id)->where('depreciation_period', $period)->exists()) {
                        return false;
                    }

                    $before = max(0, (float) $asset->depreciation_price);
                    if ($before <= 0 || (float) $asset->depreciation_rate <= 0) {
                        return false;
                    }

                    $amount = min($before, round($before * (float) $asset->depreciation_rate, 2));
                    $after = max(0, round($before - $amount, 2));
                    $asset->update(['depreciation_price' => $after]);

                    AssetLog::create([
                        'asset_id' => $asset->id,
                        'total' => $after,
                        'value_before' => $before,
                        'depreciation_amount' => $amount,
                        'type' => 'depreciate',
                        'depreciation_period' => $period,
                        'processed_by_user_id' => $userId,
                    ]);

                    return true;
                }, 3);

                $didProcess ? $processed++ : $skipped++;
            });

        return compact('processed', 'skipped');
    }
}
