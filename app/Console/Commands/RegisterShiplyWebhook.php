<?php

namespace App\Console\Commands;

use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Console\Command;

class RegisterShiplyWebhook extends Command
{
    protected $signature = 'shiply:register-webhook {--mode= : test or live; defaults to app setting}';

    protected $description = 'Register the application webhook URL with Shiply';

    public function handle(ShiplyService $shiply): int
    {
        $mode = $this->option('mode') ?: ShiplySettings::mode();
        $url = ShiplySettings::webhookUrl();

        $this->info("Registering webhook for {$mode}: {$url}");

        $ok = $shiply->registerWebhook($mode);
        if (! $ok) {
            $this->error('Registration failed.');

            return self::FAILURE;
        }

        $this->info('Webhook registered successfully.');

        return self::SUCCESS;
    }
}
