<?php

namespace App\Services;

use App\Models\Box;
use App\Models\SalesDailySession;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SalesOrdersDailyBoxService
{
    public function __construct(private SalesDailySessionService $sessions) {}

    /** @return Collection<int, Box> */
    public function ensureBoxes(User $user, ?SalesDailySession $session = null): Collection
    {
        $session ??= $this->sessions->assertCanCreateSale(
            $user,
            SalesDailySessionService::TYPE_SALES_ORDERS
        );
        $owner = $session->user()->with('employee')->first() ?? $user;
        $employeeId = $session->employee_id ?: $owner->employee?->id;
        $prefix = config('sales_orders.daily_box.name_prefix', 'صندوق الطلبيات اليومي');
        $type = config('sales_orders.daily_box.type', 'daily_sales_orders');

        foreach (config('sales_daily.default_currencies', ['شيكل']) as $currency) {
            $query = Box::query()->where('type', $type)->where('currency', $currency);
            $employeeId
                ? $query->where('employee_id', $employeeId)
                : $query->where('user_id', $session->user_id)->whereNull('employee_id');

            if (! $query->exists()) {
                Box::create([
                    'name' => $prefix.' - '.($owner->name ?? 'مستخدم').' - '.$currency,
                    'type' => $type,
                    'employee_id' => $employeeId,
                    'user_id' => $employeeId ? null : $session->user_id,
                    'total' => 0,
                    'is_shown' => 0,
                    'currency' => $currency,
                ]);
            }
        }

        return $this->boxesForSession($session);
    }

    /** @return Collection<int, Box> */
    public function boxesForSession(SalesDailySession $session): Collection
    {
        $query = Box::query()->where('type', config('sales_orders.daily_box.type', 'daily_sales_orders'));
        $session->employee_id
            ? $query->where('employee_id', $session->employee_id)
            : $query->where('user_id', $session->user_id)->whereNull('employee_id');

        return $query->orderByRaw("CASE WHEN currency = 'شيكل' THEN 0 ELSE 1 END")->get();
    }

    public function resolve(User $user, ?int $boxId = null): array
    {
        $boxes = $this->ensureBoxes($user);
        $box = $boxId ? $boxes->firstWhere('id', $boxId) : $boxes->first();
        if (! $box) {
            throw ValidationException::withMessages([
                'payment_box_id' => ['يجب اختيار صندوق طلبيات تابع للجلسة اليومية المفتوحة.'],
            ]);
        }

        return ['id' => (int) $box->id, 'name' => (string) $box->name];
    }

    /** @return list<array<string, mixed>> */
    public function summary(SalesDailySession $session): array
    {
        return $this->boxesForSession($session)->map(function (Box $box) use ($session) {
            $collected = (float) SalesOrderSettlement::query()
                ->where('sales_daily_session_id', $session->id)
                ->where('box_id', $box->id)
                ->sum('amount');
            $balance = round((float) $box->total, 2);
            $opening = round((float) (($session->sales_orders_opening_balances ?? [])[$box->currency] ?? 0), 2);

            return [
                'currency' => $box->currency,
                'daily_box_id' => $box->id,
                'daily_box_name' => $box->name,
                'box_balance' => $balance,
                'opening_float' => $opening,
                'orders_collected' => round($collected, 2),
                'sales_collected' => round($collected, 2),
                'system_balance' => round($opening + $collected, 2),
            ];
        })->values()->all();
    }
}
