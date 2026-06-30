<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $phone,
        public string $type,
        public array $payload,
        public ?int $adminId = null
    ) {}

    public function handle(WhatsAppCloudApiService $service): void
    {
        if ($this->type === 'template') {
            $service->sendTemplate(
                $this->phone,
                $this->payload['template_name'],
                $this->payload['language'] ?? 'ar',
                $this->payload['components'] ?? [],
                $this->adminId
            );
            return;
        }
        $service->sendText($this->phone, $this->payload['message'], $this->adminId);
    }
}
