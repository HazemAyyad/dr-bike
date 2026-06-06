<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$eid = 10;

if (! Schema::hasTable('employee_task_occurrences')) {
    echo "no occurrences table\n";
    exit;
}

echo "=== occurrences for employee {$eid} ===\n";
$rows = DB::table('employee_task_occurrences as o')
    ->leftJoin('employee_task_templates as t', 't.id', '=', 'o.template_id')
    ->where('o.employee_id', $eid)
    ->where('o.is_canceled', 0)
    ->orderBy('o.scheduled_date')
    ->limit(30)
    ->get(['o.id', 'o.name', 'o.legacy_task_id', 'o.template_id', 'o.scheduled_date', 'o.start_time', 'o.status', 't.name as template_name', 't.recurrence_type']);

foreach ($rows as $r) {
    echo "occ_id={$r->id}|name={$r->name}|legacy={$r->legacy_task_id}|tpl={$r->template_id}|sched={$r->scheduled_date}|status={$r->status}|tpl_name={$r->template_name}|rec={$r->recurrence_type}\n";
}

echo "\n=== occurrences around 2026-06-06 week ===\n";
$week = DB::table('employee_task_occurrences as o')
    ->leftJoin('employee_task_templates as t', 't.id', '=', 'o.template_id')
    ->where('o.employee_id', $eid)
    ->whereBetween('o.scheduled_date', ['2026-06-05', '2026-06-12'])
    ->orderBy('o.scheduled_date')
    ->get(['o.id', 'o.name', 'o.legacy_task_id', 'o.scheduled_date', 'o.status', 't.recurrence_type']);

foreach ($week as $r) {
    echo "occ_id={$r->id}|name={$r->name}|legacy={$r->legacy_task_id}|sched={$r->scheduled_date}|status={$r->status}\n";
}

echo "\n=== occurrence ids 66,67 in occurrences table ===\n";
foreach ([66, 67] as $oid) {
    $o = DB::table('employee_task_occurrences')->where('id', $oid)->first();
    if ($o) {
        echo "occ {$oid}: name={$o->name} emp={$o->employee_id} sched={$o->scheduled_date} legacy={$o->legacy_task_id} tpl={$o->template_id}\n";
    } else {
        echo "occ {$oid}: not found\n";
    }
}

echo "\n=== templates linked to employee 10 ===\n";
if (Schema::hasTable('employee_task_templates')) {
    $tpl = DB::table('employee_task_templates')->where('employee_id', $eid)->get(['id', 'name', 'recurrence_type', 'employee_id']);
    foreach ($tpl as $t) {
        echo "tpl={$t->id}|{$t->name}|rec={$t->recurrence_type}|emp={$t->employee_id}\n";
    }
}
