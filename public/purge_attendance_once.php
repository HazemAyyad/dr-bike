<?php

declare(strict_types=1);

/**
 * ONE-TIME: purge all attendance data on the server (token + lock).
 *
 *   https://YOUR-DOMAIN/purge_attendance_once.php?token=TOKEN&confirm=yes
 *
 * DELETE this file from public/ after successful run.
 */
$confirm = strtolower(trim((string) ($_GET['confirm'] ?? '')));
$providedToken = (string) ($_GET['token'] ?? '');

header('Content-Type: text/plain; charset=UTF-8');

if (! in_array($confirm, ['yes', '1', 'true'], true)) {
    http_response_code(400);
    echo "Add &confirm=yes to execute.\n";
    echo "Example: purge_attendance_once.php?token=...&confirm=yes\n";

    exit;
}

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$expectedToken = (string) env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123');
if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Forbidden. Invalid or missing token.\n";

    exit;
}

$lockPath = storage_path('framework/attendance_purge_once.lock');

if (is_file($lockPath)) {
    echo "Already executed (lock file exists).\n";
    echo "Lock: {$lockPath}\n";
    echo file_get_contents($lockPath) ?: '';
    echo "\nDelete the lock file only if you need to run again.\n";

    exit;
}

$countsBefore = [];
foreach (['employee_attendances', 'employee_attendance_scans', 'fingerprint_raw_logs'] as $table) {
    try {
        $countsBefore[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    } catch (\Throwable $e) {
        $countsBefore[$table] = -1;
    }
}

echo "=== Purge attendance data (one-time) ===\n";
echo 'Started: '.now()->toDateTimeString().' ('.config('app.timezone').")\n\n";
echo "Before:\n";
foreach ($countsBefore as $table => $count) {
    echo "  {$table}: {$count}\n";
}
echo "\n";

try {
    $exitCode = $kernel->call('attendance:purge-all', ['--force' => true]);
    echo $kernel->output();
    echo "\nExit code: {$exitCode}\n";

    if ($exitCode !== 0) {
        http_response_code(500);
        echo "FAILED.\n";

        exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'ERROR: '.$e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine()."\n";

    exit;
}

$countsAfter = [];
foreach (array_keys($countsBefore) as $table) {
    try {
        $countsAfter[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    } catch (\Throwable $e) {
        $countsAfter[$table] = -1;
    }
}

echo "\nAfter:\n";
foreach ($countsAfter as $table => $count) {
    echo "  {$table}: {$count}\n";
}

$lockBody = json_encode([
    'executed_at' => now()->toIso8601String(),
    'timezone' => config('app.timezone'),
    'before' => $countsBefore,
    'after' => $countsAfter,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

file_put_contents($lockPath, $lockBody);

echo "\nOK. Lock written: {$lockPath}\n";
echo "Delete public/purge_attendance_once.php from the server after verifying.\n";
