<?php

namespace App\Services;

use App\Models\CronJobLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Throwable;

class CronJobRunner
{
    public function availableCommands(): array
    {
        return config('cron_jobs.commands', []);
    }

    public function isAllowed(string $signature): bool
    {
        return array_key_exists($signature, $this->availableCommands());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{command: string, exit_code: int, output: string, log: ?CronJobLog, success: bool}
     */
    public function run(string $signature, array $arguments = []): array
    {
        if (! $this->isAllowed($signature)) {
            throw new InvalidArgumentException("Command [{$signature}] is not allowed.");
        }

        $meta = $this->availableCommands()[$signature];
        $allowedArgs = array_keys($meta['arguments'] ?? []);
        $filtered = Arr::only($arguments, $allowedArgs);
        $filtered = array_filter($filtered, static fn ($v) => $v !== null && $v !== '');

        $logBeforeId = CronJobLog::query()->max('id') ?? 0;

        try {
            $exitCode = Artisan::call($signature, $filtered);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            $log = $this->resolveLog($signature, $logBeforeId);

            return [
                'command' => $signature,
                'exit_code' => 1,
                'output' => '',
                'log' => $log,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        $log = $this->resolveLog($signature, $logBeforeId);

        if ($log && $output !== '' && empty($log->output)) {
            $log->update(['output' => $output]);
            $log->refresh();
        }

        return [
            'command' => $signature,
            'exit_code' => (int) $exitCode,
            'output' => $output,
            'log' => $log,
            'success' => $exitCode === 0 && ($log === null || $log->status !== 'failed'),
        ];
    }

    private function resolveLog(string $signature, int $logBeforeId): ?CronJobLog
    {
        return CronJobLog::query()
            ->where('id', '>', $logBeforeId)
            ->where(function ($q) use ($signature) {
                $q->where('command_name', $signature)
                    ->orWhere('job_name', $signature);
            })
            ->orderByDesc('id')
            ->first()
            ?? CronJobLog::query()
                ->where(function ($q) use ($signature) {
                    $q->where('command_name', $signature)
                        ->orWhere('job_name', $signature);
                })
                ->orderByDesc('id')
                ->first();
    }
}
