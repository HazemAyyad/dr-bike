<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesCancellationRequest;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailySession;
use App\Models\User;
use App\Support\SalesDailySettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDailySessionService
{
    public function __construct(
        protected AdminNotificationService $adminNotificationService
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

    /**
     * @return Collection<int, Box>
     */
    public function ensureDailyBoxes(User $user): Collection
    {
        $owner = $this->resolveOwner($user);
        $user->loadMissing('employee.user');
        $displayName = $user->name ?? 'مستخدم';

        $boxes = collect();

        foreach (config('sales_daily.currencies', []) as $currency) {
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

            $boxes->push($box);
        }

        return $boxes;
    }

    public function findBlockingSession(User $user): ?SalesDailySession
    {
        $owner = $this->resolveOwner($user);
        $today = $this->businessDateToday()->toDateString();

        return SalesDailySession::query()
            ->where('user_id', $owner['user_id'])
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->where('business_date', '<', $today)
            ->orderBy('business_date')
            ->first();
    }

    public function getActiveSession(User $user, bool $autoOpen = true): ?SalesDailySession
    {
        $owner = $this->resolveOwner($user);
        $today = $this->businessDateToday();

        $blocking = $this->findBlockingSession($user);
        if ($blocking && ! $autoOpen) {
            return $blocking;
        }

        if ($blocking) {
            return $blocking;
        }

        $session = SalesDailySession::query()
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $today)
            ->orderByDesc('id')
            ->first();

        if ($session) {
            return $session;
        }

        if (! $autoOpen) {
            return null;
        }

        return $this->openSession($user, $today);
    }

    public function openSession(User $user, ?Carbon $date = null): SalesDailySession
    {
        $owner = $this->resolveOwner($user);
        $date = ($date ?? $this->businessDateToday())->copy()->startOfDay();

        if ($this->findBlockingSession($user)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_previous_day_open')],
            ]);
        }

        $existing = SalesDailySession::query()
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $date)
            ->first();

        if ($existing) {
            return $existing;
        }

        $dailyBoxes = $this->ensureDailyBoxes($user);
        $openingBalances = [];
        foreach ($dailyBoxes as $box) {
            $openingBalances[$box->currency] = round((float) $box->total, 2);
        }

        return SalesDailySession::create([
            'user_id' => $owner['user_id'],
            'employee_id' => $owner['employee_id'],
            'business_date' => $date->toDateString(),
            'status' => config('sales_daily.session_status.open'),
            'opening_balances' => $openingBalances,
            'opened_at' => now(),
            'opened_by_user_id' => $owner['user_id'],
        ]);
    }

    public function assertCanCreateSale(User $user): SalesDailySession
    {
        $session = $this->getActiveSession($user);

        if (! $session) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_no_session')],
            ]);
        }

        if ($session->business_date->toDateString() < $this->businessDateToday()->toDateString()) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_previous_day_open')],
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

    public function assertDailyBoxOwnedByUser(User $user, Box $box): void
    {
        if (! $box->isDailySalesBox()) {
            return;
        }

        $owner = $this->resolveOwner($user);

        if ($owner['employee_id'] && (int) $box->employee_id === $owner['employee_id']) {
            return;
        }

        if (! $owner['employee_id'] && (int) $box->user_id === $owner['user_id']) {
            return;
        }

        if ($user->type === 'admin') {
            return;
        }

        throw ValidationException::withMessages([
            'box_id' => [__('messages.unauthorized')],
        ]);
    }

    public function assertSessionAllowsPayment(User $user, Box $box): void
    {
        $this->assertDailyBoxOwnedByUser($user, $box);
        $this->assertCanCreateSale($user);
    }

    /**
     * @return array<string, float>
     */
    public function salesCollectedByCurrency(SalesDailySession $session): array
    {
        $totals = array_fill_keys(config('sales_daily.currencies', []), 0.0);

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
            if ($currency && isset($totals[$currency])) {
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
            if ($currency && isset($totals[$currency])) {
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
     * @return array<string, mixed>
     */
    public function buildSessionPayload(User $user): array
    {
        $blocking = $this->findBlockingSession($user);
        $session = $blocking ?? $this->getActiveSession($user, autoOpen: true);
        $dailyBoxes = $this->ensureDailyBoxes($user);
        $salesCollected = $session ? $this->salesCollectedByCurrency($session) : [];
        $openingBalances = $session?->opening_balances ?? [];

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
        }

        return [
            'session' => $session ? [
                'id' => $session->id,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'allows_sales' => $session->allowsSales() && ! $blocking,
                'is_blocking_previous_day' => (bool) $blocking,
            ] : null,
            'currencies' => $currencies,
            'instant_sales_count' => $instantCount,
            'profit_sales_count' => $profitCount,
            'pending_closing_request_id' => $pendingClosing?->id,
            'config' => [
                'variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                'max_float' => SalesDailySettings::maxFloatMap(),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     */
    public function requestClosing(User $user, array $cashCounts): SalesDailyClosingRequest
    {
        $session = $this->assertCanCreateSale($user);

        $pending = $session->closingRequests()->where('status', 'pending')->exists();
        if ($pending) {
            throw ValidationException::withMessages([
                'session' => [__('messages.sales_daily_closing_already_pending')],
            ]);
        }

        $normalized = $this->normalizeCashCounts($user, $session, $cashCounts);

        return DB::transaction(function () use ($user, $session, $normalized) {
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
            ]);

            $user->loadMissing('employee.user');
            $name = $user->name ?? __('messages.employee_default_name');

            $this->adminNotificationService->create(
                AdminNotificationService::TYPE_SALES_DAILY_CLOSING_REQUEST,
                __('messages.sales_daily_closing_notify_title'),
                __('messages.sales_daily_closing_notify_body', ['employee' => $name]),
                [
                    'closing_request_id' => (string) $request->id,
                    'session_id' => (string) $session->id,
                ],
                $session->employee_id,
                'sales_daily_closing_request',
                $request->id
            );

            return $request->fresh(['session.user', 'session.employee.user']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCashCounts(User $user, SalesDailySession $session, array $cashCounts): array
    {
        $payload = $this->buildSessionPayload($user);
        $byCurrency = collect($payload['currencies'])->keyBy('currency');
        $normalized = [];

        foreach (config('sales_daily.currencies', []) as $currency) {
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

            $session = $closingRequest->session;
            $cashCounts = $closingRequest->cash_counts ?? [];
            $executedTransfers = [];

            foreach ($cashCounts as $row) {
                $currency = $row['currency'] ?? '';
                $fromBoxId = (int) ($row['daily_box_id'] ?? 0);
                $amountToTransfer = round((float) ($row['amount_to_transfer'] ?? 0), 2);
                $floatToKeep = round((float) ($row['float_to_keep'] ?? 0), 2);

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

                $note = 'ترحيل نهاية يوم مبيعات #'.$session->id.' — '.$currency;
                BoxLogs::createTransferLog($fromBox, $toBox, 'ترحيل صندوق مبيعات يومي', $amountToTransfer, $note);

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

            return $closingRequest->fresh(['session.user', 'session.employee.user']);
        });
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

            return $closingRequest->fresh(['session.user', 'session.employee.user']);
        });
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
        if (! $this->saleBelongsToClosedSession($sale)) {
            return;
        }

        if ($user->type === 'admin') {
            return;
        }

        throw ValidationException::withMessages([
            'sale' => [__('messages.sales_daily_cancel_request_required')],
        ]);
    }
}
