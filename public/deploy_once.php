<?php

declare(strict_types=1);

/**
 * TEMPORARY DEPLOYMENT UTILITY SCRIPT
 * ===================================
 *
 * Intended only for development or emergency deployment when SSH is unavailable.
 * Must NEVER be executed in production. Requires both a valid token and a
 * non-production environment (see checks below). Do not expose this script
 * publicly; remove or restrict access after use.
 *
 * This file is TEMPORARY and should be deleted once normal deployment (e.g. SSH)
 * is available.
 *
 * On production this script exits early (environment=production). To create the
 * storage symlink there, use the web route GET /test/run-storage-link?token=...
 * (same token as DEPLOY_ONCE_TOKEN) or run: php artisan storage:link over SSH.
 */
$expectedToken = 'eshterelyDeploy2026SecureToken123';
$providedToken = $_GET['token'] ?? '';

if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Forbidden.</p></body></html>';
    exit;
}

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ($app->environment('production')) {
    http_response_code(403);
    exit('Deploy script disabled in production.');
}
if (! config('app.debug')) {
    http_response_code(403);
    exit('Deploy script disabled.');
}

// ترتيب النشر: config ثم storage:link قبل migrate — إذا فشل migrate يُرمى استثناء ويُوقف الحلقة؛
// وضع الرابط مبكراً يضمن إنشاء public/storage حتى عند فشل قاعدة البيانات.
$allowedCommands = [
    ['name' => 'config:clear', 'params' => []],
    [
        'name' => 'tinker',
        'params' => [
            '--execute' => 'dump(app(App\\Services\\FirebaseService::class)->credentialsDiagnostics());',
        ],
        'display' => 'tinker --execute="dump(app(App\\Services\\FirebaseService::class)->credentialsDiagnostics());"',
        'label' => '=== فحص اتصال Firebase Admin SDK ===',
    ],
    ['name' => 'storage:link', 'params' => []],
    [
        'name' => 'migrate',
        'params' => ['--force' => true],
        'label' => '=== تشغيل migrations (php artisan migrate --force) ===',
    ],
    [
        'name' => 'db:seed',
        'params' => [
            '--class' => 'WhatsAppTemplateSeeder',
            '--force' => true,
        ],
        'label' => '=== إضافة قوالب واتساب (php artisan db:seed --class=WhatsAppTemplateSeeder --force) ===',
    ],
    [
        'name' => 'db:seed',
        'params' => [
            '--class' => 'OnlineStoreHomeDemoSeeder',
            '--force' => true,
        ],
        'guard' => 'online_store_home_demo',
        'label' => '=== إضافة أقسام وبنرات المتجر الكهربائي الافتراضية (مرة واحدة) ===',
    ],
    [
        'name' => 'db:seed',
        'params' => [
            '--class' => 'OnlineStoreShowcaseSeeder',
            '--force' => true,
        ],
        'label' => '=== تجهيز تصنيفات وعرض الصفحة الرئيسية للمتجر بدون استبدال البيانات الحالية ===',
    ],
    [
        'name' => 'db:seed',
        'params' => [
            '--class' => 'OnlineStorePopupCampaignSeeder',
            '--force' => true,
        ],
        'label' => '=== تجهيز تصاميم الإعلانات المنبثقة للمتجر ===',
    ],
    [
        'name' => 'shiply:sync-addresses',
        'params' => ['--mode' => 'test', '--register-webhook' => true],
        'label' => '=== Shiply: مزامنة عناوين test + تسجيل webhook ===',
    ],
    [
        'name' => 'shiply:sync-addresses',
        'params' => ['--mode' => 'live', '--register-webhook' => true],
        'label' => '=== Shiply: مزامنة عناوين live + تسجيل webhook ===',
    ],
    ['name' => 'optimize:clear', 'params' => []],
    ['name' => 'cache:clear', 'params' => []],
    // Regenerate Composer autoload (e.g. after deploy) so classes like Kreait\Firebase\Factory are found
    ['name' => '__composer_dump_autoload__', 'params' => []],
    [
        'name' => '__reverb_restart__',
        'params' => [],
        'label' => '=== إعادة تشغيل Laravel Reverb في الخلفية ===',
    ],
];

$lines = [];
$lines[] = 'Deploy script started at '.date('Y-m-d H:i:s T');
$lines[] = '';

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Deploy output</title></head><body><pre>';

foreach ($allowedCommands as $cmd) {
    $commandName = $cmd['display']
        ?? ($cmd['name'].(isset($cmd['params']['--force']) ? ' --force' : ''));

    if (! empty($cmd['label'])) {
        echo htmlspecialchars($cmd['label']."\n", ENT_QUOTES, 'UTF-8');
    }

    if (($cmd['guard'] ?? null) === 'online_store_home_demo'
        && \Illuminate\Support\Facades\Schema::hasTable('online_store_home_sections')
        && \Illuminate\Support\Facades\Schema::hasTable('online_store_banners')
        && \Database\Seeders\OnlineStoreHomeDemoSeeder::exists()) {
        echo "   INFO  Online Store home demo data already exists, skipping.\n";
        echo "Exit code: 0\n";
        echo "----------------------------------------\n";

        continue;
    }

    if (str_starts_with($cmd['name'], 'shiply:')) {
        $mode = (string) ($cmd['params']['--mode'] ?? 'test');
        $apiKey = trim((string) config("shiply.api_keys.{$mode}", ''));
        if ($apiKey === '') {
            echo htmlspecialchars('>>> Skipping: SHIPLY_API_KEY_'.strtoupper($mode)." is not set in .env\n", ENT_QUOTES, 'UTF-8');
            echo "Exit code: 0\n";
            echo "----------------------------------------\n";

            continue;
        }
    }

    // Skip storage:link if the link already exists (avoids "link already exists" message)
    if ($cmd['name'] === 'storage:link') {
        $storageLinkPath = $app->basePath('public/storage');
        if (file_exists($storageLinkPath)) {
            echo htmlspecialchars(">>> Running: php artisan {$commandName}\n", ENT_QUOTES, 'UTF-8');
            echo "   INFO  Storage link already exists, skipping.\n";
            echo "Exit code: 0\n";
            echo "----------------------------------------\n";

            continue;
        }
    }

    // Run composer dump-autoload (not an Artisan command); skip if exec() is disabled (e.g. shared hosting)
    if ($cmd['name'] === '__composer_dump_autoload__') {
        echo ">>> Running: composer dump-autoload\n";
        if (! function_exists('exec')) {
            echo "   INFO  exec() is disabled on this server; skipped. Run 'composer dump-autoload' manually via SSH if needed.\n";
            echo "Exit code: 0\n";
        } else {
            $basePath = $app->basePath();
            $output = [];
            $exitCode = 0;
            $prevCwd = getcwd();
            @chdir($basePath);
            @exec('composer dump-autoload 2>&1', $output, $exitCode);
            @chdir($prevCwd);
            echo htmlspecialchars(implode("\n", $output), ENT_QUOTES, 'UTF-8');
            echo "\nExit code: {$exitCode}\n";
        }
        echo "----------------------------------------\n";

        continue;
    }

    // Reverb is a long-running process, so launch it in the background instead of
    // calling it through the console kernel and blocking this HTTP deploy request.
    if ($cmd['name'] === '__reverb_restart__') {
        echo ">>> Running: php artisan reverb:restart && reverb:start (background)\n";

        if (PHP_OS_FAMILY === 'Windows') {
            echo "   INFO  Background Reverb launch is only supported by this deploy script on Linux.\n";
            echo "Exit code: 0\n";
            echo "----------------------------------------\n";

            continue;
        }

        if (! function_exists('exec')) {
            echo "   INFO  exec() is disabled on this server; skipped. Start Reverb using Supervisor or SSH.\n";
            echo "Exit code: 0\n";
            echo "----------------------------------------\n";

            continue;
        }

        try {
            // Ask any existing Reverb instance to exit gracefully before starting
            // the replacement process, preventing duplicate listeners on the port.
            $kernel->call('reverb:restart');
            $restartOutput = trim($kernel->output());
            if ($restartOutput !== '') {
                echo htmlspecialchars($restartOutput."\n", ENT_QUOTES, 'UTF-8');
            }
            usleep(2_000_000);

            $basePath = $app->basePath();
            $host = (string) config('reverb.servers.reverb.host', '0.0.0.0');
            $port = (int) config('reverb.servers.reverb.port', 8080);
            $logPath = storage_path('logs/reverb.log');
            $pidPath = storage_path('app/reverb.pid');
            $command = sprintf(
                "nohup 'php' artisan reverb:start --host=%s --port=%d >> %s 2>&1 < /dev/null & echo $!",
                escapeshellarg($host),
                $port,
                escapeshellarg($logPath),
            );
            $output = [];
            $exitCode = 0;
            $prevCwd = getcwd();
            @chdir($basePath);
            @exec($command, $output, $exitCode);
            @chdir($prevCwd);

            $pid = trim((string) end($output));
            if ($exitCode !== 0 || ! ctype_digit($pid)) {
                throw new \RuntimeException('Reverb background process could not be started. Check exec/nohup permissions.');
            }

            file_put_contents($pidPath, $pid.PHP_EOL, LOCK_EX);
            echo htmlspecialchars("   INFO  Reverb started with PID {$pid}; log: {$logPath}\n", ENT_QUOTES, 'UTF-8');
            echo "Exit code: 0\n";
        } catch (\Throwable $e) {
            echo htmlspecialchars('ERROR: '.$e->getMessage()."\n", ENT_QUOTES, 'UTF-8');
            echo "Exit code: 1\n";
        }
        echo "----------------------------------------\n";

        continue;
    }

    echo htmlspecialchars(">>> Running: php artisan {$commandName}\n", ENT_QUOTES, 'UTF-8');

    try {
        $exitCode = $kernel->call($cmd['name'], $cmd['params']);
        $output = $kernel->output();

        echo htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        echo htmlspecialchars("\nExit code: {$exitCode}\n", ENT_QUOTES, 'UTF-8');
        echo "----------------------------------------\n";
    } catch (\Throwable $e) {
        echo htmlspecialchars('ERROR: '.$e->getMessage()."\n", ENT_QUOTES, 'UTF-8');
        echo htmlspecialchars('File: '.$e->getFile().':'.$e->getLine()."\n", ENT_QUOTES, 'UTF-8');
        break;
    }
}

echo htmlspecialchars("\nDone.\n", ENT_QUOTES, 'UTF-8');
echo '</pre></body></html>';
