<?php

namespace App\Services\SpecialTasks;

use App\Models\SpecialTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SpecialTaskRolloverService
{
    private const ROLLOVER_DAYS = 7;

    /**
     * Move incomplete expired special tasks forward by one week.
     */
    public function rolloverToNextWeek(?Carbon $asOf = null): int
    {
        $now = ($asOf ?? now())->copy()->timezone('Asia/Hebron');
        $count = 0;

        $tasks = SpecialTask::query()
            ->where('status', 'ongoing')
            ->where('is_canceled', 0)
            ->where('end_date', '<', $now)
            ->get();

        foreach ($tasks as $task) {
            $start = Carbon::parse($task->start_date)->timezone('Asia/Hebron');
            $end = Carbon::parse($task->end_date)->timezone('Asia/Hebron');

            $task->update([
                'start_date' => $start->copy()->addDays(self::ROLLOVER_DAYS)->format('Y-m-d H:i:s'),
                'end_date' => $end->copy()->addDays(self::ROLLOVER_DAYS)->format('Y-m-d H:i:s'),
            ]);

            $count++;
        }

        if ($count > 0) {
            Log::info("Rolled over {$count} incomplete special task(s) to the next week.");
        }

        return $count;
    }
}
