<?php

namespace App\Console\Commands;

use App\Models\OnlineStore\OnlineStoreLegacyCheckoutAttempt;
use Illuminate\Console\Command;

class PurgeExpiredOnlineStoreCheckoutAttempts extends Command
{
    protected $signature = 'online-store:purge-checkout-attempts';

    protected $description = 'Delete expired legacy checkout dedupe markers without deleting SalesOrders';

    public function handle(): int
    {
        $count = OnlineStoreLegacyCheckoutAttempt::query()->expired()->delete();
        $this->info("Purged {$count} expired checkout attempt rows.");

        return self::SUCCESS;
    }
}
