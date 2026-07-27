<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessStockImagesExportQueue extends Command
{
    protected $signature = 'stock-images:queue-work';

    protected $description = 'Process one queued stock images ZIP export job';

    public function handle(): int
    {
        return $this->call('queue:work', [
            'connection' => 'database',
            '--queue' => 'stock-images',
            '--stop-when-empty' => true,
            '--max-jobs' => 1,
            '--max-time' => 7200,
            '--tries' => 1,
            '--timeout' => 0,
        ]);
    }
}
