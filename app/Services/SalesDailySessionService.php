<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\AdminNotification;
use App\Models\Box;
use App\Models\EmployeeDetail;
use App\Models\EmployeeNotification;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesCancellationRequest;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailyReopenRequest;
use App\Models\SalesDailySession;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\SalesDailySettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDailySessionService
{
    public const TYPE_INSTANT_SALES = 'instant_sales';

    public const TYPE_SALES_ORDERS = 'sales_orders';

    public function __construct(
        protected AdminNotificationService $adminNotificationService,
        protected EmployeeNotificationService $employeeNotificationService
    ) {}

    /**
     * @return array{user_id: int, employee_id: int|null}
     */
    public function resolveOwner(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'employee_id' => $user->employee?->id ? (int) $user->employee->id : null,
        ];
    }

    public function businessDateToday(): Carbon
    {
        return Carbon::today();
    }

    public function normalizeSessionType(?string $type): string
    {
        return $type === self::TYPE_SALES_ORDERS
            ? self::TYPE_SALES_ORDERS
            : self::TYPE_INSTANT_SALES;
    }

    /**
     * @return Collection<int, Box>
     */
    public function ensureDailyBoxes(User $user): Collection
    {
        $owner = $this->resolveOwner($user);
        $user->loadMissing('employee.user');
        $displayName = $user->name ?? 'مستخدم';

        foreach (config('sales_daily.default_currencies', ['شيكل']) as $currency) {
            $query = Box::query()
                ->where('type', config('sales_daily.box_type'))
                ->where('currency', $currency);

            if ($owner['employee_id']) {
                $query->where('employee_id', $owner['employee_id']);
            } else {
                $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
            }

            $box = $query->first();

            if (! $box) {
                $box = Box::create([
                    'name' => 'صندوق مبيعات يومي - '.$displayName.' - '.$currency,
                    'type' => config('sales_daily.box_type'),
                    'employee_id' => $owner['employee_id'],
                    'user_id' => $owner['employee_id'] ? null : $owner['user_id'],
                    'total' => 0,
                    'is_shown' => 0,
                    'currency' => $currency,
                ]);
            }

        }

        return $this->dailyBoxesForOwner($user);
    }

    /**
     * @return Collection<int, Box>
     */
    private function dailyBoxesForOwner(User $user): Collection
    {
        $owner = $this->resolveOwner($user);

        $query = Box::query()
            ->where('type', config('sales_daily.box_type'));

        if ($owner['employee_id']) {
            $query->where('employee_id', $owner['employee_id']);
        } else {
            $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
        }

        return $query
            ->orderByRaw("CASE WHEN currency = 'شيكل' THEN 0 ELSE 1 END")
            ->orderBy('currency')
            ->get();
    }

    public function findBlockingSession(User $user, string $type = self::TYPE_INSTANT_SALES): ?SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $owner = $this->resolveOwner($user);
        $today = $this->businessDateToday()->toDateString();

        return SalesDailySession::query()
            ->where('session_type', $type)
            ->where('user_id', $owner['user_id'])
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->where('business_date', '<', $today)
            ->orderBy('business_date')
            ->first();
    }

    public function findGlobalOpenSession(?int $exceptSessionId = null, string $type = self::TYPE_INSTANT_SALES): ?SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $query = SalesDailySession::query()
            ->with('user')
            ->where('session_type', $type)
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ]);

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query
            ->orderBy('business_date')
            ->orderByDesc('id')
            ->first();
    }

    public function findOpenSessionForBusinessDate(User $user, ?Carbon $date = null, string $type = self::TYPE_INSTANT_SALES): ?SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $owner = $this->resolveOwner($user);
        $date = ($date ?? $this->businessDateToday())->toDateString();

        return SalesDailySession::query()
            ->where('session_type', $type)
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $date)
            ->where('status', config('sales_daily.session_status.open'))
            ->orderByDesc('id')
            ->first();
    }

    public function findGlobalOpenSessionForBusinessDate(?Carbon $date = null, ?int $exceptSessionId = null, string $type = self::TYPE_INSTANT_SALES): ?SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $date = ($date ?? $this->businessDateToday())->toDateString();
        $query = SalesDailySession::query()
            ->with('user')
            ->where('session_type', $type)
            ->whereDate('business_date', $date)
            ->where('status', config('sales_daily.session_status.open'));

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query
            ->orderByDesc('id')
            ->first();
    }

    public function assertCanCreateSaleToday(User $user, string $type = self::TYPE_INSTANT_SALES): SalesDailySession
    {
        $session = $this->findOpenSessionForBusinessDate($user, type: $type)
            ?? $this->findGlobalOpenSessionForBusinessDate(type: $type);

        if (! $session) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_no_session')],
            ]);
        }

        return $session;
    }

    public function getActiveSession(User $user, bool $autoOpen = false, string $type = self::TYPE_INSTANT_SALES): ?SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $owner = $this->resolveOwner($user);
        $today = $this->businessDateToday();

        $blocking = $this->findBlockingSession($user, $type);
        if ($blocking && ! $autoOpen) {
            return $blocking;
        }

        if ($blocking) {
            return $blocking;
        }

        $session = SalesDailySession::query()
            ->where('session_type', $type)
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $today)
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->orderByDesc('id')
            ->first();

        if ($session) {
            return $session;
        }

        if (! $autoOpen) {
            return null;
        }

        return $this->openSession($user, $today, sessionType: $type);
    }

    public function openSession(
        User $user,
        ?Carbon $date = null,
        array $openingCounts = [],
        bool $confirmOpeningVariance = false,
        array $salesOrdersOpeningCounts = [],
        string $sessionType = self::TYPE_INSTANT_SALES
    ): SalesDailySession {
        $sessionType = $this->normalizeSessionType($sessionType);
        $owner = $this->resolveOwner($user);
        $date = ($date ?? $this->businessDateToday())->copy()->startOfDay();

        if ($this->findBlockingSession($user, $sessionType)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_previous_day_open')],
            ]);
        }

        $existing = SalesDailySession::query()
            ->where('session_type', $sessionType)
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $date)
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($globalOpen = $this->findGlobalOpenSession(type: $sessionType)) {
            $employeeName = $globalOpen->user?->name ?? __('messages.employee_default_name');

            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_drawer_open_by_other', ['employee' => $employeeName])],
            ]);
        }

        if ($sessionType === self::TYPE_INSTANT_SALES) {
            $this->ensureDailyBoxes($user);
        }
        $expectedOpeningCounts = $sessionType === self::TYPE_INSTANT_SALES
            ? $this->expectedOpeningCountsForNextSession()
            : [];
        $openingBalances = $this->normalizeOpeningCounts($openingCounts, $expectedOpeningCounts);
        $openingVariances = $this->openingVarianceRows($openingBalances, $expectedOpeningCounts);
        $expectedOrdersOpeningCounts = $sessionType === self::TYPE_SALES_ORDERS
            ? $this->expectedSalesOrdersOpeningCounts($user)
            : [];
        $ordersOpeningBalances = $this->normalizeOpeningCounts(
            $salesOrdersOpeningCounts,
            $expectedOrdersOpeningCounts
        );
        $ordersOpeningVariances = $this->openingVarianceRows(
            $ordersOpeningBalances,
            $expectedOrdersOpeningCounts
        );

        if (($openingVariances !== [] || $ordersOpeningVariances !== []) && ! $confirmOpeningVariance) {
            throw ValidationException::withMessages([
                'opening_counts' => [__('messages.sales_daily_opening_variance')],
            ]);
        }

        $session = DB::transaction(function () use ($user, $owner, $date, $openingBalances, $ordersOpeningBalances, $expectedOpeningCounts, $sessionType) {
            if ($sessionType === self::TYPE_INSTANT_SALES) {
                $this->syncOpeningDailyBoxBalances($user, $openingBalances, $expectedOpeningCounts);
            }

            return SalesDailySession::create([
                'user_id' => $owner['user_id'],
                'employee_id' => $owner['employee_id'],
                'session_type' => $sessionType,
                'business_date' => $date->toDateString(),
                'status' => config('sales_daily.session_status.open'),
                'opening_balances' => $openingBalances,
                'sales_orders_opening_balances' => $ordersOpeningBalances,
                'opened_at' => now(),
                'opened_by_user_id' => $owner['user_id'],
            ]);
        });

        if ($sessionType === self::TYPE_SALES_ORDERS) {
            app(SalesOrdersDailyBoxService::class)->ensureBoxes($user, $session)->each(function (Box $box) use ($ordersOpeningBalances) {
                $box->update(['total' => round((float) ($ordersOpeningBalances[$box->currency] ?? 0), 2)]);
            });
        }

        $this->logSessionActivity(
            $session,
            $user,
            'sales_daily_session_opened',
            $sessionType === self::TYPE_SALES_ORDERS ? 'فتح صندوق الطلبيات' : 'فتح صندوق المبيعات',
            $sessionType === self::TYPE_SALES_ORDERS ? 'تم فتح صندوق الطلبيات اليومي' : 'تم فتح صندوق المبيعات اليومي',
            ['opening_balances' => $openingBalances, 'sales_orders_opening_balances' => $ordersOpeningBalances]
        );

        return $session;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function expectedOpeningCountsForNextSession(): array
    {
        return $this->expectedOpeningCountsForSession();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedOpeningCountsForSession(?SalesDailySession $session = null): array
    {
        $previousQuery = SalesDailySession::query()
            ->where('session_type', self::TYPE_INSTANT_SALES)
            ->with([
                'user',
                'closingRequests' => fn ($query) => $query
                    ->where('status', 'approved')
                    ->latest('id')
                    ->limit(1),
            ])
            ->where('status', config('sales_daily.session_status.closed'));

        if ($session) {
            if ($session->opened_at) {
                $previousQuery->where('closed_at', '<=', $session->opened_at);
            } else {
                $previousQuery->where(function ($query) use ($session) {
                    $query
                        ->whereDate('business_date', '<', $session->business_date)
                        ->orWhere(function ($sameDay) use ($session) {
                            $sameDay
                                ->whereDate('business_date', $session->business_date)
                                ->where('id', '<', $session->id);
                        });
                });
            }
            $previousQuery->where('id', '!=', $session->id);
        }

        $previous = $previousQuery
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        $previousRequest = $previous?->closingRequests->first();
        $rows = [];

        foreach (($previousRequest?->cash_counts ?? []) as $row) {
            $currency = trim((string) ($row['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $rows[$currency] = [
                'currency' => $currency,
                'expected_amount' => round((float) ($row['float_to_keep'] ?? 0), 2),
                'previous_daily_box_id' => (int) ($row['daily_box_id'] ?? 0),
                'previous_employee_name' => $previous?->user?->name,
                'previous_session_id' => $previous?->id,
                'previous_business_date' => $previous?->business_date?->toDateString(),
            ];
        }

        foreach (config('sales_daily.default_currencies', ['شيكل']) as $currency) {
            $rows[$currency] ??= [
                'currency' => $currency,
                'expected_amount' => 0,
                'previous_daily_box_id' => null,
                'previous_employee_name' => $previous?->user?->name,
                'previous_session_id' => $previous?->id,
                'previous_business_date' => $previous?->business_date?->toDateString(),
            ];
        }

        return array_values($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $openingCounts
     * @param  array<int, array<string, mixed>>  $expectedOpeningCounts
     * @return array<string, float>
     */
    private function normalizeOpeningCounts(array $openingCounts, array $expectedOpeningCounts): array
    {
        $balances = [];

        foreach ($expectedOpeningCounts as $row) {
            $currency = trim((string) ($row['currency'] ?? ''));
            if ($currency !== '') {
                $balances[$currency] = round((float) ($row['expected_amount'] ?? 0), 2);
            }
        }

        foreach ($openingCounts as $row) {
            $currency = trim((string) ($row['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $balances[$currency] = round((float) ($row['physical_count'] ?? 0), 2);
        }

        return $balances;
    }

    /**
     * @param  array<string, float>  $openingBalances
     * @param  array<int, array<string, mixed>>  $expectedOpeningCounts
     * @return array<int, array<string, mixed>>
     */
    private function openingVarianceRows(array $openingBalances, array $expectedOpeningCounts): array
    {
        $rows = [];

        foreach ($expectedOpeningCounts as $row) {
            $currency = trim((string) ($row['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }

            $expected = round((float) ($row['expected_amount'] ?? 0), 2);
            $counted = round((float) ($openingBalances[$currency] ?? 0), 2);
            $variance = round($counted - $expected, 2);

            if (abs($variance) > 0.0001) {
                $rows[] = [
                    'currency' => $currency,
                    'expected_amount' => $expected,
                    'physical_count' => $counted,
                    'variance' => $variance,
                    'previous_daily_box_id' => $row['previous_daily_box_id'] ?? null,
                    'previous_employee_name' => $row['previous_employee_name'] ?? null,
                    'previous_session_id' => $row['previous_session_id'] ?? null,
                    'previous_business_date' => $row['previous_business_date'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, float>  $openingBalances
     * @param  array<int, array<string, mixed>>  $expectedOpeningCounts
     */
    private function syncOpeningDailyBoxBalances(
        User $user,
        array $openingBalances,
        array $expectedOpeningCounts
    ): void {
        $owner = $this->resolveOwner($user);
        $user->loadMissing('employee.user');
        $displayName = $user->name ?? 'مستخدم';
        $expectedByCurrency = collect($expectedOpeningCounts)->keyBy('currency');

        foreach ($openingBalances as $currency => $amount) {
            $currency = trim((string) $currency);
            if ($currency === '') {
                continue;
            }

            $targetQuery = Box::query()
                ->where('type', config('sales_daily.box_type'))
                ->where('currency', $currency);

            if ($owner['employee_id']) {
                $targetQuery->where('employee_id', $owner['employee_id']);
            } else {
                $targetQuery->where('user_id', $owner['user_id'])->whereNull('employee_id');
            }

            $targetBox = $targetQuery->first();
            if (! $targetBox) {
                $targetBox = Box::create([
                    'name' => 'صندوق مبيعات يومي - '.$displayName.' - '.$currency,
                    'type' => config('sales_daily.box_type'),
                    'employee_id' => $owner['employee_id'],
                    'user_id' => $owner['employee_id'] ? null : $owner['user_id'],
                    'total' => 0,
                    'is_shown' => 0,
                    'currency' => $currency,
                ]);
            }

            $counted = round((float) $amount, 2);
            $expected = $expectedByCurrency->get($currency, []);
            $previousBoxId = (int) ($expected['previous_daily_box_id'] ?? 0);

            if ($previousBoxId > 0 && $previousBoxId !== (int) $targetBox->id) {
                $previousBox = Box::query()->find($previousBoxId);
                if ($previousBox) {
                    $previousBox->update([
                        'total' => round(max(0, (float) $previousBox->total - $counted), 2),
                    ]);
                }
            }

            $targetBox->update(['total' => $counted]);
        }
    }

    public function assertCanCreateSale(User $user, string $type = self::TYPE_INSTANT_SALES): SalesDailySession
    {
        $type = $this->normalizeSessionType($type);
        $session = $this->getActiveSession($user, autoOpen: false, type: $type);

        if (! $session) {
            $session = $this->findGlobalOpenSession(type: $type);
        }

        if (! $session) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_no_session')],
            ]);
        }

        if ($session->isClosingRequested()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_closing_pending')],
            ]);
        }

        if ($session->isClosed()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_day_closed')],
            ]);
        }

        return $session;
    }

    public function dailyBoxBelongsToSession(Box $box, SalesDailySession $session): bool
    {
        if (! $box->isDailySalesBox()) {
            return true;
        }

        if ($session->employee_id) {
            return (int) $box->employee_id === (int) $session->employee_id;
        }

        return ! $box->employee_id && (int) $box->user_id === (int) $session->user_id;
    }

    public function assertCanCloseSession(User $user, ?SalesDailySession $session = null): SalesDailySession
    {
        if (! $session) {
            $blocking = $this->findBlockingSession($user);
            $session = $blocking ?? $this->getActiveSession($user, autoOpen: false);
        }

        if (! $session) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_no_session')],
            ]);
        }

        $this->assertSessionCanBeClosed($session);

        if ((int) $session->user_id !== (int) $user->id && ! $this->canReviewAllSessions($user)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.unauthorized')],
            ]);
        }

        return $session;
    }

    public function assertSessionCanBeClosed(SalesDailySession $session): void
    {
        if ($session->isClosingRequested()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_closing_pending')],
            ]);
        }

        if ($session->isClosed()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_day_closed')],
            ]);
        }
    }

    public function isLateCloseSession(SalesDailySession $session, ?Carbon $at = null): bool
    {
        $at = ($at ?? now())->toDateString();

        return $session->business_date->toDateString() < $at;
    }

    public function dailyBoxForCurrency(User $user, string $currency): Box
    {
        $owner = $this->resolveOwner($user);
        $this->ensureDailyBoxes($user);

        $query = Box::query()
            ->where('type', config('sales_daily.box_type'))
            ->where('currency', $currency);

        if ($owner['employee_id']) {
            $query->where('employee_id', $owner['employee_id']);
        } else {
            $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
        }

        $box = $query->first();
        if (! $box) {
            throw ValidationException::withMessages([
                'box' => [__('messages.sales_daily_box_not_found')],
            ]);
        }

        return $box;
    }

    public function dailyBoxForSessionCurrency(SalesDailySession $session, ?string $currency = null): ?Box
    {
        $owner = User::query()->find($session->user_id);
        if ($owner) {
            $this->ensureDailyBoxes($owner);
        }

        $query = Box::query()
            ->where('type', config('sales_daily.box_type'));

        $currency = trim((string) $currency);
        if ($currency !== '') {
            $query->where('currency', $currency);
        }

        if ($session->employee_id) {
            $query->where('employee_id', $session->employee_id);
        } else {
            $query->where('user_id', $session->user_id)->whereNull('employee_id');
        }

        return $query
            ->orderByRaw("CASE WHEN currency = 'شيكل' THEN 0 ELSE 1 END")
            ->orderBy('currency')
            ->first();
    }

    public function assertDailyBoxOwnedByUser(User $user, Box $box): void
    {
        if (! $box->isDailySalesBox()) {
            return;
        }

        $session = $this->assertCanCreateSale($user);
        if ($this->dailyBoxBelongsToSession($box, $session)) {
            return;
        }

        throw ValidationException::withMessages([
            'box_id' => ['الصندوق المختار ليس صندوق جلسة المبيعات اليومية المفتوحة.'],
        ]);
    }

    public function assertSessionAllowsPayment(User $user, Box $box): void
    {
        $this->assertDailyBoxOwnedByUser($user, $box);
    }

    public function notifyExternalSaleMovement(
        User $actor,
        SalesDailySession $session,
        string $saleType,
        int $saleId,
        float $amount,
        ?int $boxId = null
    ): void {
        if ((int) $session->user_id === (int) $actor->id) {
            return;
        }

        $session->loadMissing(['user', 'employee.user']);
        $actor->loadMissing('employee.user');

        $ownerEmployee = $this->resolveSessionEmployee($session);
        if ($ownerEmployee) {
            $ownerEmployee->loadMissing('user');
        }

        $actorName = $actor->employee?->user?->name ?? $actor->name ?? 'موظف';
        $ownerName = $session->user?->name ?? $ownerEmployee?->user?->name ?? 'المسؤول';
        $saleLabel = match ($saleType) {
            'profit' => 'بيع ربحي',
            'sales_order' => 'طلبية مبيعات',
            default => 'بيع فوري',
        };
        $amountLabel = number_format($amount, 2, '.', '');

        $data = [
            'session_id' => (string) $session->id,
            'business_date' => $session->business_date?->toDateString() ?? '',
            'sale_type' => $saleType,
            'sale_id' => (string) $saleId,
            'amount' => $amountLabel,
            'box_id' => $boxId !== null ? (string) $boxId : '',
            'actor_user_id' => (string) $actor->id,
            'actor_employee_id' => (string) ($actor->employee?->id ?? ''),
            'actor_name' => $actorName,
            'owner_user_id' => (string) $session->user_id,
            'owner_employee_id' => (string) ($ownerEmployee?->id ?? ''),
            'owner_name' => $ownerName,
        ];

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $this->adminNotificationService->create(
                AdminNotificationService::TYPE_SALES_DAILY_EXTERNAL_SALE,
                'حركة على صندوق مبيعات يومي',
                "{$actorName} أضاف {$saleLabel} #{$saleId} بقيمة {$amountLabel} على صندوق {$ownerName}",
                $data,
                $actor->employee?->id,
                'sales_daily_session',
                (int) $session->id
            );

            if ($ownerEmployee) {
                $this->employeeNotificationService->create(
                    $ownerEmployee,
                    EmployeeNotificationService::TYPE_SALES_DAILY_EXTERNAL_SALE,
                    'حركة على صندوقك',
                    "{$actorName} أضاف {$saleLabel} #{$saleId} بقيمة {$amountLabel} على صندوقك",
                    $data,
                    'sales_daily_session',
                    (int) $session->id
                );
            }
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * @return array<string, float>
     */
    public function salesCollectedByCurrency(SalesDailySession $session): array
    {
        $totals = [];

        $instantRows = InstantSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->whereNull('parent_id')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->with('paymentBox:id,currency')
            ->get(['payment_box_value', 'payment_box_id']);

        foreach ($instantRows as $sale) {
            $amount = (float) ($sale->payment_box_value ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $currency = $sale->paymentBox?->currency ?? $this->currencyFromBoxId($sale->payment_box_id);
            if ($currency) {
                $totals[$currency] = $totals[$currency] ?? 0.0;
                $totals[$currency] += $amount;
            }
        }

        $profitRows = ProfitSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->with('paymentBox:id,currency')
            ->get(['payment_box_value', 'payment_box_id']);

        foreach ($profitRows as $sale) {
            $amount = (float) ($sale->payment_box_value ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $currency = $sale->paymentBox?->currency ?? $this->currencyFromBoxId($sale->payment_box_id);
            if ($currency) {
                $totals[$currency] = $totals[$currency] ?? 0.0;
                $totals[$currency] += $amount;
            }
        }

        foreach ($totals as $currency => $value) {
            $totals[$currency] = round($value, 2);
        }

        return $totals;
    }

    private function currencyFromBoxId(?int $boxId): ?string
    {
        if (! $boxId) {
            return null;
        }

        return Box::query()->whereKey($boxId)->value('currency');
    }

    /**
     * @return array{
     *     checked:int,
     *     admin_notified:int,
     *     employee_notified:int,
     *     skipped_admin_duplicate:int,
     *     skipped_employee_duplicate:int,
     *     missing_employee:int,
     *     details:list<array<string, mixed>>
     * }
     */
    public function sendPreviousDayOpenReminders(bool $force = false): array
    {
        $tz = 'Asia/Hebron';
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $reminderDate = $today;
        $slotMinute = ((int) $now->format('i')) < 30 ? 0 : 30;
        $reminderSlot = $now->copy()
            ->setTime((int) $now->format('H'), $slotMinute, 0)
            ->format('Y-m-d H:i');

        $stats = [
            'checked' => 0,
            'admin_notified' => 0,
            'employee_notified' => 0,
            'skipped_admin_duplicate' => 0,
            'skipped_employee_duplicate' => 0,
            'missing_employee' => 0,
            'details' => [],
        ];

        $sessions = SalesDailySession::query()
            ->with(['user', 'employee.user'])
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->whereDate('business_date', '<', $today)
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            foreach ($sessions as $session) {
                $stats['checked']++;

                $employee = $this->resolveSessionEmployee($session);
                $employee?->loadMissing('user');
                $session->loadMissing('user');

                $employeeName = $session->user?->name
                    ?? $employee?->user?->name
                    ?? __('messages.employee_default_name');
                $businessDate = $session->business_date?->toDateString() ?? '';
                $isOrders = $session->isSalesOrders();
                $drawerName = $isOrders ? 'صندوق الطلبيات' : 'صندوق المبيعات';

                $data = [
                    'session_id' => (string) $session->id,
                    'business_date' => $businessDate,
                    'session_status' => (string) $session->status,
                    'employee_id' => (string) ($session->employee_id ?? $employee?->id ?? ''),
                    'employee_name' => $employeeName,
                    'owner_user_id' => (string) $session->user_id,
                    'reminder_date' => $reminderDate,
                    'reminder_slot' => $reminderSlot,
                    'checked_at' => $now->toIso8601String(),
                    'session_type' => $session->session_type,
                ];

                $adminSent = false;
                if (! $force && $this->previousDayAdminReminderExists($session, $reminderSlot)) {
                    $stats['skipped_admin_duplicate']++;
                } else {
                    $this->adminNotificationService->create(
                        AdminNotificationService::TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN,
                        "{$drawerName} غير مغلق",
                        "{$employeeName} لم يغلق {$drawerName} ليوم {$businessDate}. يرجى مراجعة {$drawerName} وإغلاقه.",
                        $data,
                        $session->employee_id ?: $employee?->id,
                        'sales_daily_session',
                        (int) $session->id
                    );
                    $adminSent = true;
                    $stats['admin_notified']++;
                }

                $employeeSent = false;
                if (! $employee) {
                    $stats['missing_employee']++;
                } elseif (! $force && $this->previousDayEmployeeReminderExists($employee, $session, $reminderSlot)) {
                    $stats['skipped_employee_duplicate']++;
                } else {
                    $this->employeeNotificationService->create(
                        $employee,
                        EmployeeNotificationService::TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN,
                        "تذكير إغلاق {$drawerName}",
                        "{$drawerName} ليوم {$businessDate} ما زال غير مغلق. يرجى إغلاقه.",
                        $data,
                        'sales_daily_session',
                        (int) $session->id
                    );
                    $employeeSent = true;
                    $stats['employee_notified']++;
                }

                $stats['details'][] = [
                    'session_id' => (int) $session->id,
                    'business_date' => $businessDate,
                    'status' => (string) $session->status,
                    'employee_id' => $employee?->id,
                    'employee_name' => $employeeName,
                    'reminder_slot' => $reminderSlot,
                    'admin_sent' => $adminSent,
                    'employee_sent' => $employeeSent,
                ];
            }
        } finally {
            App::setLocale($previous);
        }

        return $stats;
    }

    private function previousDayAdminReminderExists(SalesDailySession $session, string $reminderSlot): bool
    {
        return AdminNotification::query()
            ->where('type', AdminNotificationService::TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN)
            ->where('related_type', 'sales_daily_session')
            ->where('related_id', $session->id)
            ->where('data->reminder_slot', $reminderSlot)
            ->exists();
    }

    private function previousDayEmployeeReminderExists(
        EmployeeDetail $employee,
        SalesDailySession $session,
        string $reminderSlot
    ): bool {
        return EmployeeNotification::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeNotificationService::TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN)
            ->where('related_type', 'sales_daily_session')
            ->where('related_id', $session->id)
            ->where('data->reminder_slot', $reminderSlot)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSessionPayload(User $user, string $type = self::TYPE_INSTANT_SALES): array
    {
        $type = $this->normalizeSessionType($type);
        $owner = $this->resolveOwner($user);
        $blocking = $this->findBlockingSession($user, $type);
        $globalOpen = $this->findGlobalOpenSession(type: $type);
        $session = $blocking
            ?? $this->getActiveSession($user, autoOpen: false, type: $type)
            ?? $globalOpen;
        $blockedByOther = $globalOpen !== null
            && (int) $globalOpen->user_id !== $owner['user_id'];
        $todaySessionExists = SalesDailySession::query()
            ->where('session_type', $type)
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $this->businessDateToday())
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->exists();
        $canRequestOpen = ! $blocking
            && ! $blockedByOther
            && ! $todaySessionExists;
        $session?->loadMissing('user');
        $boxOwner = $session?->user ?? $user;
        $dailyBoxes = $session && $type === self::TYPE_INSTANT_SALES
            ? $this->ensureDailyBoxes($boxOwner)
            : collect();
        $salesCollected = $session && $type === self::TYPE_INSTANT_SALES
            ? $this->salesCollectedByCurrency($session)
            : [];
        $openingBalances = $session?->opening_balances ?? [];
        if ($session && $session->allowsSales() && $type === self::TYPE_SALES_ORDERS) {
            app(SalesOrdersDailyBoxService::class)->ensureBoxes($boxOwner, $session);
        }
        $ordersCurrencies = $session && $type === self::TYPE_SALES_ORDERS
            ? app(SalesOrdersDailyBoxService::class)->summary($session)
            : [];

        $currencies = [];
        foreach ($dailyBoxes as $box) {
            $currency = $box->currency;
            $opening = round((float) ($openingBalances[$currency] ?? 0), 2);
            $collected = round((float) ($salesCollected[$currency] ?? 0), 2);
            $systemBalance = round($opening + $collected, 2);

            $currencies[] = [
                'currency' => $currency,
                'daily_box_id' => $box->id,
                'daily_box_name' => $box->name,
                'box_balance' => round((float) $box->total, 2),
                'opening_float' => $opening,
                'sales_collected' => $collected,
                'system_balance' => $systemBalance,
            ];
        }

        $instantCount = 0;
        $profitCount = 0;
        $pendingClosing = null;
        $pendingReopen = null;

        if ($session) {
            $instantCount = InstantSale::query()
                ->where('sales_daily_session_id', $session->id)
                ->whereNull('parent_id')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                })
                ->count();

            $profitCount = ProfitSale::query()
                ->where('sales_daily_session_id', $session->id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                })
                ->count();

            $pendingClosing = $session->closingRequests()
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            $pendingReopen = $session->reopenRequests()
                ->where('status', 'pending')
                ->latest('id')
                ->first();
        }

        $canRequestClosing = $session
            && $session->status === config('sales_daily.session_status.open')
            && ! $pendingClosing;
        $isPreviousDayOpen = $session
            && $session->business_date->toDateString() < $this->businessDateToday()->toDateString()
            && in_array($session->status, [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ], true);
        $requiresLateCloseReason = $canRequestClosing
            && $session
            && $this->isLateCloseSession($session)
            && ! $this->canReviewAllSessions($user);

        $canManageOther = $blockedByOther && $this->canReviewAllSessions($user);

        return [
            'session' => $session ? [
                'id' => $session->id,
                'session_type' => $session->session_type,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'employee_name' => $session->user?->name,
                'opened_at' => $session->opened_at?->toDateTimeString(),
                'allows_sales' => $session->allowsSales(),
                'is_blocking_previous_day' => (bool) $isPreviousDayOpen,
                'previous_day_warning' => (bool) $isPreviousDayOpen,
                'previous_day_owner_name' => $isPreviousDayOpen
                    ? ($session->user?->name ?? null)
                    : null,
                'previous_day_business_date' => $isPreviousDayOpen
                    ? $session->business_date->toDateString()
                    : null,
                'has_pending_reopen' => (bool) $pendingReopen,
                'can_request_closing' => $canRequestClosing
                    && (! $blockedByOther || $this->canReviewAllSessions($user)),
                'requires_late_close_reason' => $requiresLateCloseReason,
            ] : null,
            'can_request_open' => $canRequestOpen,
            'blocked_by_other_session' => $blockedByOther,
            'blocked_by_employee_name' => $blockedByOther
                ? ($globalOpen?->user?->name ?? null)
                : null,
            'can_manage_other_session' => $canManageOther,
            'manageable_session_id' => $canManageOther ? (int) $globalOpen->id : null,
            'can_finalize_closing' => $this->canReviewAllSessions($user),
            'currencies' => $currencies,
            'sales_orders_currencies' => $ordersCurrencies,
            'instant_sales_count' => $instantCount,
            'profit_sales_count' => $profitCount,
            'pending_closing_request_id' => $pendingClosing?->id,
            'pending_reopen_request_id' => $pendingReopen?->id,
            'session_type' => $type,
            'expected_opening_counts' => $canRequestOpen && $type === self::TYPE_INSTANT_SALES
                ? $this->expectedOpeningCountsForNextSession()
                : [],
            'expected_sales_orders_opening_counts' => $canRequestOpen && $type === self::TYPE_SALES_ORDERS
                ? $this->expectedSalesOrdersOpeningCounts($user)
                : [],
            'config' => [
                'variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                'max_float' => SalesDailySettings::maxFloatMap(),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     */
    public function requestClosing(
        User $user,
        array $cashCounts,
        ?string $lateCloseReason = null,
        ?int $sessionId = null,
        ?array $transfers = null,
        ?string $reviewNotes = null,
        array $salesOrdersCashCounts = []
    ): SalesDailyClosingRequest {
        if ($sessionId !== null) {
            $session = SalesDailySession::query()->findOrFail($sessionId);
            $this->assertCanViewSession($user, $session);
            $this->assertCanCloseSession($user, $session);
            $owner = User::query()->findOrFail($session->user_id);
        } else {
            $session = $this->assertCanCloseSession($user);
            $owner = $user;
        }

        $isLateClose = $this->isLateCloseSession($session);
        $lateCloseReason = trim((string) $lateCloseReason);

        if ($isLateClose && ! $this->canReviewAllSessions($user) && $lateCloseReason === '') {
            throw ValidationException::withMessages([
                'late_close_reason' => [__('messages.sales_daily_late_close_reason_required')],
            ]);
        }

        $pending = $session->closingRequests()->where('status', 'pending')->exists();
        if ($pending) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_closing_already_pending')],
            ]);
        }

        if ($session->isSalesOrders() && $salesOrdersCashCounts === []) {
            throw ValidationException::withMessages([
                'sales_orders_cash_counts' => ['يجب إدخال جرد صندوق الطلبيات.'],
            ]);
        }
        if (! $session->isSalesOrders() && $cashCounts === []) {
            throw ValidationException::withMessages([
                'cash_counts' => ['يجب إدخال جرد صندوق المبيعات.'],
            ]);
        }

        $normalized = $session->isSalesOrders()
            ? []
            : $this->normalizeCashCounts($owner, $session, $cashCounts);
        $ordersNormalized = $session->isSalesOrders()
            ? $this->normalizeSalesOrdersCashCounts($session, $salesOrdersCashCounts)
            : [];

        $request = DB::transaction(function () use ($user, $session, $normalized, $ordersNormalized, $isLateClose, $lateCloseReason, $owner) {
            $session->update([
                'status' => config('sales_daily.session_status.closing_requested'),
            ]);

            $request = SalesDailyClosingRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $user->id,
                'requested_at' => now(),
                'status' => 'pending',
                'instant_sales_count' => InstantSale::query()
                    ->where('sales_daily_session_id', $session->id)
                    ->whereNull('parent_id')
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                    })
                    ->count(),
                'profit_sales_count' => ProfitSale::query()
                    ->where('sales_daily_session_id', $session->id)
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                    })
                    ->count(),
                'cash_counts' => $normalized,
                'sales_orders_cash_counts' => $ordersNormalized,
                'late_close_reason' => $isLateClose && $lateCloseReason !== '' ? $lateCloseReason : null,
            ]);

            $owner->loadMissing('employee.user');
            $name = $owner->name ?? __('messages.employee_default_name');
            $actorName = $user->name ?? __('messages.employee_default_name');
            $drawerName = $session->isSalesOrders()
                ? 'صندوق الطلبيات'
                : 'صندوق المبيعات';
            $notifyBody = (int) $user->id === (int) $owner->id
                ? "{$name} طلب إغلاق {$drawerName}."
                : "{$actorName} أرسل طلب إغلاق {$drawerName} الخاص بـ {$name}.";

            $this->adminNotificationService->create(
                AdminNotificationService::TYPE_SALES_DAILY_CLOSING_REQUEST,
                "طلب إغلاق {$drawerName}",
                $notifyBody,
                [
                    'closing_request_id' => (string) $request->id,
                    'session_id' => (string) $session->id,
                    'session_type' => $session->session_type,
                ],
                $session->employee_id,
                'sales_daily_closing_request',
                $request->id
            );

            return $request->fresh(['session.user', 'session.employee.user']);
        });

        $this->logSessionActivity(
            $request->session,
            $user,
            'sales_daily_closing_requested',
            $request->session->isSalesOrders()
                ? 'طلب إغلاق صندوق الطلبيات'
                : 'طلب إغلاق صندوق المبيعات',
            $request->session->isSalesOrders()
                ? 'تم طلب إغلاق صندوق الطلبيات اليومي'
                : 'تم طلب إغلاق صندوق المبيعات اليومي',
            [
                'closing_request_id' => (int) $request->id,
                'late_close_reason' => $lateCloseReason !== '' ? $lateCloseReason : null,
            ],
            'sales_daily_closing_request',
            (int) $request->id
        );

        if ($this->canReviewAllSessions($user) && is_array($transfers)) {
            return $this->approveClosing($user, $request->id, $transfers, $reviewNotes);
        }

        return $request;
    }

    /**
     * @return array{sessions: Collection<int, array<string, mixed>>}
     */
    public function listOpenSessions(User $viewer): array
    {
        if (! $this->canReviewAllSessions($viewer)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.unauthorized')],
            ]);
        }

        $sessions = SalesDailySession::query()
            ->with(['user', 'employee.user'])
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (SalesDailySession $session) {
                $owner = User::query()->find($session->user_id);
                $pendingClosing = $session->closingRequests()
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first();

                return array_merge($this->formatSessionSummary($session), [
                    'currencies' => $session->isSalesOrders()
                        ? app(SalesOrdersDailyBoxService::class)->summary($session)
                        : ($owner ? $this->buildCurrenciesForSession($session, $owner) : []),
                    'can_close' => $session->isOpen() && ! $pendingClosing,
                    'pending_closing_request_id' => $pendingClosing?->id,
                ]);
            });

        return ['sessions' => $sessions];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClosePayloadForSession(User $viewer, int $sessionId): array
    {
        $session = SalesDailySession::query()
            ->with(['user', 'employee.user'])
            ->findOrFail($sessionId);

        $this->assertCanViewSession($viewer, $session);

        $owner = User::query()->findOrFail($session->user_id);
        $currencies = $session->isSalesOrders()
            ? []
            : $this->buildCurrenciesForSession($session, $owner);
        $ordersCurrencies = $session->isSalesOrders()
            ? app(SalesOrdersDailyBoxService::class)->summary($session)
            : [];
        $counts = $this->salesCountsForSession($session);
        $pendingClosing = $session->closingRequests()
            ->where('status', 'pending')
            ->latest('id')
            ->first();
        $pendingReopen = $session->reopenRequests()
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $canRequestClosing = $session->status === config('sales_daily.session_status.open')
            && ! $pendingClosing;
        $isLate = $this->isLateCloseSession($session);
        $requiresLateCloseReason = $canRequestClosing
            && $isLate
            && ! $this->canReviewAllSessions($viewer);

        return [
            'session' => [
                'id' => $session->id,
                'session_type' => $session->session_type,
                'user_id' => $session->user_id,
                'employee_name' => $session->user?->name,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'allows_sales' => false,
                'is_blocking_previous_day' => false,
                'has_pending_reopen' => (bool) $pendingReopen,
                'can_request_closing' => $canRequestClosing,
                'requires_late_close_reason' => $requiresLateCloseReason,
                'is_late_close' => $isLate,
            ],
            'can_finalize_closing' => $this->canReviewAllSessions($viewer),
            'session_type' => $session->session_type,
            'currencies' => $currencies,
            'sales_orders_currencies' => $ordersCurrencies,
            'expected_opening_counts' => $this->expectedOpeningCountsForSession($session),
            'instant_sales_count' => $counts['instant'],
            'profit_sales_count' => $counts['profit'],
            'pending_closing_request_id' => $pendingClosing?->id,
            'pending_reopen_request_id' => $pendingReopen?->id,
            'config' => [
                'variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                'max_float' => SalesDailySettings::maxFloatMap(),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCashCounts(User $owner, SalesDailySession $session, array $cashCounts): array
    {
        $byCurrency = collect($this->buildCurrenciesForSession($session, $owner))->keyBy('currency');
        $normalized = [];

        foreach ($byCurrency->keys() as $currency) {
            $input = collect($cashCounts)->firstWhere('currency', $currency) ?? [];
            $meta = $byCurrency->get($currency, []);
            $opening = (float) ($meta['opening_float'] ?? 0);
            $collected = (float) ($meta['sales_collected'] ?? 0);
            $systemBalance = round($opening + $collected, 2);
            $physical = round((float) ($input['physical_count'] ?? 0), 2);
            $floatToKeep = round((float) ($input['float_to_keep'] ?? 0), 2);
            $variance = round($physical - $systemBalance, 2);
            $employeeNote = trim((string) ($input['employee_note'] ?? ''));

            if ($physical < 0 || $floatToKeep < 0) {
                throw ValidationException::withMessages([
                    'cash_counts' => [__('messages.sales_daily_invalid_amounts')],
                ]);
            }

            if ($floatToKeep > $physical) {
                throw ValidationException::withMessages([
                    'cash_counts' => [__('messages.sales_daily_float_exceeds_counted')],
                ]);
            }

            $maxFloat = SalesDailySettings::maxFloatForCurrency($currency);
            if ($floatToKeep > $maxFloat) {
                throw ValidationException::withMessages([
                    'cash_counts' => [__('messages.sales_daily_float_exceeds_max', ['max' => $maxFloat, 'currency' => $currency])],
                ]);
            }

            if (config('sales_daily.variance_note_required') && abs($variance) > 0.0001 && $employeeNote === '') {
                throw ValidationException::withMessages([
                    'cash_counts' => [__('messages.sales_daily_variance_note_required')],
                ]);
            }

            $amountToTransfer = round(max(0, $physical - $floatToKeep), 2);

            $normalized[] = [
                'currency' => $currency,
                'daily_box_id' => (int) ($meta['daily_box_id'] ?? 0),
                'opening_float' => $opening,
                'sales_collected' => $collected,
                'system_balance' => $systemBalance,
                'physical_count' => $physical,
                'variance' => $variance,
                'float_to_keep' => $floatToKeep,
                'amount_to_transfer' => $amountToTransfer,
                'employee_note' => $employeeNote,
                'variance_alert' => abs($variance) >= SalesDailySettings::varianceAlertThreshold(),
            ];
        }

        return $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeSalesOrdersCashCounts(SalesDailySession $session, array $cashCounts): array
    {
        return collect(app(SalesOrdersDailyBoxService::class)->summary($session))
            ->map(function (array $meta) use ($cashCounts) {
                $currency = (string) $meta['currency'];
                $input = collect($cashCounts)->firstWhere('currency', $currency) ?? [];
                $system = round((float) $meta['system_balance'], 2);
                $physical = round((float) ($input['physical_count'] ?? $system), 2);
                $float = round((float) ($input['float_to_keep'] ?? 0), 2);
                $variance = round($physical - $system, 2);
                $note = trim((string) ($input['employee_note'] ?? ''));
                if ($physical < 0 || $float < 0 || $float > $physical) {
                    throw ValidationException::withMessages(['sales_orders_cash_counts' => ['قيم جرد صندوق الطلبيات غير صحيحة.']]);
                }
                if (config('sales_daily.variance_note_required') && abs($variance) > .0001 && $note === '') {
                    throw ValidationException::withMessages(['sales_orders_cash_counts' => [__('messages.sales_daily_variance_note_required')]]);
                }

                return [
                    'currency' => $currency,
                    'daily_box_id' => (int) $meta['daily_box_id'],
                    'opening_float' => (float) $meta['opening_float'],
                    'sales_collected' => (float) $meta['orders_collected'],
                    'system_balance' => $system,
                    'physical_count' => $physical,
                    'variance' => $variance,
                    'float_to_keep' => $float,
                    'amount_to_transfer' => round(max(0, $physical - $float), 2),
                    'employee_note' => $note,
                    'box_kind' => 'sales_orders',
                ];
            })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $transfers
     */
    public function approveClosing(User $reviewer, int $requestId, array $transfers, ?string $reviewNotes = null): SalesDailyClosingRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $transfers, $reviewNotes) {
            $closingRequest = SalesDailyClosingRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $closingRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            return $this->applyApprovedClosing($reviewer, $closingRequest, $transfers, $reviewNotes);
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function expectedSalesOrdersOpeningCounts(User $user): array
    {
        $owner = $this->resolveOwner($user);
        $query = Box::query()->where('type', config('sales_orders.daily_box.type', 'daily_sales_orders'));
        $owner['employee_id']
            ? $query->where('employee_id', $owner['employee_id'])
            : $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
        $byCurrency = $query->get()->keyBy('currency');

        return collect(config('sales_daily.default_currencies', ['شيكل']))
            ->map(fn ($currency) => [
                'currency' => $currency,
                'expected_amount' => round((float) ($byCurrency->get($currency)?->total ?? 0), 2),
            ])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @param  array<int, array<string, mixed>>  $transfers
     */
    public function directClose(
        User $reviewer,
        array $cashCounts,
        int $sessionId,
        array $transfers,
        ?string $reviewNotes = null,
        array $salesOrdersCashCounts = []
    ): SalesDailyClosingRequest {
        if (! $this->canReviewAllSessions($reviewer)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.unauthorized')],
            ]);
        }

        $session = SalesDailySession::query()->findOrFail($sessionId);
        $this->assertCanViewSession($reviewer, $session);
        $this->assertCanCloseSession($reviewer, $session);

        if ($session->closingRequests()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_closing_already_pending')],
            ]);
        }

        $owner = User::query()->findOrFail($session->user_id);
        if ($session->isSalesOrders() && $salesOrdersCashCounts === []) {
            throw ValidationException::withMessages([
                'sales_orders_cash_counts' => ['يجب إدخال جرد صندوق الطلبيات.'],
            ]);
        }
        if (! $session->isSalesOrders() && $cashCounts === []) {
            throw ValidationException::withMessages([
                'cash_counts' => ['يجب إدخال جرد صندوق المبيعات.'],
            ]);
        }

        $normalized = $session->isSalesOrders()
            ? []
            : $this->normalizeCashCounts($owner, $session, $cashCounts);
        $ordersNormalized = $session->isSalesOrders()
            ? $this->normalizeSalesOrdersCashCounts($session, $salesOrdersCashCounts)
            : [];
        $counts = $this->salesCountsForSession($session);

        return DB::transaction(function () use ($reviewer, $session, $normalized, $ordersNormalized, $counts, $transfers, $reviewNotes) {
            $closingRequest = SalesDailyClosingRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $reviewer->id,
                'requested_at' => now(),
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
                'instant_sales_count' => $counts['instant'],
                'profit_sales_count' => $counts['profit'],
                'cash_counts' => $normalized,
                'sales_orders_cash_counts' => $ordersNormalized,
            ]);

            return $this->applyApprovedClosing($reviewer, $closingRequest, $transfers, $reviewNotes);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $transfers
     */
    private function applyApprovedClosing(
        User $reviewer,
        SalesDailyClosingRequest $closingRequest,
        array $transfers,
        ?string $reviewNotes = null
    ): SalesDailyClosingRequest {
        $session = $closingRequest->session;
        $cashCounts = array_merge(
            $closingRequest->cash_counts ?? [],
            $closingRequest->sales_orders_cash_counts ?? []
        );
        $executedTransfers = [];

        foreach ($cashCounts as $row) {
            $currency = $row['currency'] ?? '';
            $fromBoxId = (int) ($row['daily_box_id'] ?? 0);
            if ($fromBoxId <= 0) {
                $fromBoxId = $this->resolveDailyBoxIdForSession($session, $currency);
            }
            $amountToTransfer = round((float) ($row['amount_to_transfer'] ?? 0), 2);
            $floatToKeep = round((float) ($row['float_to_keep'] ?? 0), 2);

            if ($fromBoxId <= 0) {
                throw ValidationException::withMessages([
                    'transfers' => [__('messages.sales_daily_box_not_found')],
                ]);
            }

            if ($amountToTransfer <= 0) {
                $fromBox = Box::lockForUpdate()->find($fromBoxId);
                if ($fromBox) {
                    $fromBox->update(['total' => $floatToKeep]);
                }

                continue;
            }

            $transferInput = collect($transfers)->firstWhere('currency', $currency);
            $toBoxId = (int) ($transferInput['to_box_id'] ?? 0);

            if ($toBoxId <= 0) {
                throw ValidationException::withMessages([
                    'transfers' => [__('messages.sales_daily_transfer_target_required', ['currency' => $currency])],
                ]);
            }

            $fromBox = Box::lockForUpdate()->findOrFail($fromBoxId);
            $toBox = Box::lockForUpdate()->findOrFail($toBoxId);

            if ($fromBox->currency !== $toBox->currency) {
                throw ValidationException::withMessages([
                    'transfers' => [__('messages.must_be_same_currency')],
                ]);
            }

            $toBox->update(['total' => (float) $toBox->total + $amountToTransfer]);
            $fromBox->update(['total' => $floatToKeep]);

            $drawerName = $session->isSalesOrders() ? 'صندوق طلبيات يومي' : 'صندوق مبيعات يومي';
            $note = 'ترحيل نهاية '.$drawerName.' #'.$session->id.' — '.$currency;
            BoxLogs::createTransferLog($fromBox, $toBox, 'ترحيل '.$drawerName, $amountToTransfer, $note);

            $executedTransfers[] = [
                'currency' => $currency,
                'from_box_id' => $fromBox->id,
                'to_box_id' => $toBox->id,
                'amount' => $amountToTransfer,
                'float_kept' => $floatToKeep,
            ];
        }

        $closingRequest->update([
            'status' => 'approved',
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
            'transfers' => $executedTransfers,
        ]);

        $session->update([
            'status' => config('sales_daily.session_status.closed'),
            'closed_at' => now(),
            'closed_by_user_id' => $reviewer->id,
        ]);

        $this->notifyEmployeeClosingApproved($session);
        $this->logSessionActivity(
            $session,
            $reviewer,
            'sales_daily_closing_approved',
            $session->isSalesOrders() ? 'اعتماد إغلاق صندوق الطلبيات' : 'اعتماد إغلاق صندوق المبيعات',
            $session->isSalesOrders() ? 'تم اعتماد إغلاق صندوق الطلبيات اليومي' : 'تم اعتماد إغلاق صندوق المبيعات اليومي',
            [
                'closing_request_id' => (int) $closingRequest->id,
                'transfers' => $executedTransfers,
                'review_notes' => $reviewNotes,
            ],
            'sales_daily_closing_request',
            (int) $closingRequest->id
        );

        return $closingRequest->fresh(['session.user', 'session.employee.user']);
    }

    public function rejectClosing(User $reviewer, int $requestId, ?string $reviewNotes = null): SalesDailyClosingRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $reviewNotes) {
            $closingRequest = SalesDailyClosingRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $closingRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            $closingRequest->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            $closingRequest->session->update([
                'status' => config('sales_daily.session_status.open'),
            ]);

            $this->notifyEmployeeClosingRejected($closingRequest->session);
            $this->logSessionActivity(
                $closingRequest->session,
                $reviewer,
                'sales_daily_closing_rejected',
                $closingRequest->session->isSalesOrders() ? 'رفض إغلاق صندوق الطلبيات' : 'رفض إغلاق صندوق المبيعات',
                $closingRequest->session->isSalesOrders() ? 'تم رفض طلب إغلاق صندوق الطلبيات اليومي' : 'تم رفض طلب إغلاق صندوق المبيعات اليومي',
                [
                    'closing_request_id' => (int) $closingRequest->id,
                    'review_notes' => $reviewNotes,
                ],
                'sales_daily_closing_request',
                (int) $closingRequest->id
            );

            return $closingRequest->fresh(['session.user', 'session.employee.user']);
        });
    }

    private function logSessionActivity(
        SalesDailySession $session,
        User $actor,
        string $action,
        string $title,
        ?string $description = null,
        array $metadata = [],
        ?string $subjectType = null,
        ?int $subjectId = null
    ): void {
        $businessDate = $session->business_date instanceof Carbon
            ? $session->business_date->toDateString()
            : (string) $session->business_date;

        app(EmployeeActivityLogger::class)->log(
            $session->employee_id ? (int) $session->employee_id : null,
            $actor,
            'sales_daily_session',
            $action,
            $title,
            $description,
            $session,
            null,
            array_filter(array_merge([
                'session_id' => (int) $session->id,
                'business_date' => $businessDate,
                'status' => $session->status,
            ], $metadata), fn ($value) => $value !== null),
            $subjectType ?? 'sales_daily_session',
            $subjectId ?? (int) $session->id
        );
    }

    private function notifyEmployeeClosingApproved(SalesDailySession $session): void
    {
        $employee = $this->resolveSessionEmployee($session);
        if (! $employee) {
            return;
        }

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $isOrders = $session->isSalesOrders();
            $this->employeeNotificationService->create(
                $employee,
                EmployeeNotificationService::TYPE_SALES_DAILY_CLOSING_APPROVED,
                $isOrders ? 'تم إغلاق صندوق الطلبيات' : 'تم إغلاق صندوق المبيعات',
                $isOrders
                    ? 'تمت الموافقة على إغلاق صندوق الطلبيات الخاص بك. لا يمكنك تسجيل طلبيات مالية عليه الآن.'
                    : __('messages.sales_daily_closing_approved_notify_body'),
                [
                    'session_id' => (string) $session->id,
                    'business_date' => $session->business_date?->toDateString() ?? '',
                    'session_type' => $session->session_type,
                ],
                'sales_daily_session',
                (int) $session->id
            );
        } finally {
            App::setLocale($previous);
        }
    }

    private function notifyEmployeeClosingRejected(SalesDailySession $session): void
    {
        $employee = $this->resolveSessionEmployee($session);
        if (! $employee) {
            return;
        }

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $isOrders = $session->isSalesOrders();
            $this->employeeNotificationService->create(
                $employee,
                EmployeeNotificationService::TYPE_SALES_DAILY_CLOSING_REJECTED,
                $isOrders ? 'تم رفض إغلاق صندوق الطلبيات' : __('messages.sales_daily_closing_rejected_notify_title'),
                $isOrders
                    ? 'تم رفض طلب إغلاق صندوق الطلبيات الخاص بك. يمكنك متابعة العمل عليه.'
                    : __('messages.sales_daily_closing_rejected_notify_body'),
                [
                    'session_id' => (string) $session->id,
                    'business_date' => $session->business_date?->toDateString() ?? '',
                    'session_type' => $session->session_type,
                ],
                'sales_daily_session',
                (int) $session->id
            );
        } finally {
            App::setLocale($previous);
        }
    }

    private function resolveSessionEmployee(SalesDailySession $session): ?EmployeeDetail
    {
        if ($session->employee_id) {
            return EmployeeDetail::query()->find($session->employee_id);
        }

        return EmployeeDetail::query()
            ->where('user_id', $session->user_id)
            ->first();
    }

    public function requestCancellation(User $user, string $saleType, int $saleId, string $reason): SalesCancellationRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('messages.sales_daily_cancel_reason_required')],
            ]);
        }

        [$sale, $session] = $this->resolveSaleForCancellation($saleType, $saleId);

        if (! $session || ! $session->isClosed()) {
            throw ValidationException::withMessages([
                'sale' => [__('messages.sales_daily_cancel_not_closed_day')],
            ]);
        }

        $exists = SalesCancellationRequest::query()
            ->where('sale_type', $saleType)
            ->where('sale_id', $saleId)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sale' => [__('messages.sales_daily_cancel_request_exists')],
            ]);
        }

        $request = SalesCancellationRequest::create([
            'sale_type' => $saleType,
            'sale_id' => $saleId,
            'session_id' => $session->id,
            'requested_by_user_id' => $user->id,
            'requested_at' => now(),
            'reason' => $reason,
            'status' => 'pending',
        ]);

        $user->loadMissing('employee.user');
        $this->adminNotificationService->create(
            AdminNotificationService::TYPE_SALES_CANCELLATION_REQUEST,
            __('messages.sales_daily_cancel_notify_title'),
            __('messages.sales_daily_cancel_notify_body', [
                'type' => $saleType === 'instant' ? 'بيع فوري' : 'ربح نقدي',
                'id' => $saleId,
            ]),
            [
                'cancellation_request_id' => (string) $request->id,
                'sale_type' => $saleType,
                'sale_id' => (string) $saleId,
            ],
            $session->employee_id,
            'sales_cancellation_request',
            $request->id
        );

        return $request->fresh(['session']);
    }

    /**
     * @return array{0: InstantSale|ProfitSale, 1: SalesDailySession|null}
     */
    private function resolveSaleForCancellation(string $saleType, int $saleId): array
    {
        if ($saleType === 'instant') {
            $sale = InstantSale::query()->whereNull('parent_id')->findOrFail($saleId);

            return [$sale, $sale->salesDailySession];
        }

        if ($saleType === 'profit') {
            $sale = ProfitSale::query()->findOrFail($saleId);

            return [$sale, $sale->salesDailySession];
        }

        throw ValidationException::withMessages([
            'sale_type' => [__('messages.validation_failed')],
        ]);
    }

    public function reversalBoxForSession(SalesDailySession $session, string $currency): ?int
    {
        $closing = $session->closingRequests()
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if (! $closing || ! is_array($closing->transfers)) {
            return null;
        }

        foreach ($closing->transfers as $transfer) {
            if (($transfer['currency'] ?? '') === $currency) {
                return isset($transfer['to_box_id']) ? (int) $transfer['to_box_id'] : null;
            }
        }

        return null;
    }

    public function saleBelongsToClosedSession(InstantSale|ProfitSale $sale): bool
    {
        $session = $sale->salesDailySession;

        return $session instanceof SalesDailySession && $session->isClosed();
    }

    public function assertCanDirectCancelSale(User $user, InstantSale|ProfitSale $sale): void
    {
        $session = $sale->salesDailySession;

        if (! $session instanceof SalesDailySession) {
            return;
        }

        if ($user->type === 'admin') {
            return;
        }

        if ($this->saleBelongsToClosedSession($sale)) {
            throw ValidationException::withMessages([
                'sale' => [__('messages.sales_daily_cancel_request_required')],
            ]);
        }

        if ((int) $session->user_id === (int) $user->id) {
            return;
        }

        if ($this->canReviewAllSessions($user)) {
            return;
        }

        if ($sale instanceof InstantSale && (int) ($sale->created_by ?? 0) === (int) $user->id) {
            return;
        }

        throw ValidationException::withMessages([
            'sale' => [__('messages.sales_daily_cancel_not_allowed')],
        ]);
    }

    public function canReviewAllSessions(User $user): bool
    {
        if ($user && $user->type === 'admin') {
            return true;
        }

        if (! $user || $user->type !== 'employee' || ! $user->employee) {
            return false;
        }

        return $user->employee->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', config('sales_daily.permissions.daily_close_review')))
            ->exists();
    }

    public function assertCanViewSession(User $viewer, SalesDailySession $session): void
    {
        if ($this->canReviewAllSessions($viewer)) {
            return;
        }

        if ((int) $session->user_id === (int) $viewer->id) {
            return;
        }

        throw ValidationException::withMessages([
            'session' => [__('messages.unauthorized')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sessions: Collection<int, array<string, mixed>>, pagination: array<string, int|null>}
     */
    public function listSessions(User $viewer, array $filters = []): array
    {
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = SalesDailySession::query()
            ->with(['user', 'employee.user'])
            ->orderByDesc('business_date')
            ->orderByDesc('id');

        if (! $this->canReviewAllSessions($viewer)) {
            $query->where('user_id', $viewer->id);
        }

        if (! empty($filters['business_date'])) {
            $query->whereDate('business_date', $filters['business_date']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('business_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('business_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $sessions = collect($paginator->items())
            ->map(fn (SalesDailySession $session) => $this->formatSessionSummary($session));

        return [
            'sessions' => $sessions,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTodayOverview(User $viewer): array
    {
        $today = $this->businessDateToday()->toDateString();

        $query = SalesDailySession::query()
            ->with(['user', 'employee.user'])
            ->where(function ($query) use ($today) {
                $query->whereDate('business_date', $today)
                    ->orWhereIn('status', [
                        config('sales_daily.session_status.open'),
                        config('sales_daily.session_status.closing_requested'),
                    ]);
            })
            ->orderByRaw("CASE WHEN status IN ('open', 'closing_requested') THEN 0 ELSE 1 END")
            ->orderByDesc('business_date')
            ->orderByDesc('id');

        if (! $this->canReviewAllSessions($viewer)) {
            $query->where('user_id', $viewer->id);
        }

        $sessions = $query->limit(100)->get()->map(function (SalesDailySession $session) {
            $summary = $this->formatSessionSummary($session);

            $owner = User::query()->find($session->user_id);
            if (! $owner) {
                return $summary;
            }

            return array_merge($summary, [
                'currencies' => $session->isSalesOrders()
                    ? app(SalesOrdersDailyBoxService::class)->summary($session)
                    : $this->buildCurrenciesForSession($session, $owner),
            ]);
        });

        return [
            'business_date' => $today,
            'sessions' => $sessions->values()->all(),
            'counts' => [
                'total' => $sessions->count(),
                'open' => $sessions->where('status', config('sales_daily.session_status.open'))->count(),
                'closing_requested' => $sessions->where('status', config('sales_daily.session_status.closing_requested'))->count(),
                'closed' => $sessions->where('status', config('sales_daily.session_status.closed'))->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSessionDetail(User $viewer, int $sessionId): array
    {
        $session = SalesDailySession::query()
            ->with(['user', 'employee.user', 'closingRequests.requestedBy', 'closingRequests.reviewedBy'])
            ->findOrFail($sessionId);

        $this->assertCanViewSession($viewer, $session);

        $owner = User::query()->findOrFail($session->user_id);
        $currencies = $session->isSalesOrders()
            ? app(SalesOrdersDailyBoxService::class)->summary($session)
            : $this->buildCurrenciesForSession($session, $owner);
        $counts = $this->salesCountsForSession($session);
        $closingRequests = $session->closingRequests
            ->sortByDesc('id')
            ->values()
            ->map(fn (SalesDailyClosingRequest $request) => $this->formatClosingRequestRow($request, $session))
            ->all();

        $canRequestClosing = $session->status === config('sales_daily.session_status.open')
            && ! $session->closingRequests->contains(fn (SalesDailyClosingRequest $r) => $r->status === 'pending');

        $salesLog = $this->buildSessionSalesLog($session);
        $ordersLog = $this->buildSessionSalesOrdersLog($session);

        return [
            'session' => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'employee_id' => $session->employee_id,
                'employee_name' => $session->user?->name,
                'opened_at' => $session->opened_at?->toDateTimeString(),
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'allows_sales' => $session->allowsSales(),
                'can_request_closing' => $canRequestClosing
                    && ($this->canReviewAllSessions($viewer) || (int) $session->user_id === (int) $viewer->id),
                'requires_late_close_reason' => $canRequestClosing
                    && $this->isLateCloseSession($session)
                    && ! $this->canReviewAllSessions($viewer),
                'opened_at' => $session->opened_at?->toDateTimeString(),
                'closed_at' => $session->closed_at?->toDateTimeString(),
                'closed_on_next_day' => $session->closed_at
                    && $session->closed_at->toDateString() > $session->business_date->toDateString(),
                'opening_balances' => $session->opening_balances ?? [],
            ],
            'currencies' => $currencies,
            'instant_sales_count' => $counts['instant'],
            'profit_sales_count' => $counts['profit'],
            'instant_sales' => $salesLog['instant_sales'],
            'profit_sales' => $salesLog['profit_sales'],
            'sales_orders_count' => count($ordersLog),
            'sales_orders' => $ordersLog,
            'closing_requests' => $closingRequests,
            'config' => [
                'variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                'max_float' => SalesDailySettings::maxFloatMap(),
            ],
        ];
    }

    /**
     * @return array{instant_sales: array<int, array<string, mixed>>, profit_sales: array<int, array<string, mixed>>}
     */
    public function buildSessionSalesLog(SalesDailySession $session): array
    {
        $sales = InstantSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->whereNull('parent_id')
            ->with(['product:id,nameAr', 'offerPackage:id,name', 'createdByUser:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $orderByInstantSale = SalesOrder::query()
            ->whereIn('instant_sale_id', $sales->pluck('id'))
            ->get()
            ->keyBy('instant_sale_id');

        $instantSales = $sales
            ->map(function (InstantSale $sale) use ($orderByInstantSale) {
                $linkedOrder = $orderByInstantSale->get($sale->id);
                $isPackage = $sale->offer_package_id !== null;
                $label = $isPackage
                    ? ($sale->offerPackage?->name ?? 'باكيج محذوف')
                    : ($sale->product?->nameAr ?? 'منتج محذوف');
                if ($linkedOrder) {
                    $label = 'طلبية '.($linkedOrder->serial_number ?? '#'.$linkedOrder->id);
                }
                $total = round((float) $sale->total_cost, 2);
                $paid = round((float) ($sale->payment_box_value ?? 0), 2);
                if ($paid > $total) {
                    $paid = $total;
                }

                return [
                    'id' => $sale->id,
                    'sale_type' => 'instant',
                    'label' => $label,
                    'invoice_number' => (string) ($sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT)),
                    'serial_number' => $sale->serial_number,
                    'maintenance_id' => $sale->maintenance_id,
                    'is_from_maintenance' => $sale->maintenance_id !== null,
                    'maintenance_invoice_number' => $sale->maintenance_id
                        ? 'MNT-'.str_pad((string) $sale->maintenance_id, 6, '0', STR_PAD_LEFT)
                        : null,
                    'is_package_sale' => $isPackage,
                    'is_from_sales_order' => $linkedOrder !== null,
                    'sales_order_id' => $linkedOrder?->id,
                    'sales_order_serial' => $linkedOrder?->serial_number,
                    'total_cost' => $total,
                    'quantity' => (float) $sale->quantity,
                    'paid_amount' => $paid,
                    'remaining_amount' => max(0, round($total - $paid, 2)),
                    'status' => $sale->status ?? 'active',
                    'created_at' => $sale->created_at?->toDateTimeString(),
                    'buyer_name' => $sale->buyer_name,
                    'created_by' => $sale->created_by,
                    'created_by_name' => $sale->createdByUser?->name,
                    'payment_box_name' => $sale->payment_box_name,
                    'payment_box_value' => $sale->payment_box_value,
                    'notes' => $sale->notes,
                ];
            })
            ->values()
            ->all();

        $linkedOrderIds = collect($instantSales)
            ->pluck('sales_order_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $sessionOrderDeliveries = SalesOrder::query()
            ->where('sales_daily_session_id', $session->id)
            ->whereNotNull('financial_posted_at')
            ->where('is_debt_collection', false)
            ->when($linkedOrderIds !== [], fn ($q) => $q->whereNotIn('id', $linkedOrderIds))
            ->orderByDesc('financial_posted_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (SalesOrder $order) {
                $total = round((float) $order->total, 2);
                $paid = round((float) $order->payment_amount, 2);
                if ($paid > $total) {
                    $paid = $total;
                }

                return [
                    'id' => $order->id,
                    'sale_type' => 'sales_order',
                    'label' => 'طلبية '.($order->serial_number ?? '#'.$order->id),
                    'is_package_sale' => false,
                    'is_from_sales_order' => true,
                    'sales_order_id' => $order->id,
                    'sales_order_serial' => $order->serial_number,
                    'total_cost' => $total,
                    'quantity' => 0,
                    'paid_amount' => $paid,
                    'remaining_amount' => max(0, round($total - $paid, 2)),
                    'status' => $order->status,
                    'created_at' => $order->financial_posted_at?->toDateTimeString(),
                    'buyer_name' => $order->customer_name,
                    'payment_box_name' => null,
                    'payment_box_value' => $paid,
                    'notes' => null,
                ];
            })
            ->values()
            ->all();

        $instantSales = collect(array_merge($instantSales, $sessionOrderDeliveries))
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? '')
            ->values()
            ->all();

        $profitSales = ProfitSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->with('createdByUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ProfitSale $sale) {
                return [
                    'id' => $sale->id,
                    'sale_type' => 'profit',
                    'label' => trim((string) ($sale->notes ?: $sale->buyer_name ?: '')) ?: ('ربح نقدي #'.$sale->id),
                    'total_cost' => round((float) $sale->total_cost, 2),
                    'paid_amount' => round((float) ($sale->payment_box_value ?? $sale->total_cost), 2),
                    'status' => $sale->status ?? 'active',
                    'created_at' => $sale->created_at?->toDateTimeString(),
                    'buyer_name' => $sale->buyer_name,
                    'created_by' => $sale->created_by,
                    'created_by_name' => $sale->createdByUser?->name,
                    'payment_box_name' => $sale->payment_box_name,
                    'payment_box_value' => $sale->payment_box_value,
                    'notes' => $sale->notes,
                ];
            })
            ->values()
            ->all();

        return [
            'instant_sales' => $instantSales,
            'profit_sales' => $profitSales,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSessionSalesOrdersLog(SalesDailySession $session): array
    {
        $businessDate = $session->business_date->toDateString();

        return SalesOrder::query()
            ->where('created_by', $session->user_id)
            ->whereDate('created_at', $businessDate)
            ->where('is_debt_collection', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'serial_number' => $order->serial_number,
                'status' => $order->status,
                'customer_name' => $order->customer_name,
                'total' => round((float) $order->total, 2),
                'payment_type' => $order->payment_type,
                'payment_amount' => round((float) $order->payment_amount, 2),
                'instant_sale_id' => $order->instant_sale_id,
                'delivered_today' => $order->sales_daily_session_id === $session->id
                    && $order->financial_posted_at !== null,
                'created_at' => $order->created_at?->toDateTimeString(),
                'financial_posted_at' => $order->financial_posted_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSessionSummary(SalesDailySession $session): array
    {
        $session->loadMissing(['user', 'employee.user']);
        $counts = $this->salesCountsForSession($session);
        $owner = User::query()->find($session->user_id);

        return [
            'id' => $session->id,
            'session_type' => $session->session_type,
            'user_id' => $session->user_id,
            'employee_id' => $session->employee_id,
            'employee_name' => $session->user?->name,
            'business_date' => $session->business_date->toDateString(),
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toDateTimeString(),
            'closed_at' => $session->closed_at?->toDateTimeString(),
            'closed_on_next_day' => $session->closed_at
                && $session->closed_at->toDateString() > $session->business_date->toDateString(),
            'instant_sales_count' => $counts['instant'],
            'profit_sales_count' => $counts['profit'],
            'currencies' => $session->isSalesOrders()
                ? app(SalesOrdersDailyBoxService::class)->summary($session)
                : ($owner ? $this->buildCurrenciesForSession($session, $owner) : []),
            'expected_opening_counts' => $session->isSalesOrders()
                ? []
                : $this->expectedOpeningCountsForSession($session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatClosingRequestRow(
        SalesDailyClosingRequest $request,
        ?SalesDailySession $session = null
    ): array {
        $request->loadMissing(['requestedBy', 'reviewedBy', 'session']);
        $session = $session ?? $request->session;
        $requestedDate = $request->requested_at?->toDateString();
        $businessDate = $session?->business_date?->toDateString();
        $isLateClose = $businessDate && $requestedDate
            ? $requestedDate > $businessDate
            : (bool) $request->late_close_reason;
        $salesLog = $session ? $this->buildSessionSalesLog($session) : [
            'instant_sales' => [],
            'profit_sales' => [],
        ];

        return [
            'id' => $request->id,
            'status' => $request->status,
            'requested_at' => $request->requested_at?->toDateTimeString(),
            'requested_date' => $requestedDate,
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'reviewed_date' => $request->reviewed_at?->toDateString(),
            'review_notes' => $request->review_notes,
            'requested_by' => $request->requestedBy?->name,
            'reviewed_by' => $request->reviewedBy?->name,
            'instant_sales_count' => $request->instant_sales_count,
            'profit_sales_count' => $request->profit_sales_count,
            'cash_counts' => $request->cash_counts,
            'sales_orders_cash_counts' => $request->sales_orders_cash_counts,
            'transfers' => $request->transfers,
            'is_late_close' => $isLateClose,
            'late_close_reason' => $request->late_close_reason,
            'business_date' => $businessDate,
            'session_type' => $session?->session_type,
            'instant_sales' => $salesLog['instant_sales'],
            'profit_sales' => $salesLog['profit_sales'],
        ];
    }

    /**
     * @return array{instant: int, profit: int}
     */
    private function salesCountsForSession(SalesDailySession $session): array
    {
        $instant = InstantSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->whereNull('parent_id')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->count();

        $profit = ProfitSale::query()
            ->where('sales_daily_session_id', $session->id)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->count();

        return ['instant' => $instant, 'profit' => $profit];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function requestReopen(User $user, string $reason): SalesDailyReopenRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => [__('messages.sales_daily_reopen_reason_required')],
            ]);
        }

        $owner = $this->resolveOwner($user);
        $today = $this->businessDateToday()->toDateString();

        $session = SalesDailySession::query()
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $today)
            ->first();

        if (! $session || ! $session->isClosed()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_reopen_not_closed_day')],
            ]);
        }

        if ($session->reopenRequests()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_reopen_already_pending')],
            ]);
        }

        return DB::transaction(function () use ($user, $session, $reason) {
            $request = SalesDailyReopenRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $user->id,
                'requested_at' => now(),
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $user->loadMissing('employee.user');
            $name = $user->name ?? __('messages.employee_default_name');

            $this->adminNotificationService->create(
                AdminNotificationService::TYPE_SALES_DAILY_REOPEN_REQUEST,
                __('messages.sales_daily_reopen_notify_title'),
                __('messages.sales_daily_reopen_notify_body', ['employee' => $name]),
                [
                    'reopen_request_id' => (string) $request->id,
                    'session_id' => (string) $session->id,
                ],
                $session->employee_id,
                'sales_daily_reopen_request',
                $request->id
            );

            return $request->fresh(['session.user', 'session.employee.user']);
        });
    }

    public function approveReopen(User $reviewer, int $requestId, ?string $reviewNotes = null): SalesDailyReopenRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $reviewNotes) {
            $reopenRequest = SalesDailyReopenRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $reopenRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            $session = $reopenRequest->session;
            if (! $session->isClosed()) {
                throw ValidationException::withMessages([
                    'session' => [__('messages.sales_daily_reopen_session_not_closed')],
                ]);
            }

            $reopenRequest->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            $session->update([
                'status' => config('sales_daily.session_status.open'),
                'closed_at' => null,
                'closed_by_user_id' => null,
            ]);

            $this->notifyEmployeeReopenApproved($session);

            return $reopenRequest->fresh(['session.user', 'session.employee.user']);
        });
    }

    public function rejectReopen(User $reviewer, int $requestId, ?string $reviewNotes = null): SalesDailyReopenRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $reviewNotes) {
            $reopenRequest = SalesDailyReopenRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $reopenRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            $reopenRequest->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            $this->notifyEmployeeReopenRejected($reopenRequest->session);

            return $reopenRequest->fresh(['session.user', 'session.employee.user']);
        });
    }

    private function notifyEmployeeReopenApproved(SalesDailySession $session): void
    {
        $employee = $this->resolveSessionEmployee($session);
        if (! $employee) {
            return;
        }

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $this->employeeNotificationService->create(
                $employee,
                EmployeeNotificationService::TYPE_SALES_DAILY_REOPEN_APPROVED,
                __('messages.sales_daily_reopen_approved_notify_title'),
                __('messages.sales_daily_reopen_approved_notify_body'),
                [
                    'session_id' => (string) $session->id,
                    'business_date' => $session->business_date?->toDateString() ?? '',
                ],
                'sales_daily_session',
                (int) $session->id
            );
        } finally {
            App::setLocale($previous);
        }
    }

    private function notifyEmployeeReopenRejected(SalesDailySession $session): void
    {
        $employee = $this->resolveSessionEmployee($session);
        if (! $employee) {
            return;
        }

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $this->employeeNotificationService->create(
                $employee,
                EmployeeNotificationService::TYPE_SALES_DAILY_REOPEN_REJECTED,
                __('messages.sales_daily_reopen_rejected_notify_title'),
                __('messages.sales_daily_reopen_rejected_notify_body'),
                [
                    'session_id' => (string) $session->id,
                    'business_date' => $session->business_date?->toDateString() ?? '',
                ],
                'sales_daily_session',
                (int) $session->id
            );
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * @return Collection<int, SalesDailyReopenRequest>
     */
    public function pendingReopenRequests(): Collection
    {
        return SalesDailyReopenRequest::query()
            ->with(['session.user', 'session.employee.user', 'requestedBy'])
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();
    }

    private function resolveDailyBoxIdForSession(SalesDailySession $session, string $currency): int
    {
        $owner = User::query()->find($session->user_id);
        if (! $owner) {
            return 0;
        }

        $box = $this->ensureDailyBoxes($owner)->firstWhere('currency', $currency);

        return $box ? (int) $box->id : 0;
    }

    private function buildCurrenciesForSession(SalesDailySession $session, User $owner): array
    {
        $dailyBoxes = $this->ensureDailyBoxes($owner);
        $salesCollected = $this->salesCollectedByCurrency($session);
        $openingBalances = $session->opening_balances ?? [];
        $currencies = [];

        foreach ($dailyBoxes as $box) {
            $currency = $box->currency;
            $opening = round((float) ($openingBalances[$currency] ?? 0), 2);
            $collected = round((float) ($salesCollected[$currency] ?? 0), 2);
            $systemBalance = round($opening + $collected, 2);

            $currencies[] = [
                'currency' => $currency,
                'daily_box_id' => $box->id,
                'daily_box_name' => $box->name,
                'box_balance' => round((float) $box->total, 2),
                'opening_float' => $opening,
                'sales_collected' => $collected,
                'system_balance' => $systemBalance,
            ];
        }

        return $currencies;
    }
}
