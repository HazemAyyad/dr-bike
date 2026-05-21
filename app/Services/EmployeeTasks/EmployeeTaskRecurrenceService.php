<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTemplate;
use App\Models\EmployeeTaskTimeline;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeTaskRecurrenceService
{
    private const HORIZON_DAYS = 14;

    public function __construct(
        private readonly EmployeeTaskTimelineService $timeline
    ) {}

    /**
     * Ensure occurrences exist for a template within the lazy generation window.
     */
    public function ensureOccurrences(EmployeeTaskTemplate $template, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        if ($template->recurrence_type === 'noRepeat') {
            return $this->ensureSingleOccurrence($template);
        }

        $from = ($from ?? now())->startOfDay();
        $to = ($to ?? $from->copy()->addDays(self::HORIZON_DAYS))->endOfDay();

        $dates = $this->resolveDatesInRange($template, $from, $to);
        $created = collect();

        foreach ($dates as $date) {
            $occurrence = $this->findOrCreateOccurrenceForDate($template, $date);
            if ($occurrence) {
                $created->push($occurrence);
            }
        }

        return $created;
    }

    public function ensureSingleOccurrence(EmployeeTaskTemplate $template): Collection
    {
        $config = $template->recurrence_config ?? [];
        $start = isset($config['start_time'])
            ? Carbon::parse($config['start_time'])
            : now();
        $end = isset($config['end_time'])
            ? Carbon::parse($config['end_time'])
            : $start->copy()->addHours(1);

        $occurrence = EmployeeTaskOccurrence::firstOrCreate(
            [
                'template_id' => $template->id,
                'scheduled_date' => $start->toDateString(),
            ],
            $this->occurrencePayloadFromTemplate($template, $start, $end)
        );

        if ($occurrence->wasRecentlyCreated) {
            $this->copySubtasksFromTemplate($template, $occurrence);
            $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_CREATED);
        }

        return collect([$occurrence]);
    }

    private function findOrCreateOccurrenceForDate(EmployeeTaskTemplate $template, Carbon $date): ?EmployeeTaskOccurrence
    {
        if (! $this->isWithinDuration($template, $date)) {
            return null;
        }

        $existing = EmployeeTaskOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('scheduled_date', $date->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        [$start, $end] = $this->windowForDate($template, $date);

        $occurrence = EmployeeTaskOccurrence::create(
            $this->occurrencePayloadFromTemplate($template, $start, $end, $date)
        );

        $this->copySubtasksFromTemplate($template, $occurrence);
        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_CREATED);

        return $occurrence;
    }

    private function occurrencePayloadFromTemplate(
        EmployeeTaskTemplate $template,
        Carbon $start,
        Carbon $end,
        ?Carbon $scheduledDate = null
    ): array {
        return [
            'template_id' => $template->id,
            'employee_id' => $template->employee_id,
            'name' => $template->name,
            'description' => $template->description,
            'notes' => $template->notes,
            'points' => $template->points,
            'priority' => $template->priority,
            'status' => EmployeeTaskStatus::Pending->value,
            'is_canceled' => false,
            'start_time' => $start,
            'end_time' => $end,
            'scheduled_date' => ($scheduledDate ?? $start)->toDateString(),
            'admin_img' => $template->admin_img,
            'audio' => $template->audio,
            'is_forced_to_upload_img' => $template->is_forced_to_upload_img,
            'not_shown_for_employee' => $template->not_shown_for_employee,
            'requires_admin_review' => $template->requires_admin_review ?? true,
        ];
    }

    private function copySubtasksFromTemplate(EmployeeTaskTemplate $template, EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->subtasks()->exists()) {
            return;
        }

        foreach ($template->subtasks as $sub) {
            EmployeeTaskOccurrenceSubtask::create([
                'occurrence_id' => $occurrence->id,
                'template_subtask_id' => $sub->id,
                'name' => $sub->name,
                'description' => $sub->description,
                'sort_order' => $sub->sort_order,
                'requires_image' => $sub->requires_image,
                'bonus_points' => $sub->bonus_points,
                'status' => 'pending',
                'admin_img' => $sub->admin_img,
            ]);
        }
    }

    private function windowForDate(EmployeeTaskTemplate $template, Carbon $date): array
    {
        $config = $template->recurrence_config ?? [];
        $startTime = $config['start_time'] ?? $date->format('Y-m-d').' 09:00:00';
        $endTime = $config['end_time'] ?? $date->format('Y-m-d').' 17:00:00';

        $start = Carbon::parse($date->format('Y-m-d').' '.Carbon::parse($startTime)->format('H:i:s'));
        $end = Carbon::parse($date->format('Y-m-d').' '.Carbon::parse($endTime)->format('H:i:s'));

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    private function resolveDatesInRange(EmployeeTaskTemplate $template, Carbon $from, Carbon $to): array
    {
        $config = $template->recurrence_config ?? [];
        $dates = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            if ($this->matchesRecurrence($template, $cursor)) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function matchesRecurrence(EmployeeTaskTemplate $template, Carbon $date): bool
    {
        $type = $template->recurrence_type;
        $config = $template->recurrence_config ?? [];

        return match ($type) {
            'daily' => $this->matchesDailyInterval($template, $date, $config),
            'weekly' => $this->matchesWeekly($date, $config),
            'monthly' => $this->matchesMonthly($date, $config),
            'yearly' => $this->matchesYearly($date, $config),
            default => false,
        };
    }

    private function matchesDailyInterval(EmployeeTaskTemplate $template, Carbon $date, array $config): bool
    {
        $anchor = isset($config['anchor_date'])
            ? Carbon::parse($config['anchor_date'])->startOfDay()
            : $template->created_at?->startOfDay() ?? now()->startOfDay();
        $interval = max(1, (int) ($config['interval'] ?? 1));
        $diff = $anchor->diffInDays($date->copy()->startOfDay());

        return $diff % $interval === 0;
    }

    private function matchesWeekly(Carbon $date, array $config): bool
    {
        $weekdays = array_map('strtolower', $config['weekdays'] ?? []);
        if (empty($weekdays)) {
            return false;
        }

        return in_array(strtolower($date->format('l')), $weekdays, true);
    }

    private function matchesMonthly(Carbon $date, array $config): bool
    {
        $mode = $config['monthly_mode'] ?? 'dates';

        if ($mode === 'dates') {
            $days = array_map('intval', $config['month_days'] ?? []);

            return in_array((int) $date->format('d'), $days, true);
        }

        $ordinal = $config['weekday_ordinal'] ?? 'first';
        $weekday = strtolower($config['weekday'] ?? 'monday');

        return $this->isNthWeekdayOfMonth($date, $ordinal, $weekday);
    }

    private function matchesYearly(Carbon $date, array $config): bool
    {
        $months = array_map('intval', $config['months'] ?? [(int) now()->format('n')]);
        $days = array_map('intval', $config['month_days'] ?? [(int) now()->format('j')]);

        return in_array((int) $date->format('n'), $months, true)
            && in_array((int) $date->format('j'), $days, true);
    }

    private function isNthWeekdayOfMonth(Carbon $date, string $ordinal, string $weekday): bool
    {
        if (strtolower($date->format('l')) !== $weekday) {
            return false;
        }

        $dayOfMonth = (int) $date->format('j');
        $weekOfMonth = (int) ceil($dayOfMonth / 7);
        $lastWeek = (int) ceil($date->copy()->endOfMonth()->format('j') / 7);

        return match ($ordinal) {
            'first' => $weekOfMonth === 1,
            'second' => $weekOfMonth === 2,
            'third' => $weekOfMonth === 3,
            'fourth' => $weekOfMonth === 4,
            'last' => $weekOfMonth === $lastWeek,
            default => false,
        };
    }

    private function isWithinDuration(EmployeeTaskTemplate $template, Carbon $date): bool
    {
        $config = $template->recurrence_config ?? [];
        $durationType = $config['duration_type'] ?? 'forever';

        if ($durationType === 'end_date' && ! empty($config['end_date'])) {
            return $date->lte(Carbon::parse($config['end_date'])->endOfDay());
        }

        if ($durationType === 'end_after_count') {
            $max = (int) ($config['end_after_count'] ?? 0);
            $count = EmployeeTaskOccurrence::where('template_id', $template->id)->count();
            if ($count >= $max && ! EmployeeTaskOccurrence::where('template_id', $template->id)
                ->whereDate('scheduled_date', $date->toDateString())->exists()) {
                return false;
            }
        }

        if (! empty($config['anchor_date']) && $date->lt(Carbon::parse($config['anchor_date'])->startOfDay())) {
            return false;
        }

        return true;
    }

    public function buildRecurrenceSummary(EmployeeTaskTemplate $template): string
    {
        $type = $template->recurrence_type;
        $config = $template->recurrence_config ?? [];

        if ($type === 'noRepeat') {
            return __('messages.recurrence_no_repeat');
        }

        $parts = [__('messages.recurrence_'.$type)];

        if ($type === 'weekly' && ! empty($config['weekdays'])) {
            $parts[] = implode(', ', $config['weekdays']);
        }

        $duration = $config['duration_type'] ?? 'forever';
        if ($duration === 'end_after_count') {
            $parts[] = __('messages.recurrence_end_after', ['count' => $config['end_after_count'] ?? 0]);
        } elseif ($duration === 'end_date' && ! empty($config['end_date'])) {
            $parts[] = __('messages.recurrence_end_at', ['date' => $config['end_date']]);
        }

        return implode(' · ', $parts);
    }
}
