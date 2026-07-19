<?php

namespace App\Support;

use App\Models\EmployeeSuggestion;
use App\Models\Followup;
use App\Models\Maintenance;
use App\Models\SupportConversation;
use App\Models\SuspendedInstantSale;
use App\Models\User;

class DashboardSectionBadges
{
    public const SUPPORT_PERMISSION = 'Technical Support';

    /**
     * @return array<string, int>
     */
    public static function forUser(User $user): array
    {
        $employeeId = (int) ($user->employee?->id ?? 0);
        $canManageSupport = self::canManageSupport($user);

        $supportQuery = SupportConversation::query()
            ->where('status', '!=', SupportConversation::STATUS_CLOSED);
        if (! $canManageSupport && $employeeId > 0) {
            $supportQuery->where('employee_id', $employeeId);
        }

        $suggestionsQuery = EmployeeSuggestion::query()
            ->where('status', '!=', EmployeeSuggestion::STATUS_CLOSED);
        if ($user->type !== 'admin' && $employeeId > 0) {
            $suggestionsQuery->where('employee_id', $employeeId);
        }

        $salesQuery = SuspendedInstantSale::query()
            ->where('status', SuspendedInstantSale::STATUS_SUSPENDED);
        if ($user->type !== 'admin') {
            $salesQuery->where('created_by_user_id', $user->id);
        }

        return [
            'technical_support' => (int) $supportQuery->count(),
            'maintenance' => (int) Maintenance::query()->where('status', '!=', 'delivered')->count(),
            'follow_up' => (int) Followup::query()
                ->where('status', 'ongoing')
                ->where(function ($query) {
                    $query->whereNull('is_canceled')->orWhere('is_canceled', 0);
                })
                ->count(),
            'sales' => (int) $salesQuery->count(),
            'suggestions' => (int) $suggestionsQuery->count(),
        ];
    }

    private static function canManageSupport(User $user): bool
    {
        if ($user->type === 'admin') {
            return true;
        }

        return (bool) $user->employee?->permissions()
            ->whereHas('permission', fn ($query) => $query->where('name_en', self::SUPPORT_PERMISSION))
            ->exists();
    }
}
