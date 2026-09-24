<?php

namespace App\Services;

use App\Models\Asset;
use Carbon\Carbon;

class AssetDepreciationCalculator
{
    /** @return array<string, mixed> */
    public function calculate(Asset $asset, ?string $period = null): array
    {
        $period = $period ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('Invalid depreciation period.');
        }

        $periodDate = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $acquiredAt = $asset->acquired_at ?: $asset->created_at;
        $acquisitionMonth = $acquiredAt ? Carbon::parse($acquiredAt)->startOfMonth() : null;
        $originalCost = round(max(0, (float) $asset->price), 2);
        $rawBookValue = round((float) $asset->depreciation_price, 2);
        $currentBookValue = round(max(0, $rawBookValue), 2);
        $usefulLife = (int) round((float) $asset->months_number);
        $usedPeriods = $this->usedPeriods($asset, $originalCost);
        $remainingPeriods = max(0, $usefulLife - $usedPeriods);
        $alreadyProcessed = $asset->logs()
            ->where('type', 'depreciate')
            ->where('depreciation_period', $period)
            ->exists();
        $warning = null;
        $skipReason = null;
        $status = 'eligible';
        $eligible = true;

        if ($usefulLife <= 0) {
            $eligible = false;
            $status = 'requires_review';
            $warning = 'العمر الإنتاجي غير صالح ويحتاج مراجعة.';
        } elseif ($rawBookValue < -0.0001 || $currentBookValue - $originalCost > 0.01) {
            $eligible = false;
            $status = 'requires_review';
            $warning = 'القيمة الدفترية خارج حدود تكلفة الأصل وتحتاج مراجعة.';
        } elseif ($acquisitionMonth && $periodDate->lt($acquisitionMonth)) {
            $eligible = false;
            $status = 'not_yet_acquired';
            $skipReason = 'الفترة المطلوبة تسبق شهر اقتناء الأصل.';
        } elseif ($alreadyProcessed) {
            $eligible = false;
            $status = 'already_depreciated';
            $skipReason = 'تم إهلاك الأصل لهذه الفترة.';
        } elseif ($currentBookValue <= 0.0001) {
            $eligible = false;
            $status = 'fully_depreciated';
            $skipReason = 'اكتمل إهلاك الأصل.';
        } elseif ($remainingPeriods <= 0) {
            $eligible = false;
            $status = 'requires_review';
            $warning = 'انتهى العمر الإنتاجي وما زالت قيمة دفترية؛ يلزم قرار محاسبي.';
        }

        $monthlyDepreciation = $remainingPeriods > 0
            ? round($currentBookValue / $remainingPeriods, 2)
            : 0.0;
        $nextAmount = $eligible
            ? ($remainingPeriods === 1
                ? $currentBookValue
                : min($currentBookValue, $monthlyDepreciation))
            : 0.0;
        $valueAfter = round(max(0, $currentBookValue - $nextAmount), 2);

        return [
            'asset_id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'period' => $period,
            'original_cost' => $originalCost,
            'current_book_value' => $currentBookValue,
            'value_before' => $currentBookValue,
            'useful_life_months' => $usefulLife,
            'used_periods' => $usedPeriods,
            'remaining_periods' => $remainingPeriods,
            'depreciation_rate' => $usefulLife > 0 ? round(1 / $usefulLife, 8) : 0.0,
            'depreciation_rate_percent' => $usefulLife > 0 ? round(100 / $usefulLife, 6) : 0.0,
            'monthly_depreciation' => $monthlyDepreciation,
            'next_depreciation_amount' => round($nextAmount, 2),
            'depreciation_amount' => round($nextAmount, 2),
            'value_after' => $valueAfter,
            'accumulated_depreciation' => round(max(0, $originalCost - $currentBookValue), 2),
            'fully_depreciated' => $currentBookValue <= 0.0001,
            'already_depreciated' => $alreadyProcessed,
            'eligible' => $eligible,
            'status' => $status,
            'skip_reason' => $skipReason,
            'warning' => $warning,
            'acquired_at' => $acquiredAt ? Carbon::parse($acquiredAt)->toDateString() : null,
        ];
    }

    private function usedPeriods(Asset $asset, float $originalCost): int
    {
        $previousValue = $originalCost;
        $usedPeriods = 0;

        foreach ($asset->logs()->orderBy('id')->get([
            'type',
            'total',
            'value_before',
            'depreciation_amount',
        ]) as $log) {
            $total = $log->total !== null ? round((float) $log->total, 2) : null;
            if ($log->type === 'create') {
                if ($total !== null) {
                    $previousValue = $total;
                }

                continue;
            }
            if ($log->type !== 'depreciate') {
                continue;
            }

            $amount = round((float) ($log->depreciation_amount ?? 0), 2);
            $valueBefore = $log->value_before !== null
                ? round((float) $log->value_before, 2)
                : $previousValue;
            $reducedValue = $total !== null
                && ($valueBefore - $total > 0.0001 || $previousValue - $total > 0.0001);
            if ($amount > 0.0001 || $reducedValue) {
                $usedPeriods++;
            }
            if ($total !== null) {
                $previousValue = $total;
            }
        }

        return $usedPeriods;
    }
}
