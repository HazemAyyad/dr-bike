<?php

namespace App\Services;

use App\Models\Box;
use App\Models\Maintenance;
use App\Models\MaintenanceDailyBoxLog;
use App\Models\MaintenanceDailySession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceDailyBoxService
{
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

    public function businessDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfDay();
    }

    public function ensureBox(User $user): Box
    {
        $owner = $this->resolveOwner($user);
        $displayName = $user->name ?? 'مستخدم';

        $query = Box::query()
            ->where('type', config('maintenance_daily.box_type', 'daily_maintenance'))
            ->where('currency', config('maintenance_daily.currency', 'شيكل'));

        if ($owner['employee_id']) {
            $query->where('employee_id', $owner['employee_id']);
        } else {
            $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
        }

        $box = $query->first();

        if ($box) {
            return $box;
        }

        return Box::create([
            'name' => config('maintenance_daily.box_name', 'صندوق الصيانة اليومي').' - '.$displayName,
            'type' => config('maintenance_daily.box_type', 'daily_maintenance'),
            'employee_id' => $owner['employee_id'],
            'user_id' => $owner['employee_id'] ? null : $owner['user_id'],
            'total' => 0,
            'is_shown' => 0,
            'currency' => config('maintenance_daily.currency', 'شيكل'),
        ]);
    }

    public function assertWithinOpenWindow(?Carbon $at = null): void
    {
        $at = $at ?? now();
        $openAt = $at->copy()->setTimeFromTimeString(config('maintenance_daily.open_time', '08:00'));
        $closeAt = $at->copy()->addDay()->startOfDay();

        if ($at->lt($openAt) || $at->gte($closeAt)) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => [
                    'صندوق الصيانة اليومي يعمل من الساعة 08:00 صباحاً حتى منتصف الليل.',
                ],
            ]);
        }
    }

    public function currentSession(User $user, ?Carbon $at = null): ?MaintenanceDailySession
    {
        $at = $at ?? now();
        $owner = $this->resolveOwner($user);
        $date = $this->businessDate($at);

        return MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type'])
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $date)
            ->orderByDesc('id')
            ->first();
    }

    public function requireOpenSession(User $user, ?Carbon $at = null): MaintenanceDailySession
    {
        $at = $at ?? now();
        $this->assertWithinOpenWindow($at);

        $session = $this->currentSession($user, $at);

        if (! $session || ! $session->isOpen()) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => ['يجب فتح صندوق الصيانة اليومي قبل تسليم الصيانة.'],
            ]);
        }

        return $session;
    }

    public function openToday(?User $user = null, ?Carbon $at = null, float $openingBalance = 0): MaintenanceDailySession
    {
        $at = $at ?? now();
        $this->assertWithinOpenWindow($at);

        if (! $user) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => ['لا يمكن فتح صندوق الصيانة بدون مستخدم.'],
            ]);
        }

        return $this->openSession($this->businessDate($at), $user, $openingBalance);
    }

    private function openSession(Carbon $date, User $user, float $openingBalance = 0): MaintenanceDailySession
    {
        return DB::transaction(function () use ($user, $date, $openingBalance) {
            $owner = $this->resolveOwner($user);
            $box = Box::lockForUpdate()->find($this->ensureBox($user)->id);
            $session = MaintenanceDailySession::query()
                ->where('user_id', $owner['user_id'])
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->first();

            if ($session) {
                if (! $session->isOpen()) {
                    throw ValidationException::withMessages([
                        'maintenance_daily_box' => ['تم إغلاق صندوق الصيانة لهذا اليوم.'],
                    ]);
                }

                return $session;
            }

            $openingBalance = round(max(0, $openingBalance), 2);
            $box->total = $openingBalance;
            $box->save();

            return MaintenanceDailySession::create([
                'user_id' => $owner['user_id'],
                'employee_id' => $owner['employee_id'],
                'business_date' => $date->toDateString(),
                'status' => config('maintenance_daily.session_status.open', 'open'),
                'box_id' => $box->id,
                'opening_balance' => $openingBalance,
                'opened_at' => now(),
                'opened_by_user_id' => $user?->id,
            ]);
        });
    }

    public function requestClosing(User $user, ?string $note = null, ?Carbon $at = null): MaintenanceDailySession
    {
        $at = $at ?? now();

        return DB::transaction(function () use ($user, $note, $at) {
            $session = $this->requireOpenSession($user, $at);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);

            $session->update([
                'status' => config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                'closing_balance' => round((float) $box->total, 2),
                'closing_requested_at' => now(),
                'closing_requested_by_user_id' => $user->id,
                'closing_request_note' => $note ? trim($note) : null,
            ]);

            return $session->fresh(['box', 'user', 'closingRequestedBy']);
        });
    }

    public function pendingClosingRequests()
    {
        return MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type', 'user:id,name', 'closingRequestedBy:id,name'])
            ->where('status', config('maintenance_daily.session_status.closing_requested', 'closing_requested'))
            ->orderByDesc('closing_requested_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MaintenanceDailySession $session) => $this->formatClosingRequest($session))
            ->values();
    }

    public function approveClosing(User $reviewer, int $sessionId, ?string $note = null): MaintenanceDailySession
    {
        return DB::transaction(function () use ($reviewer, $sessionId, $note) {
            $session = MaintenanceDailySession::query()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $session->isClosingRequested()) {
                throw ValidationException::withMessages([
                    'session' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $box = Box::lockForUpdate()->find($session->box_id);
            $closingBalance = round((float) ($box?->total ?? $session->closing_balance ?? 0), 2);

            $session->update([
                'status' => config('maintenance_daily.session_status.closed', 'closed'),
                'closing_balance' => $closingBalance,
                'closed_at' => now(),
                'closed_by_user_id' => $reviewer->id,
                'notes' => trim((string) $session->notes."\nاعتماد إغلاق الصندوق بواسطة: {$reviewer->name}".($note ? " | {$note}" : '')),
            ]);

            return $session->fresh(['box', 'user', 'closingRequestedBy', 'closedBy']);
        });
    }

    public function rejectClosing(User $reviewer, int $sessionId, ?string $note = null): MaintenanceDailySession
    {
        return DB::transaction(function () use ($reviewer, $sessionId, $note) {
            $session = MaintenanceDailySession::query()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $session->isClosingRequested()) {
                throw ValidationException::withMessages([
                    'session' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $session->update([
                'status' => config('maintenance_daily.session_status.open', 'open'),
                'closing_balance' => null,
                'closing_requested_at' => null,
                'closing_requested_by_user_id' => null,
                'closing_request_note' => null,
                'notes' => trim((string) $session->notes."\nرفض طلب الإغلاق بواسطة: {$reviewer->name}".($note ? " | {$note}" : '')),
            ]);

            return $session->fresh(['box', 'user']);
        });
    }

    /**
     * @return array{session: MaintenanceDailySession, box: Box, log: MaintenanceDailyBoxLog}
     */
    public function recordPayment(
        Maintenance $maintenance,
        User $user,
        float $amount,
        ?int $instantSaleId = null,
        string $method = 'cash',
        ?string $note = null
    ): array {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'payment_amount' => ['قيمة حركة صندوق الصيانة يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($maintenance, $user, $amount, $instantSaleId, $method, $note) {
            $session = $this->requireOpenSession($user);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $before = round((float) $box->total, 2);
            $affectsCash = $method === 'cash';
            $after = $affectsCash ? round($before + $amount, 2) : $before;

            if ($affectsCash) {
                $box->total = $after;
                $box->save();
            }

            $log = MaintenanceDailyBoxLog::create([
                'session_id' => $session->id,
                'box_id' => $box->id,
                'maintenance_id' => $maintenance->id,
                'instant_sale_id' => $instantSaleId,
                'user_id' => $user->id,
                'actor_name' => $user->name,
                'type' => 'add',
                'payment_method' => $method,
                'affects_cash_balance' => $affectsCash,
                'amount' => round($amount, 2),
                'box_balance_before' => $before,
                'box_balance_after' => $after,
                'description' => $this->paymentDescription($method, $maintenance),
                'note' => trim('صيانة #'.$maintenance->id.' | المستخدم: '.$user->name.($note ? ' | '.$note : '')),
            ]);

            return [
                'session' => $session->fresh(['box']),
                'box' => $box,
                'log' => $log,
            ];
        });
    }

    public function closeExpiredSessions(?Carbon $at = null): int
    {
        $at = $at ?? now();
        $today = $this->businessDate($at)->toDateString();
        $closed = 0;

        MaintenanceDailySession::query()
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->whereDate('business_date', '<', $today)
            ->with('box')
            ->chunkById(50, function ($sessions) use (&$closed, $at) {
                foreach ($sessions as $session) {
                    $session->update([
                        'status' => config('maintenance_daily.session_status.closed', 'closed'),
                        'closing_balance' => round((float) ($session->box?->total ?? 0), 2),
                        'closed_at' => $at,
                        'notes' => trim((string) $session->notes."\nإغلاق تلقائي عند منتصف الليل."),
                    ]);
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?string $date = null, ?User $user = null): array
    {
        $businessDate = $date
            ? Carbon::parse($date)->toDateString()
            : $this->businessDate()->toDateString();

        $box = $user ? $this->ensureBox($user) : null;
        $sessionQuery = MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type', 'user:id,name', 'closingRequestedBy:id,name'])
            ->whereDate('business_date', $businessDate);

        if ($user) {
            $sessionQuery->where('user_id', $this->resolveOwner($user)['user_id']);
        }

        $session = $sessionQuery->orderByDesc('id')->first();

        $logs = $session
            ? MaintenanceDailyBoxLog::query()
                ->where('session_id', $session->id)
                ->with(['maintenance:id,customer_id,seller_id', 'user:id,name'])
                ->with(['instantSale:id,serial_number'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (MaintenanceDailyBoxLog $log) => [
                    'id' => $log->id,
                    'maintenance_id' => $log->maintenance_id,
                    'instant_sale_id' => $log->instant_sale_id,
                    'invoice_number' => $log->instantSale?->serial_number,
                    'instant_sale_serial' => $log->instantSale?->serial_number,
                    'user_id' => $log->user_id,
                    'actor_name' => $log->actor_name ?? $log->user?->name,
                    'type' => $log->type,
                    'payment_method' => $log->payment_method,
                    'affects_cash_balance' => (bool) ($log->affects_cash_balance ?? true),
                    'amount' => round((float) $log->amount, 2),
                    'box_balance_before' => round((float) $log->box_balance_before, 2),
                    'box_balance_after' => round((float) $log->box_balance_after, 2),
                    'description' => $log->description,
                    'note' => $log->note,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->values()
            : collect();

        $cashTotal = round((float) $logs
            ->where('affects_cash_balance', true)
            ->filter(fn ($log) => in_array($log['payment_method'] ?? null, ['cash', null], true))
            ->sum('amount'), 2);
        $visaTotal = round((float) $logs
            ->where('payment_method', 'visa')
            ->sum('amount'), 2);
        $transferTotal = round((float) $logs
            ->where('payment_method', 'bank_transfer')
            ->sum('amount'), 2);
        $expectedClosingBalance = round((float) ($session?->opening_balance ?? 0) + $cashTotal, 2);

        return [
            'session' => $session ? [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'employee_name' => $session->user?->name,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'opening_balance' => round((float) $session->opening_balance, 2),
                'closing_balance' => $session->closing_balance !== null
                    ? round((float) $session->closing_balance, 2)
                    : null,
                'expected_closing_balance' => $expectedClosingBalance,
                'opened_at' => optional($session->opened_at)->format('Y-m-d H:i:s'),
                'closing_requested_at' => optional($session->closing_requested_at)->format('Y-m-d H:i:s'),
                'closing_requested_by_name' => $session->closingRequestedBy?->name,
                'closing_request_note' => $session->closing_request_note,
                'closed_at' => optional($session->closed_at)->format('Y-m-d H:i:s'),
            ] : null,
            'box' => $box ? [
                'id' => $box->id,
                'name' => $box->name,
                'currency' => $box->currency,
                'total' => round((float) $box->total, 2),
            ] : null,
            'logs' => $logs,
            'logs_total' => round((float) $logs->sum('amount'), 2),
            'cash_total' => $cashTotal,
            'visa_total' => $visaTotal,
            'transfer_total' => $transferTotal,
            'non_cash_total' => round((float) $logs
                ->where('affects_cash_balance', false)
                ->sum('amount'), 2),
            'expected_closing_balance' => $expectedClosingBalance,
            'config' => [
                'open_time' => config('maintenance_daily.open_time', '08:00'),
                'close_time' => config('maintenance_daily.close_time', '00:00'),
            ],
        ];
    }

    private function paymentDescription(string $method, Maintenance $maintenance): string
    {
        return match ($method) {
            'visa' => 'قبض فيزا صيانة #'.$maintenance->id,
            'bank_transfer' => 'قبض حوالة صيانة #'.$maintenance->id,
            default => 'قبض كاش صيانة #'.$maintenance->id,
        };
    }

    public function formatClosingRequest(MaintenanceDailySession $session): array
    {
        $payload = $this->payload($session->business_date?->toDateString(), $session->user);
        $sessionPayload = $payload['session'] ?? [];

        return [
            'id' => $session->id,
            'session_id' => $session->id,
            'employee_name' => $session->user?->name,
            'business_date' => $session->business_date?->toDateString(),
            'status' => $session->status,
            'opening_balance' => round((float) $session->opening_balance, 2),
            'cash_total' => round((float) ($payload['cash_total'] ?? 0), 2),
            'visa_total' => round((float) ($payload['visa_total'] ?? 0), 2),
            'transfer_total' => round((float) ($payload['transfer_total'] ?? 0), 2),
            'expected_closing_balance' => round((float) ($payload['expected_closing_balance'] ?? 0), 2),
            'closing_balance' => $session->closing_balance !== null
                ? round((float) $session->closing_balance, 2)
                : ($sessionPayload['closing_balance'] ?? null),
            'requested_at' => optional($session->closing_requested_at)->format('Y-m-d H:i:s'),
            'requested_by_name' => $session->closingRequestedBy?->name,
            'note' => $session->closing_request_note,
            'box_name' => $session->box?->name,
            'currency' => $session->box?->currency,
        ];
    }
}
