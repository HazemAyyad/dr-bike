<?php

namespace App\Services;

use App\Models\Box;
use App\Models\MaintenanceDailySession;
use App\Models\SalesDailySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ExpenseBoxAccessService
{
    /**
     * Regular boxes explicitly visible to the actor, plus every currently open
     * daily sales/maintenance box. Open daily boxes are intentionally global.
     *
     * @return Collection<int, Box>
     */
    public function availableBoxes(User $user): Collection
    {
        $regularIds = $this->regularBoxIds($user);
        $dailyIds = $this->openDailyBoxIds();

        return Box::query()
            ->where(function (Builder $query) use ($regularIds, $dailyIds) {
                if ($regularIds === null) {
                    $query->where(function (Builder $regular) {
                        $regular->where('is_shown', 1)
                            ->where(function (Builder $type) {
                                $type->whereNull('type')
                                    ->orWhereNotIn('type', $this->dailyTypes());
                            });
                    });
                } elseif ($regularIds->isNotEmpty()) {
                    $query->where(function (Builder $regular) use ($regularIds) {
                        $regular->where('is_shown', 1)
                            ->whereIn('id', $regularIds);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($dailyIds->isNotEmpty()) {
                    $query->orWhereIn('id', $dailyIds);
                }
            })
            ->orderByRaw("CASE WHEN type IN (?, ?) THEN 0 ELSE 1 END", $this->dailyTypes())
            ->orderBy('name')
            ->get();
    }

    public function canUse(User $user, int $boxId): bool
    {
        return $this->availableBoxes($user)->contains('id', $boxId);
    }

    /** @return Collection<int, int> */
    public function openDailyBoxIds(): Collection
    {
        $maintenanceIds = MaintenanceDailySession::query()
            ->where('status', config('maintenance_daily.session_status.open', 'open'))
            ->whereNotNull('box_id')
            ->pluck('box_id');

        $salesSessions = SalesDailySession::query()
            ->where('status', config('sales_daily.session_status.open', 'open'))
            ->get(['user_id', 'employee_id']);

        $salesIds = $salesSessions->isEmpty() ? collect() : Box::query()
            ->where('type', config('sales_daily.box_type', 'daily_sales'))
            ->where(function (Builder $query) use ($salesSessions) {
                foreach ($salesSessions as $session) {
                    $query->orWhere(function (Builder $owner) use ($session) {
                        if ($session->employee_id) {
                            $owner->where('employee_id', $session->employee_id);
                        } else {
                            $owner->where('user_id', $session->user_id)
                                ->whereNull('employee_id');
                        }
                    });
                }
            })
            ->pluck('id');

        return $maintenanceIds->merge($salesIds)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** @return Collection<int, int>|null Null means unrestricted admin access. */
    private function regularBoxIds(User $user): ?Collection
    {
        if ($user->type === 'admin') {
            return null;
        }

        if (! Schema::hasTable('employee_visible_boxes') || ! $user->employee) {
            return collect();
        }

        return $user->employee->visibleBoxes()
            ->where(function (Builder $query) {
                $query->whereNull('boxes.type')
                    ->orWhereNotIn('boxes.type', $this->dailyTypes());
            })
            ->pluck('boxes.id')
            ->map(fn ($id) => (int) $id);
    }

    /** @return array<int, string> */
    private function dailyTypes(): array
    {
        return [
            config('sales_daily.box_type', 'daily_sales'),
            config('maintenance_daily.box_type', 'daily_maintenance'),
        ];
    }
}
