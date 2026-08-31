<?php

namespace App\Services;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\MaintenanceDailyBoxLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class BoxReportService
{
    private const TYPES = [
        'add', 'minus', 'transfer', 'sale', 'maintenance', 'expense',
        'payroll', 'settlement', 'cancellation_reversal',
    ];

    public function validate(Request $request): array
    {
        return $request->validate([
            'box_id' => ['required', 'integer', 'exists:boxes,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'direction' => ['nullable', 'in:incoming,outgoing,transfer'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', 'in:'.implode(',', self::TYPES)],
            'search' => ['nullable', 'string', 'max:255'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'sort' => ['nullable', 'in:newest,oldest,amount_desc,amount_asc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);
    }

    public function report(Box $box, array $filters): array
    {
        $logs = $this->filteredLogs($box, $filters);

        return [
            'box' => $box,
            'filters' => $filters,
            'logs' => $logs,
            'summary' => $this->summary($box, $filters, $logs),
        ];
    }

    public function paginate(Box $box, array $filters): array
    {
        $logs = $this->filteredLogs($box, $filters);
        $perPage = (int) ($filters['per_page'] ?? 30);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $logs->forPage($page, $perPage)->values(),
            $logs->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
        );

        return [
            'box' => $box,
            'summary' => $this->summary($box, $filters, $logs),
            'logs' => $paginator,
        ];
    }

    private function filteredLogs(Box $box, array $filters): Collection
    {
        $standard = $this->filteredQuery($box, $filters)->get();
        $maintenance = $this->filteredMaintenanceLogs($box, $filters);
        $logs = $standard->concat($maintenance);

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $logs->sortBy('created_at')->values(),
            'amount_desc' => $logs->sortByDesc(fn ($log) => abs((float) $log->signed_amount))->values(),
            'amount_asc' => $logs->sortBy(fn ($log) => abs((float) $log->signed_amount))->values(),
            default => $logs->sortByDesc('created_at')->values(),
        };
    }

    private function filteredMaintenanceLogs(Box $box, array $filters): Collection
    {
        if (! $box->isDailyMaintenanceBox()) {
            return collect();
        }

        $query = MaintenanceDailyBoxLog::query()
            ->where('box_id', $box->id)
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->with('instantSale:id,serial_number');

        $query->when($filters['direction'] ?? null, function ($q, string $direction) {
            if ($direction === 'transfer') {
                $q->where('type', 'transfer');
            } elseif ($direction === 'incoming') {
                $q->where('affects_cash_balance', true)->where('amount', '>', 0);
            } else {
                $q->where('affects_cash_balance', true)->where('amount', '<', 0);
            }
        });
        $query->when(
            isset($filters['types']) && ! in_array('maintenance', $filters['types'], true),
            fn ($q) => $q->whereIn('type', $filters['types'])
        );
        $query->when($filters['search'] ?? null, function ($q, string $search) {
            $escaped = addcslashes($search, '%_\\');
            $q->where(function ($searchQuery) use ($escaped) {
                $searchQuery->where('description', 'like', "%{$escaped}%")
                    ->orWhere('note', 'like', "%{$escaped}%")
                    ->orWhere('id', $escaped)
                    ->orWhere('maintenance_id', $escaped)
                    ->orWhere('instant_sale_id', $escaped);
            });
        });
        $query->when(isset($filters['min_amount']), fn ($q) => $q->whereRaw('ABS(amount) >= ?', [(float) $filters['min_amount']]));
        $query->when(isset($filters['max_amount']), fn ($q) => $q->whereRaw('ABS(amount) <= ?', [(float) $filters['max_amount']]));

        return $query->get()->map(function (MaintenanceDailyBoxLog $log) {
            $row = (object) $log->toArray();
            $row->created_at = $log->created_at;
            $row->movement_type = $log->type;
            $row->type = 'maintenance';
            $row->signed_amount = $log->affects_cash_balance ? (float) $log->amount : 0.0;
            $row->invoice_number = $log->instantSale?->serial_number;
            $row->reference = $log->instantSale?->serial_number
                ?? ($log->maintenance_id ? 'M-'.$log->maintenance_id : 'ML-'.$log->id);
            $row->source = 'maintenance_daily';

            return $row;
        });
    }

    private function filteredQuery(Box $box, array $filters): Builder
    {
        $amountSql = $this->signedAmountSql($box->id);
        $query = $this->baseQuery($box)
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->select('box_logs.*')
            ->selectRaw("{$amountSql} as signed_amount")
            ->with(['fromBox:id,name,total,type', 'toBox:id,name,total,type', 'box:id,name,total,type']);

        $query->when($filters['direction'] ?? null, function (Builder $q, string $direction) use ($amountSql) {
            if ($direction === 'transfer') {
                $q->where('type', 'transfer');
            } elseif ($direction === 'incoming') {
                $q->whereRaw("{$amountSql} > 0");
            } else {
                $q->whereRaw("{$amountSql} < 0");
            }
        });
        $query->when($filters['types'] ?? null, fn (Builder $q, array $types) => $q->whereIn('type', $types));
        $query->when($filters['search'] ?? null, function (Builder $q, string $search) {
            $escaped = addcslashes($search, '%_\\');
            $q->where(function (Builder $searchQuery) use ($escaped) {
                $searchQuery->where('description', 'like', "%{$escaped}%")
                    ->orWhere('note', 'like', "%{$escaped}%")
                    ->orWhere('id', $escaped);
            });
        });
        $query->when(isset($filters['min_amount']), fn (Builder $q) => $q->whereRaw("ABS({$amountSql}) >= ?", [(float) $filters['min_amount']]));
        $query->when(isset($filters['max_amount']), fn (Builder $q) => $q->whereRaw("ABS({$amountSql}) <= ?", [(float) $filters['max_amount']]));

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest('created_at'),
            'amount_desc' => $query->orderByRaw("ABS({$amountSql}) DESC"),
            'amount_asc' => $query->orderByRaw("ABS({$amountSql}) ASC"),
            default => $query->latest('created_at'),
        };
    }

    private function summary(Box $box, array $filters, Collection $logs): array
    {
        $incoming = round((float) $logs->sum(fn ($log) => max(0, (float) $log->signed_amount)), 2);
        $outgoing = round((float) $logs->sum(fn ($log) => abs(min(0, (float) $log->signed_amount))), 2);
        $amountSql = $this->signedAmountSql($box->id);
        $periodNet = round((float) $this->baseQuery($box)
            ->whereDate('created_at', '>=', $filters['from_date'])
            ->whereDate('created_at', '<=', $filters['to_date'])
            ->selectRaw("COALESCE(SUM({$amountSql}), 0) as net")
            ->value('net'), 2);
        $afterPeriod = (float) $this->baseQuery($box)
            ->whereDate('created_at', '>', $filters['to_date'])
            ->selectRaw("COALESCE(SUM({$amountSql}), 0) as net")
            ->value('net');
        if ($box->isDailyMaintenanceBox()) {
            $periodNet += round((float) MaintenanceDailyBoxLog::query()
                ->where('box_id', $box->id)
                ->where('affects_cash_balance', true)
                ->whereDate('created_at', '>=', $filters['from_date'])
                ->whereDate('created_at', '<=', $filters['to_date'])
                ->sum('amount'), 2);
            $afterPeriod += (float) MaintenanceDailyBoxLog::query()
                ->where('box_id', $box->id)
                ->where('affects_cash_balance', true)
                ->whereDate('created_at', '>', $filters['to_date'])
                ->sum('amount');
        }
        $closing = round((float) $box->total - $afterPeriod, 2);

        return [
            'opening_balance' => round($closing - $periodNet, 2),
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'net' => round($incoming - $outgoing, 2),
            'closing_balance' => $closing,
            'movements_count' => $logs->count(),
        ];
    }

    private function baseQuery(Box $box): Builder
    {
        return BoxLog::query()->where(function (Builder $q) use ($box) {
            $q->where('box_id', $box->id)
                ->orWhere('to_box_id', $box->id)
                ->orWhere('from_box_id', $box->id);
        });
    }

    private function signedAmountSql(int $boxId): string
    {
        $id = (int) $boxId;
        $value = Schema::hasColumn('box_logs', 'transfered_balance')
            ? 'COALESCE(value, transfered_balance, 0)'
            : 'COALESCE(value, 0)';

        return "CASE WHEN type = 'minus' THEN -ABS({$value}) "
            ."WHEN type = 'transfer' AND from_box_id = {$id} THEN -ABS({$value}) "
            ."WHEN type = 'transfer' AND to_box_id = {$id} THEN ABS({$value}) "
            ."ELSE {$value} END";
    }
}
