<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetLog;
use Illuminate\Support\Facades\DB;

class MonthlyAssetDepreciationService
{
    public function __construct(private AssetDepreciationCalculator $calculator) {}

    /**
     * @return array{processed:int, skipped:int, warnings:array<int, array<string, mixed>>}
     */
    public function run(?string $period = null, ?int $userId = null, ?int $assetId = null): array
    {
        $period = $period ?: now()->format('Y-m');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid depreciation period.');

        $processed = 0;
        $skipped = 0;
        $warnings = [];

        Asset::query()
            ->when($assetId, fn ($query) => $query->whereKey($assetId))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($period, $userId, &$processed, &$skipped, &$warnings) {
                $result = DB::transaction(function () use ($id, $period, $userId) {
                    $asset = Asset::query()->lockForUpdate()->findOrFail($id);
                    $calculation = $this->calculator->calculate($asset, $period);
                    if (! $calculation['eligible']) {
                        return ['processed' => false, 'calculation' => $calculation];
                    }

                    $before = (float) $calculation['value_before'];
                    $amount = (float) $calculation['next_depreciation_amount'];
                    $after = (float) $calculation['value_after'];
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

                    return ['processed' => true, 'calculation' => $calculation];
                }, 3);

                if ($result['processed']) {
                    $processed++;
                } else {
                    $skipped++;
                    if ($result['calculation']['warning']) {
                        $warnings[] = [
                            'asset_id' => $id,
                            'warning' => $result['calculation']['warning'],
                        ];
                    }
                }
            });

        return compact('processed', 'skipped', 'warnings');
    }
}
