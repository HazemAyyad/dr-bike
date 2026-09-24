<?php

namespace App\Services;

use App\Exceptions\BoxAccessDeniedException;
use App\Models\Box;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class BoxAccessService
{
    /** @return array<int, int>|null Null means unrestricted administrator access. */
    public function visibleBoxIds(?User $user): ?array
    {
        if ($user?->type === 'admin') {
            return null;
        }
        if (! $user || $user->type !== 'employee' || ! Schema::hasTable('employee_visible_boxes')) {
            return [];
        }

        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        return $employee->visibleBoxes()
            ->pluck('boxes.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccess(?User $user, int $boxId): bool
    {
        $visibleIds = $this->visibleBoxIds($user);

        return $visibleIds === null
            ? Box::query()->whereKey($boxId)->exists()
            : in_array($boxId, $visibleIds, true);
    }

    public function findAccessible(?User $user, int $boxId, bool $lockForUpdate = false): Box
    {
        $visibleIds = $this->visibleBoxIds($user);
        $query = Box::query()->whereKey($boxId);
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $box = $query->first();
        if (! $box) {
            throw new BoxAccessDeniedException;
        }

        return $box;
    }

    public function scopeAccessible(Builder $query, ?User $user): Builder
    {
        $visibleIds = $this->visibleBoxIds($user);

        return $visibleIds === null ? $query : $query->whereIn('id', $visibleIds);
    }
}
