<?php

namespace App\Services;

use App\Models\CronJobLog;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\App;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class CronJobLogger
{
    public function start(string $jobName, ?string $commandName = null, ?array $payload = null): CronJobLog
    {
        return CronJobLog::create([
            'job_name' => $jobName,
            'command_name' => $commandName ?? $jobName,
            'status' => 'running',
            'started_at' => now(),
            'payload' => $payload,
        ]);
    }

    public function finishSuccess(CronJobLog $log, ?string $output = null): void
    {
        $finishedAt = now();
        $log->update([
            'status' => 'success',
            'finished_at' => $finishedAt,
            'duration_seconds' => max(0, $finishedAt->diffInSeconds($log->started_at)),
            'output' => $output,
            'error_message' => null,
        ]);
    }

    public function finishFailed(CronJobLog $log, Throwable|string $error, ?string $output = null): void
    {
        $finishedAt = now();
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;

        $log->update([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'duration_seconds' => max(0, $finishedAt->diffInSeconds($log->started_at)),
            'output' => $output,
            'error_message' => $message,
        ]);
    }

    /**
     * Run a scheduled/console job and persist start/finish logs.
     *
     * @return mixed Return value from $callback (e.g. command exit code).
     */
    public function run(string $jobName, callable $callback, ?string $commandName = null, ?array $payload = null): mixed
    {
        $log = $this->start($jobName, $commandName, $payload);
        $buffer = new BufferedOutput();
        $previousLocale = App::getLocale();
        App::setLocale('ar');

        try {
            $result = (new \ReflectionFunction($callback))->getNumberOfParameters() >= 2
                ? $callback($buffer, $log)
                : $callback($buffer);
            $this->finishSuccess($log, trim($buffer->fetch()) ?: null);

            return $result;
        } catch (Throwable $e) {
            $output = trim($buffer->fetch());
            $this->finishFailed($log, $e, $output !== '' ? $output : null);
            throw $e;
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function captureOutput(?OutputStyle $output): ?string
    {
        if ($output === null) {
            return null;
        }

        if (method_exists($output, 'fetch')) {
            return trim($output->fetch()) ?: null;
        }

        return null;
    }
}
