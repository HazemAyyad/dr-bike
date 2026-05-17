<?php

namespace App\Services;

use App\Models\CronJobLog;
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
        $artisanArgs = $this->buildArtisanArguments($meta['arguments'] ?? [], $arguments);

        $logBeforeId = CronJobLog::query()->max('id') ?? 0;

        try {
            $exitCode = Artisan::call($signature, $artisanArgs);
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
        $log?->refresh();

        if ($log && $output !== '' && empty($log->output)) {
            $log->update(['output' => $output]);
            $log->refresh();
        }

        $report = $log?->payload['report'] ?? null;

        return [
            'command' => $signature,
            'exit_code' => (int) $exitCode,
            'output' => $output,
            'report' => is_array($report) ? $report : null,
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

    /**
     * @param  array<string, array<string, mixed>>  $argumentMeta
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildArtisanArguments(array $argumentMeta, array $input): array
    {
        $artisanArgs = [];

        foreach ($argumentMeta as $argName => $argMeta) {
            $option = $argMeta['option'] ?? '--'.str_replace('_', '-', $argName);
            $type = $argMeta['type'] ?? 'text';

            if ($type === 'checkbox') {
                $enabled = ! empty($input[$argName])
                    || ! empty($input[$option])
                    || ! empty($input[ltrim($option, '-')]);
                if ($enabled) {
                    $artisanArgs[$option] = true;
                }

                continue;
            }

            $value = $input[$argName] ?? $input[$option] ?? null;
            if ($value !== null && $value !== '') {
                $artisanArgs[$option] = $value;
            }
        }

        return $artisanArgs;
    }
}
