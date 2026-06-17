<?php

namespace App\Console\Commands;

use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Console\Command;

class SyncShiplyAddresses extends Command
{
    protected $signature = 'shiply:sync-addresses
                            {--mode= : test or live; defaults to app setting}
                            {--register-webhook : Register webhook URL after sync}';

    protected $description = 'Sync Shiply cities and villages into local cache tables';

    public function handle(ShiplyService $shiply): int
    {
        $mode = $this->option('mode') ?: ShiplySettings::mode();
        if (! in_array($mode, [ShiplySettings::MODE_TEST, ShiplySettings::MODE_LIVE], true)) {
            $this->error('Invalid --mode. Use test or live.');

            return self::FAILURE;
        }

        $this->info("Syncing Shiply addresses for mode: {$mode}");

        try {
            $stats = $shiply->syncAddresses($mode);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Cities: {$stats['synced_cities']}, Villages: {$stats['synced_villages']}");

        if ($this->option('register-webhook')) {
            $ok = $shiply->registerWebhook($mode);
            $this->line($ok ? 'Webhook URL registered.' : 'Webhook registration failed — check logs.');
        }

        return self::SUCCESS;
    }
}
