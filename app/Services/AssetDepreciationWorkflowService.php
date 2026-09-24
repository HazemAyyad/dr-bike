<?php

namespace App\Services;

use App\Models\Asset;

class AssetDepreciationWorkflowService
{
    public function __construct(
        private AssetDepreciationCalculator $calculator,
        private MonthlyAssetDepreciationService $depreciation,
    ) {}

    /** @return array{period:string,summary:array<string,int|float>,items:array<int,array<string,mixed>>} */
    public function preview(?string $period = null): array
    {
        $period = $period ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('Invalid depreciation period.');
        }

        $items = Asset::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Asset $asset) => $this->calculator->calculate($asset, $period))
            ->values();

        return [
            'period' => $period,
            'summary' => [
                'total' => $items->count(),
                'eligible' => $items->where('eligible', true)->count(),
                'already_depreciated' => $items->where('status', 'already_depreciated')->count(),
                'requires_review' => $items->where('status', 'requires_review')->count(),
                'skipped' => $items->where('eligible', false)->count(),
                'depreciation_amount' => round((float) $items->where('eligible', true)->sum('depreciation_amount'), 2),
            ],
            'items' => $items->all(),
        ];
    }

    /** @return array{before:array<string,mixed>,execution:array<string,mixed>,after:array<string,mixed>} */
    public function run(string $period, ?int $userId = null): array
    {
        $before = $this->preview($period);
        $execution = $this->depreciation->run($period, $userId);
        $after = $this->preview($period);

        return compact('before', 'execution', 'after');
    }
}
