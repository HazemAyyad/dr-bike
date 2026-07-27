<?php

namespace App\Jobs;

use App\Models\StockImageExport;
use App\Services\StockImagesZipExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildStockImagesZipExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0;

    public function __construct(public int $exportId)
    {
        $this->afterCommit();
        $this->onQueue('stock-images');
    }

    public function handle(StockImagesZipExportService $service): void
    {
        $export = StockImageExport::find($this->exportId);
        if (! $export) {
            return;
        }

        $service->buildForExport($export);
    }
}
