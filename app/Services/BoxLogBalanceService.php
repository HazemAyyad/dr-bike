<?php

namespace App\Services;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\MaintenanceDailyBoxLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BoxLogBalanceService
{
    /**
     * Reconstruct balances from the full ledger, before any display filters.
     * Only expose balances for the boxes the caller is allowed to see.
     */
    public function attach(Collection $logs, ?array $visibleBoxIds = null, ?int $boxId = null): Collection
    {
        $ids = $logs->flatMap(fn ($log) => [$log->box_id, $log->from_box_id, $log->to_box_id])
            ->filter()->unique()->values();
        if ($visibleBoxIds !== null) {
            $ids = $ids->intersect($visibleBoxIds)->values();
        }
        if ($ids->isEmpty()) {
            return $logs;
        }

        $balances = Box::query()->whereIn('id', $ids)->pluck('total', 'id')->all();
        $history = BoxLog::query()->where(function ($query) use ($ids) {
            $query->whereIn('box_id', $ids)->orWhereIn('from_box_id', $ids)->orWhereIn('to_box_id', $ids);
        })->get();
        if (Schema::hasTable('maintenance_daily_box_logs')) {
            $history = $history->concat(MaintenanceDailyBoxLog::query()->whereIn('box_id', $ids)->get());
        }

        $requested = $logs->keyBy('id');
        $history = $history->sortBy([
            ['created_at', 'desc'], ['id', 'desc'],
        ]);

        foreach ($history as $movement) {
            if ($movement instanceof MaintenanceDailyBoxLog) {
                if ($movement->affects_cash_balance && isset($balances[$movement->box_id])) {
                    $balances[$movement->box_id] = round((float) $balances[$movement->box_id] - (float) $movement->amount, 4);
                }

                continue;
            }

            $amount = (float) ($movement->value ?? $movement->transfered_balance ?? 0);
            $isTransfer = $movement->type === 'transfer'
                || ($movement->from_box_id && $movement->to_box_id);
            $effects = $isTransfer
                ? [(int) $movement->from_box_id => -abs($amount), (int) $movement->to_box_id => abs($amount)]
                : [(int) $movement->box_id => $movement->type === 'minus' ? -abs($amount) : $amount];
            foreach ($effects as $id => $signed) {
                if (! array_key_exists($id, $balances)) {
                    continue;
                }
                $after = (float) $balances[$id];
                $before = round($after - $signed, 4);
                $balances[$id] = $before;
                $row = $requested->get($movement->id);
                if (! $row) {
                    continue;
                }
                if ($isTransfer && ! $row->type) {
                    $row->setAttribute('type', 'transfer');
                }
                if ($row->value === null) {
                    $row->setAttribute('value', $amount);
                }
                $prefix = $isTransfer
                    ? ($id === (int) $movement->from_box_id ? 'from_box' : 'to_box')
                    : 'box';
                $row->setAttribute($prefix.'_balance_before', round($before, 2));
                $row->setAttribute($prefix.'_balance_after', round($after, 2));
                if ($boxId === $id) {
                    $row->setAttribute('box_balance_before', round($before, 2));
                    $row->setAttribute('box_balance_after', round($after, 2));
                }
            }
        }

        return $logs;
    }
}
