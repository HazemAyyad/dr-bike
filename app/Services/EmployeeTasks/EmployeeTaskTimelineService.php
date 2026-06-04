<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTimeline;
use Illuminate\Support\Facades\Auth;

class EmployeeTaskTimelineService
{
    public function recordForTask(
        EmployeeTask $task,
        string $eventType,
        ?string $notes = null,
        ?array $metadata = null
    ): EmployeeTaskTimeline {
        return EmployeeTaskTimeline::create([
            'employee_task_id' => $task->id,
            'occurrence_id' => $task->occurrence_id,
            'event_type' => $eventType,
            'actor_id' => Auth::id(),
            'actor_type' => Auth::check() ? 'user' : null,
            'notes' => $notes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function recordForOccurrence(
        EmployeeTaskOccurrence $occurrence,
        string $eventType,
        ?string $notes = null,
        ?array $metadata = null
    ): EmployeeTaskTimeline {
        return EmployeeTaskTimeline::create([
            'employee_task_id' => $occurrence->legacy_task_id,
            'occurrence_id' => $occurrence->id,
            'event_type' => $eventType,
            'actor_id' => Auth::id(),
            'actor_type' => Auth::check() ? 'user' : null,
            'notes' => $notes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listForOccurrence(int $occurrenceId): array
    {
        return EmployeeTaskTimeline::query()
            ->where('occurrence_id', $occurrenceId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($e) => $this->mapTimelineRow($e))
            ->all();
    }

    /**
     * Timeline rows for a legacy task (skips orphan events after employee_tasks.id reuse).
     */
    public function listForLegacyTask(EmployeeTask $task): array
    {
        $query = EmployeeTaskTimeline::query()
            ->where('employee_task_id', $task->id)
            ->whereNull('occurrence_id')
            ->orderBy('created_at');

        if ($task->created_at) {
            $query->where('created_at', '>=', $task->created_at);
        }

        return $query->get()->map(fn ($e) => $this->mapTimelineRow($e))->all();
    }

    public function listCombined(?int $taskId, ?int $occurrenceId): array
    {
        $query = EmployeeTaskTimeline::query()->orderBy('created_at');

        if ($occurrenceId) {
            $query->where(function ($q) use ($taskId, $occurrenceId) {
                $q->where('occurrence_id', $occurrenceId);
                if ($taskId) {
                    $q->orWhere('employee_task_id', $taskId);
                }
            });
        } elseif ($taskId) {
            $query->where('employee_task_id', $taskId);
        } else {
            return [];
        }

        return $query->get()->map(fn ($e) => $this->mapTimelineRow($e))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTimelineRow(EmployeeTaskTimeline $e): array
    {
        return [
            'event_type' => $e->event_type,
            'notes' => $e->notes,
            'metadata' => $e->metadata,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
