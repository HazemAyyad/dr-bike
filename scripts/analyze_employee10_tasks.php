<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\EmployeeTask;
use App\Support\EmployeeVisibleTasks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

$eid = (int) ($argv[1] ?? 10);
$today = EmployeeVisibleTasks::todayDateString();

echo "employee_id={$eid}\n";
echo 'assignees_table=' . (Schema::hasTable('employee_task_assignees') ? 'yes' : 'no') . "\n";
echo "today={$today}\n\n";

$query = EmployeeTask::query()
    ->where('is_canceled', 0)
    ->whereNull('occurrence_id')
    ->where(function ($q) use ($eid) {
        $q->where('employee_id', $eid);
        if (Schema::hasTable('employee_task_assignees')) {
            $q->orWhereIn('id', function ($sub) use ($eid) {
                $sub->select('employee_task_id')
                    ->from('employee_task_assignees')
                    ->where('employee_id', $eid);
            });
        }
    });

$dbRows = $query->orderBy('start_time')->get();
echo '=== DB rows linked to employee (employee_id OR assignee) ===' . "\n";
echo 'count=' . $dbRows->count() . "\n";
foreach ($dbRows->take(25) as $t) {
    echo sprintf(
        "%d|%s|parent=%s|emp=%s|start=%s|rec=%s|status=%s\n",
        $t->id,
        $t->name,
        $t->parent_id ?? '-',
        $t->employee_id,
        $t->start_time,
        $t->task_recurrence ?? '-',
        $t->status
    );
}
if ($dbRows->count() > 25) {
    echo '... and ' . ($dbRows->count() - 25) . " more\n";
}

if (Schema::hasTable('employee_task_assignees')) {
    $assigneeRows = \Illuminate\Support\Facades\DB::table('employee_task_assignees')
        ->where('employee_id', $eid)
        ->pluck('employee_task_id')
        ->all();
    echo "\n=== assignee task ids for employee {$eid} ===\n";
    echo implode(',', array_slice($assigneeRows, 0, 30)) . (count($assigneeRows) > 30 ? '...' : '') . "\n";
    echo 'count=' . count($assigneeRows) . "\n";
}

$legacy = EmployeeVisibleTasks::legacyForEmployee($eid);
echo "\n=== API legacyForEmployee (after filters) ===\n";
echo 'count=' . $legacy->count() . "\n";
foreach ($legacy->take(20) as $t) {
    echo sprintf(
        "%d|%s|parent=%s|start=%s|rec=%s|status=%s\n",
        $t->id,
        $t->name,
        $t->parent_id ?? '-',
        $t->start_time,
        $t->task_recurrence ?? '-',
        $t->status
    );
}

$payload = EmployeeVisibleTasks::dashboardPayload($eid);
echo "\n=== dashboardPayload (employee home API) ===\n";
echo 'count=' . $payload->count() . "\n";

$todayCarbon = Carbon::parse($today)->timezone(EmployeeVisibleTasks::TIMEZONE)->startOfDay();
$todayTasks = $payload->filter(fn ($r) => EmployeeVisibleTasks::taskAppliesOnDate($r, $todayCarbon));
echo "\n=== applies TODAY ({$today}) ===\n";
echo 'count=' . $todayTasks->count() . "\n";
foreach ($todayTasks as $r) {
    echo json_encode([
        'id' => $r['id'],
        'task_id' => $r['task_id'],
        'name' => $r['name'],
        'start' => $r['start_time'],
        'parent_id' => $r['parent_id'] ?? null,
        'recurrence' => $r['task_recurrence'] ?? null,
        'status' => $r['status'],
    ], JSON_UNESCAPED_UNICODE) . "\n";
}

// Week range Sat-Fri style (approx current week around today)
$weekStart = $todayCarbon->copy();
$daysToSubtract = ($weekStart->dayOfWeek >= 6) ? $weekStart->dayOfWeek - 6 : $weekStart->dayOfWeek + 1;
$weekStart = $weekStart->subDays($daysToSubtract);
$weekEnd = $weekStart->copy()->addDays(6);
echo "\n=== week {$weekStart->toDateString()} to {$weekEnd->toDateString()} (employee logical days) ===\n";
for ($d = $weekStart->copy(); $d->lte($weekEnd); $d->addDay()) {
    $dayTasks = $payload->filter(fn ($r) => EmployeeVisibleTasks::taskAppliesOnDate($r, $d));
    if ($dayTasks->isEmpty()) {
        continue;
    }
    echo $d->toDateString() . ': ' . $dayTasks->count() . " task(s)\n";
    foreach ($dayTasks as $r) {
        echo '  - id=' . $r['id'] . ' name=' . $r['name'] . ' parent=' . ($r['parent_id'] ?? '-') . "\n";
    }
}

$listService = app(\App\Services\EmployeeTasks\EmployeeTaskListService::class);
$adminOngoing = $listService->getOngoingItems(fn ($e) => 'photo');
$adminFor10 = $adminOngoing->filter(fn ($t) => (int) ($t['employee_id'] ?? 0) === $eid);
echo "\n=== Admin ongoing API rows where employee_id=10 (raw, no expander) ===\n";
echo 'count=' . $adminFor10->count() . "\n";
foreach ($adminFor10->take(15) as $t) {
    echo sprintf(
        "task_id=%s|parent=%s|start=%s|name=%s\n",
        $t['task_id'],
        $t['parent_id'] ?? '-',
        $t['start_time'],
        $t['task_name']
    );
}

echo "\n=== assignees on parent 7650 ===\n";
if (Schema::hasTable('employee_task_assignees')) {
    $a7650 = \Illuminate\Support\Facades\DB::table('employee_task_assignees')
        ->where('employee_task_id', 7650)
        ->pluck('employee_id')
        ->all();
    echo 'employee_ids: ' . implode(',', $a7650) . "\n";
}

foreach ([66, 67, 331] as $tid) {
    $t = EmployeeTask::find($tid);
    if (! $t) {
        echo "\nTask {$tid}: NOT FOUND\n";
        continue;
    }
    echo "\n=== task {$tid} ===\n";
    echo "name={$t->name}|emp={$t->employee_id}|parent={$t->parent_id}|start={$t->start_time}|rec={$t->task_recurrence}|status={$t->status}\n";
    if (Schema::hasTable('employee_task_assignees')) {
        $as = \Illuminate\Support\Facades\DB::table('employee_task_assignees')
            ->where('employee_task_id', $tid)
            ->pluck('employee_id')
            ->all();
        echo 'assignees: ' . implode(',', $as) . "\n";
    }
}

// Simulate employee Flutter expander logic (simplified PHP)
echo "\n=== SIMULATED employee UI today after expander ===\n";
$rawTasks = $payload->map(function ($r) {
    return (object) [
        'id' => (int) $r['id'],
        'taskId' => (int) ($r['task_id'] ?? $r['id']),
        'name' => $r['name'],
        'start' => $r['start_time'],
        'parentId' => $r['parent_id'] ?? null,
        'recurrence' => $r['task_recurrence'] ?? 'noRepeat',
        'status' => $r['status'],
    ];
})->all();

$todayDt = Carbon::parse($today)->startOfDay();
$visibleToday = [];
foreach ($rawTasks as $task) {
    $start = Carbon::parse($task->start)->startOfDay();
    if ($start->equalTo($todayDt)) {
        $visibleToday[] = ['id' => $task->id, 'name' => $task->name, 'source' => 'direct_start'];
        continue;
    }
    $isChild = ! empty($task->parentId);
    $isRecurringParent = empty($task->parentId) && ($task->recurrence !== 'noRepeat' && $task->recurrence !== '');
    if ($isChild) {
        continue;
    }
    if ($isRecurringParent) {
        $anchor = Carbon::parse($task->start)->startOfDay();
        if ($todayDt->lt($anchor)) {
            continue;
        }
        if ($todayDt->lte(Carbon::parse($task->start)->startOfDay())) {
            // simplified daily match
        }
        // daily recurring from anchor
        if ($task->recurrence === 'daily' && $todayDt->gte($anchor)) {
            $visibleToday[] = ['id' => $task->id, 'name' => $task->name, 'source' => 'virtual_daily_parent'];
        }
    }
}
// Also check: pending children exist in DB for today but hidden from API
$childToday = EmployeeTask::query()
    ->where('employee_id', $eid)
    ->where('parent_id', 7650)
    ->whereDate('start_time', $today)
    ->first();
if ($childToday) {
    echo "DB child for today exists id={$childToday->id} but excluded from employee API payload (pending child filter)\n";
}

foreach ($visibleToday as $v) {
    echo json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== SIMULATED admin UI this week (employee_id=10 rows + expander concept) ===\n";
$weekDays = [];
for ($d = $weekStart->copy(); $d->lte($weekEnd); $d->addDay()) {
    $key = $d->toDateString();
    $weekDays[$key] = [];
}
foreach ($adminFor10 as $t) {
    $start = Carbon::parse($t['start_time'])->startOfDay();
    $key = $start->toDateString();
    if (isset($weekDays[$key])) {
        $weekDays[$key][] = 'DB#'.$t['task_id'].(empty($t['parent_id']) ? '' : '(copy)');
    }
}
// parent 7650 virtual days in week before children check
$parent7650 = $adminFor10->firstWhere('task_id', 7650);
if ($parent7650) {
    for ($d = $weekStart->copy(); $d->lte($weekEnd); $d->addDay()) {
        if ($d->lt(Carbon::parse($parent7650['start_time'])->startOfDay())) {
            continue;
        }
        $key = $d->toDateString();
        $hasChild = $adminFor10->contains(fn ($x) => ($x['parent_id'] ?? null) == 7650 && Carbon::parse($x['start_time'])->toDateString() === $key);
        if (! $hasChild && isset($weekDays[$key])) {
            $weekDays[$key][] = 'VIRTUAL#7650';
        }
    }
}
foreach ($weekDays as $day => $items) {
    if ($items === []) {
        continue;
    }
    echo $day . ': ' . implode(', ', array_unique($items)) . "\n";
}
