<?php

namespace App\Enums;

enum EmployeeTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case WaitingReview = 'waiting_review';
    case Completed = 'completed';
    case Overdue = 'overdue';
    case Canceled = 'canceled';

    /** Legacy value still present in older rows until migrated. */
    case Ongoing = 'ongoing';

    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::InProgress,
            self::WaitingReview,
            self::Overdue,
            self::Ongoing,
        ], true);
    }

    public function isArchived(): bool
    {
        return in_array($this, [self::Completed, self::Canceled], true);
    }

    /**
     * Normalize legacy status values to the new workflow.
     */
    public static function normalize(?string $status): self
    {
        return match ($status) {
            'pending' => self::Pending,
            'in_progress' => self::InProgress,
            'waiting_review' => self::WaitingReview,
            'completed' => self::Completed,
            'overdue' => self::Overdue,
            'canceled' => self::Canceled,
            'ongoing' => self::Pending,
            default => self::Pending,
        };
    }

    /**
     * Statuses included in the legacy "ongoing" API tab.
     */
    public static function ongoingTabValues(): array
    {
        return [
            self::Pending->value,
            self::InProgress->value,
            self::WaitingReview->value,
            self::Overdue->value,
            self::Ongoing->value,
        ];
    }
}
