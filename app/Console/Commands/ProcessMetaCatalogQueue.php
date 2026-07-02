<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessMetaCatalogQueue extends Command
{
    protected $signature = 'meta:catalog-queue-work';

    protected $description = 'Process a bounded batch of queued Meta Catalog jobs';

    public function handle(): int
    {
        $this->info('Processing up to 100 queued jobs...');

        return $this->call('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--max-jobs' => 100,
            '--max-time' => 240,
            '--tries' => 3,
            '--timeout' => 120,
        ]);
    }
}
