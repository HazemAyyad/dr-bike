<?php

namespace App\Services\SpecialTasks;

use App\Models\SpecialTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SpecialTaskRolloverService
{
    /**
     * Move unfinished special tasks from the ending week to the no-date list.
     */
    public function moveEndingWeekToNoDate(?Carbon $asOf = null): int
    {
        $now = ($asOf ?? now())->copy()->timezone('Asia/Hebron');
        $weekStart = $now->copy()->previous(Carbon::SATURDAY)->startOfDay();
        $weekEnd = $now->copy()->previous(Carbon::FRIDAY)->endOfDay();
        $count = 0;

        $tasks = SpecialTask::query()
            ->where('status', 'ongoing')
            ->where('is_canceled', 0)
            ->whereNull('moved_to_no_date_at')
            ->whereBetween('start_date', [
                $weekStart->format('Y-m-d H:i:s'),
                $weekEnd->format('Y-m-d H:i:s'),
            ])
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'end_date' => $weekEnd->format('Y-m-d H:i:s'),
                'moved_to_no_date_at' => $now,
            ]);

            $count++;
        }

        if ($count > 0) {
            Log::info("Moved {$count} unfinished special task(s) to no-date tasks.");
        }

        return $count;
    }
}
