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
    public function businessDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfDay();
    }

    public function ensureBox(): Box
    {
        $box = Box::query()
            ->where('type', config('maintenance_daily.box_type', 'daily_maintenance'))
            ->where('currency', config('maintenance_daily.currency', 'شيكل'))
            ->first();

        if ($box) {
            return $box;
        }

        return Box::create([
            'name' => config('maintenance_daily.box_name', 'صندوق الصيانة اليومي'),
            'type' => config('maintenance_daily.box_type', 'daily_maintenance'),
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

    public function getOrOpenSession(User $user, ?Carbon $at = null): MaintenanceDailySession
    {
        $at = $at ?? now();
        $this->assertWithinOpenWindow($at);
        $date = $this->businessDate($at);

        return $this->openSession($date, $user);
    }

    public function openToday(?User $user = null, ?Carbon $at = null): MaintenanceDailySession
    {
        $at = $at ?? now();
        $this->assertWithinOpenWindow($at);

        return $this->openSession($this->businessDate($at), $user);
    }

    private function openSession(Carbon $date, ?User $user = null): MaintenanceDailySession
    {
        return DB::transaction(function () use ($user, $date) {
            $box = Box::lockForUpdate()->find($this->ensureBox()->id);
            $session = MaintenanceDailySession::query()
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

            return MaintenanceDailySession::create([
                'business_date' => $date->toDateString(),
                'status' => config('maintenance_daily.session_status.open', 'open'),
                'box_id' => $box->id,
                'opening_balance' => round((float) $box->total, 2),
                'opened_at' => now(),
                'opened_by_user_id' => $user?->id,
            ]);
        });
    }

    /**
     * @return array{session: MaintenanceDailySession, box: Box, log: MaintenanceDailyBoxLog}
     */
    public function recordPayment(
        Maintenance $maintenance,
        User $user,
        float $amount,
        ?int $instantSaleId = null
    ): array {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'payment_amount' => ['قيمة حركة صندوق الصيانة يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($maintenance, $user, $amount, $instantSaleId) {
            $session = $this->getOrOpenSession($user);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $before = round((float) $box->total, 2);
            $after = round($before + $amount, 2);

            $box->total = $after;
            $box->save();

            $log = MaintenanceDailyBoxLog::create([
                'session_id' => $session->id,
                'box_id' => $box->id,
                'maintenance_id' => $maintenance->id,
                'instant_sale_id' => $instantSaleId,
                'user_id' => $user->id,
                'actor_name' => $user->name,
                'type' => 'add',
                'amount' => round($amount, 2),
                'box_balance_before' => $before,
                'box_balance_after' => $after,
                'description' => 'قبض صيانة #'.$maintenance->id,
                'note' => 'صيانة #'.$maintenance->id.' | المستخدم: '.$user->name,
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
            ->where('status', config('maintenance_daily.session_status.open', 'open'))
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
    public function payload(?string $date = null): array
    {
        $businessDate = $date
            ? Carbon::parse($date)->toDateString()
            : $this->businessDate()->toDateString();

        $box = $this->ensureBox();
        $session = MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type'])
            ->whereDate('business_date', $businessDate)
            ->first();

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
                    'amount' => round((float) $log->amount, 2),
                    'box_balance_before' => round((float) $log->box_balance_before, 2),
                    'box_balance_after' => round((float) $log->box_balance_after, 2),
                    'description' => $log->description,
                    'note' => $log->note,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->values()
            : collect();

        return [
            'session' => $session ? [
                'id' => $session->id,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'opening_balance' => round((float) $session->opening_balance, 2),
                'closing_balance' => $session->closing_balance !== null
                    ? round((float) $session->closing_balance, 2)
                    : null,
                'opened_at' => optional($session->opened_at)->format('Y-m-d H:i:s'),
                'closed_at' => optional($session->closed_at)->format('Y-m-d H:i:s'),
            ] : null,
            'box' => [
                'id' => $box->id,
                'name' => $box->name,
                'currency' => $box->currency,
                'total' => round((float) $box->total, 2),
            ],
            'logs' => $logs,
            'logs_total' => round((float) $logs->sum('amount'), 2),
            'config' => [
                'open_time' => config('maintenance_daily.open_time', '08:00'),
                'close_time' => config('maintenance_daily.close_time', '00:00'),
            ],
        ];
    }
}
