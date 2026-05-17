<?php

namespace App\Http\Controllers;

use App\Models\CronJobLog;
use App\Services\CronJobRunner;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * صفحة ويب لتشغيل أوامر الكرون يدوياً وعرض السجلات — للتطوير/الإدارة المحلية.
 */
class CronJobWebController extends Controller
{
    private function ensureEnabled(): void
    {
        if (! config('cron_jobs.web_enabled') && ! config('app.debug')) {
            abort(403, 'Cron web manager is disabled.');
        }
    }

    public function index()
    {
        $this->ensureEnabled();

        $commands = config('cron_jobs.commands', []);
        $recentLogs = CronJobLog::query()
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('cron-jobs-manager', [
            'commands' => $commands,
            'recentLogs' => $recentLogs,
            'runResult' => session('run_result'),
            'selectedLog' => session('selected_log_id')
                ? CronJobLog::find(session('selected_log_id'))
                : null,
        ]);
    }

    public function run(Request $request, CronJobRunner $runner)
    {
        $this->ensureEnabled();

        $allowed = array_keys(config('cron_jobs.commands', []));
        $validated = $request->validate([
            'command' => ['required', 'string', 'in:'.implode(',', $allowed)],
            'token' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = config('cron_jobs.commands')[$validated['command']] ?? [];
        $arguments = [];
        foreach ($meta['arguments'] ?? [] as $argName => $argMeta) {
            if (($argMeta['type'] ?? 'text') === 'checkbox') {
                if ($request->boolean($argName)) {
                    $option = $argMeta['option'] ?? '--'.str_replace('_', '-', $argName);
                    $arguments[$option] = true;
                }
            } elseif ($request->filled($argName)) {
                $arguments[$argName] = $request->input($argName);
            }
        }

        try {
            $result = $runner->run($validated['command'], $arguments);
            $logId = $result['log']?->id;
            unset($result['log']);
            $result['log_id'] = $logId;
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('test.cron-jobs')
                ->with('run_result', [
                    'success' => false,
                    'command' => $validated['command'],
                    'error' => $e->getMessage(),
                    'output' => '',
                    'exit_code' => 1,
                ]);
        }

        return redirect()
            ->route('test.cron-jobs')
            ->with('run_result', $result)
            ->with('selected_log_id', $logId);
    }

    public function showLog(int $id)
    {
        $this->ensureEnabled();

        $log = CronJobLog::query()->findOrFail($id);

        return redirect()
            ->route('test.cron-jobs')
            ->with('selected_log_id', $log->id);
    }
}
