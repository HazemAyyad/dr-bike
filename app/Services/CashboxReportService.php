<?php

namespace App\Services;

use App\Models\Box;
use App\Models\MaintenanceDailySession;
use App\Models\SalesDailySession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CashboxReportService
{
    public function __construct(
        private BoxReportService $boxReports,
        private SalesDailySessionService $salesSessions,
        private SalesOrdersDailyBoxService $salesOrderBoxes,
    ) {}

    /** @return Collection<int, Box> */
    public function permanentBoxes(): Collection
    {
        return $this->permanentBoxesQuery()
            ->orderBy('name')
            ->get(['id', 'name', 'total', 'currency', 'type', 'is_shown']);
    }

    public function statement(?int $boxId, Carbon $from, Carbon $to): array
    {
        $box = $this->permanentBoxesQuery()
            ->when($boxId, fn (Builder $query) => $query->whereKey($boxId))
            ->orderBy('name')
            ->first();

        if (! $box) {
            return [
                'title' => 'كشف حساب الصندوق',
                'summary' => collect([
                    ['title' => 'عدد الحركات', 'value' => 0],
                ]),
                'columns' => ['التاريخ', 'المرجع', 'نوع الحركة', 'البيان', 'وارد', 'صادر', 'الرصيد', 'العملة', 'الصندوق المقابل'],
                'rows' => collect(),
            ];
        }

        $report = $this->boxReports->report($box, [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'sort' => 'oldest',
        ]);
        $summary = $report['summary'];
        $runningBalance = (float) ($summary['opening_balance'] ?? 0);

        $rows = collect($report['logs'])->map(function ($log) use ($box, &$runningBalance) {
            $signedAmount = round((float) ($log->signed_amount ?? 0), 2);
            $runningBalance = round($runningBalance + $signedAmount, 2);
            $counterparty = null;
            if ($log->type === 'transfer') {
                $counterparty = (int) $log->from_box_id === (int) $box->id
                    ? $log->toBox?->name
                    : $log->fromBox?->name;
            }

            return [
                'date' => $log->created_at?->toDateTimeString(),
                'reference' => 'BOX-'.$log->id,
                'type' => $this->movementTypeLabel((string) $log->type),
                'description' => $log->description ?: $log->note,
                'incoming' => max(0, $signedAmount),
                'outgoing' => abs(min(0, $signedAmount)),
                'balance' => $runningBalance,
                'currency' => $box->currency,
                'counterparty_box' => $counterparty,
            ];
        })->values();

        return [
            'title' => 'كشف حساب الصندوق - '.$box->name,
            'summary' => collect([
                ['title' => 'الصندوق', 'value' => $box->name],
                ['title' => 'العملة', 'value' => $box->currency],
                ['title' => 'رصيد أول المدة', 'value' => round((float) ($summary['opening_balance'] ?? 0), 2)],
                ['title' => 'الوارد', 'value' => round((float) ($summary['incoming'] ?? 0), 2)],
                ['title' => 'الصادر', 'value' => round((float) ($summary['outgoing'] ?? 0), 2)],
                ['title' => 'رصيد آخر المدة', 'value' => round((float) ($summary['closing_balance'] ?? 0), 2)],
                ['title' => 'عدد الحركات', 'value' => (int) ($summary['movements_count'] ?? 0)],
            ]),
            'columns' => ['التاريخ', 'المرجع', 'نوع الحركة', 'البيان', 'وارد', 'صادر', 'الرصيد', 'العملة', 'الصندوق المقابل'],
            'rows' => $rows,
        ];
    }

    public function dailySessions(Carbon $from, Carbon $to): array
    {
        $salesSessions = Schema::hasTable('sales_daily_sessions')
            ? SalesDailySession::query()
                ->with(['user:id,name', 'closingRequests'])
                ->whereDate('business_date', '>=', $from->toDateString())
                ->whereDate('business_date', '<=', $to->toDateString())
                ->get()
            : collect();
        $maintenanceSessions = Schema::hasTable('maintenance_daily_sessions')
            ? MaintenanceDailySession::query()
                ->with(['user:id,name', 'box:id,name,total,currency', 'closingRequests'])
                ->whereDate('business_date', '>=', $from->toDateString())
                ->whereDate('business_date', '<=', $to->toDateString())
                ->get()
            : collect();

        $boxNames = Box::query()->pluck('name', 'id');
        $salesRows = $salesSessions->flatMap(
            fn (SalesDailySession $session) => $this->salesSessionRows($session, $boxNames)
        );
        $maintenanceRows = $maintenanceSessions->flatMap(
            fn (MaintenanceDailySession $session) => $this->maintenanceSessionRows($session, $boxNames)
        );
        $rows = $salesRows->concat($maintenanceRows)
            ->sortByDesc(fn (array $row) => ($row['business_date'] ?? '').' '.($row['opened_at'] ?? ''))
            ->values();
        $sessions = $salesSessions->concat($maintenanceSessions);
        $summary = collect([
            ['title' => 'عدد الجلسات', 'value' => $sessions->count()],
            ['title' => 'المفتوحة', 'value' => $sessions->where('status', 'open')->count()],
            ['title' => 'بانتظار الإغلاق', 'value' => $sessions->where('status', 'closing_requested')->count()],
            ['title' => 'المغلقة', 'value' => $sessions->where('status', 'closed')->count()],
        ]);

        foreach ($rows->groupBy('currency') as $currency => $currencyRows) {
            $summary->push(
                [
                    'title' => 'فرق الجرد - '.$currency,
                    'value' => round((float) $currencyRows->sum(fn (array $row) => (float) ($row['variance'] ?? 0)), 2),
                ],
                [
                    'title' => 'المبلغ المرحّل - '.$currency,
                    'value' => round((float) $currencyRows->sum(fn (array $row) => (float) ($row['transferred'] ?? 0)), 2),
                ],
            );
        }

        return [
            'title' => 'جلسات الصناديق اليومية',
            'summary' => $summary,
            'columns' => [
                'التاريخ', 'نوع الجلسة', 'الموظف', 'الصندوق', 'العملة',
                'رصيد البداية', 'المقبوض', 'الرصيد المتوقع', 'الجرد الفعلي',
                'فرق الجرد', 'المبلغ المرحّل', 'جهة الترحيل', 'الحالة',
            ],
            'rows' => $rows,
        ];
    }

    private function permanentBoxesQuery(): Builder
    {
        return Box::query()
            ->where('is_shown', 1)
            ->where(function (Builder $query) {
                $query->whereNull('type')->orWhereNotIn('type', $this->dailyBoxTypes());
            });
    }

    /** @return list<string> */
    private function dailyBoxTypes(): array
    {
        return array_values(array_unique([
            config('sales_daily.box_type', 'daily_sales'),
            config('sales_orders.daily_box.type', 'daily_sales_orders'),
            config('maintenance_daily.box_type', 'daily_maintenance'),
        ]));
    }

    private function movementTypeLabel(string $type): string
    {
        return match ($type) {
            'add' => 'قبض',
            'minus' => 'صرف',
            'transfer' => 'تحويل',
            'sale' => 'مبيعات',
            'maintenance' => 'صيانة',
            'expense' => 'مصروف',
            'payroll' => 'رواتب',
            'settlement' => 'تسوية',
            'cancellation_reversal' => 'عكس إلغاء',
            default => $type ?: 'حركة صندوق',
        };
    }

    /** @param Collection<int, string> $boxNames */
    private function salesSessionRows(SalesDailySession $session, Collection $boxNames): Collection
    {
        $request = $this->closingRequestFor($session);
        $counts = $session->isSalesOrders()
            ? ($request?->sales_orders_cash_counts ?? [])
            : ($request?->cash_counts ?? []);
        if (empty($counts)) {
            $counts = $this->liveSalesSessionCounts($session);
        }

        $type = $session->isSalesOrders() ? 'طلبيات' : 'مبيعات';

        return collect($counts)->map(function (array $count) use ($session, $request, $boxNames, $type) {
            return $this->dailySessionRow(
                session: $session,
                count: $count,
                request: $request,
                boxNames: $boxNames,
                type: $type,
                fallbackBoxName: $session->isSalesOrders() ? 'صندوق الطلبيات اليومي' : 'صندوق المبيعات اليومي',
            );
        });
    }

    /** @param Collection<int, string> $boxNames */
    private function maintenanceSessionRows(MaintenanceDailySession $session, Collection $boxNames): Collection
    {
        $request = $this->closingRequestFor($session);
        $counts = $request?->cash_counts ?? [];
        if (empty($counts)) {
            $opening = round((float) $session->opening_balance, 2);
            $systemBalance = round((float) ($session->box?->total ?? $session->closing_balance ?? $opening), 2);
            $counts = [[
                'currency' => $session->box?->currency ?: config('maintenance_daily.currency', 'شيكل'),
                'daily_box_id' => $session->box_id,
                'opening_float' => $opening,
                'sales_collected' => round($systemBalance - $opening, 2),
                'system_balance' => $systemBalance,
                'physical_count' => $session->closing_balance,
            ]];
        }

        return collect($counts)->map(function (array $count) use ($session, $request, $boxNames) {
            return $this->dailySessionRow(
                session: $session,
                count: $count,
                request: $request,
                boxNames: $boxNames,
                type: 'صيانة',
                fallbackBoxName: $session->box?->name ?: 'صندوق الصيانة اليومي',
            );
        });
    }

    private function closingRequestFor($session)
    {
        $requests = $session->closingRequests->sortByDesc('id');

        return $session->status === 'closed'
            ? ($requests->firstWhere('status', 'approved') ?: $requests->first())
            : $requests->first();
    }

    /** @return list<array<string, mixed>> */
    private function liveSalesSessionCounts(SalesDailySession $session): array
    {
        if ($session->isSalesOrders()) {
            try {
                return $this->salesOrderBoxes->summary($session);
            } catch (Throwable) {
                return [];
            }
        }

        $boxes = Box::query()
            ->where('type', config('sales_daily.box_type', 'daily_sales'))
            ->when(
                $session->employee_id,
                fn (Builder $query) => $query->where('employee_id', $session->employee_id),
                fn (Builder $query) => $query->where('user_id', $session->user_id)->whereNull('employee_id'),
            )
            ->get();
        try {
            $collectedByCurrency = $this->salesSessions->salesCollectedByCurrency($session);
        } catch (Throwable) {
            $collectedByCurrency = [];
        }

        return $boxes->map(function (Box $box) use ($session, $collectedByCurrency) {
            $opening = round((float) (($session->opening_balances ?? [])[$box->currency] ?? 0), 2);
            $collected = array_key_exists($box->currency, $collectedByCurrency)
                ? round((float) $collectedByCurrency[$box->currency], 2)
                : round((float) $box->total - $opening, 2);

            return [
                'currency' => $box->currency,
                'daily_box_id' => $box->id,
                'opening_float' => $opening,
                'sales_collected' => $collected,
                'system_balance' => round($opening + $collected, 2),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, string>  $boxNames
     * @return array<string, mixed>
     */
    private function dailySessionRow(
        $session,
        array $count,
        $request,
        Collection $boxNames,
        string $type,
        string $fallbackBoxName,
    ): array {
        $boxId = (int) ($count['daily_box_id'] ?? $session->box_id ?? 0);
        $currency = (string) ($count['currency'] ?? 'شيكل');
        $transfer = collect($request?->transfers ?? [])->first(
            fn (array $row) => ($row['currency'] ?? $currency) === $currency
                && (! isset($row['from_box_id']) || (int) $row['from_box_id'] === $boxId)
        );
        $toBoxId = (int) ($transfer['to_box_id'] ?? 0);

        return [
            'business_date' => $session->business_date?->toDateString(),
            'session_type' => $type,
            'employee' => $session->user?->name ?: 'غير محدد',
            'box' => $boxNames->get($boxId, $fallbackBoxName),
            'currency' => $currency,
            'opening' => round((float) ($count['opening_float'] ?? $session->opening_balance ?? 0), 2),
            'collected' => round((float) ($count['sales_collected'] ?? $count['orders_collected'] ?? 0), 2),
            'expected' => round((float) ($count['system_balance'] ?? 0), 2),
            'physical' => ($count['physical_count'] ?? null) !== null
                ? round((float) $count['physical_count'], 2)
                : null,
            'variance' => ($count['variance'] ?? null) !== null
                ? round((float) $count['variance'], 2)
                : null,
            'transferred' => round((float) ($count['amount_to_transfer'] ?? $transfer['amount'] ?? 0), 2),
            'transfer_to' => $transfer['to_box_name'] ?? $boxNames->get($toBoxId),
            'status' => $this->sessionStatusLabel((string) $session->status),
            'opened_at' => $session->opened_at?->toDateTimeString(),
        ];
    }

    private function sessionStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'مفتوحة',
            'closing_requested' => 'بانتظار الإغلاق',
            'closed' => 'مغلقة',
            default => $status,
        };
    }
}
