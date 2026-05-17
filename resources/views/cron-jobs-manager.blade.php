<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إدارة مهام الكرون (Scheduler)</title>
    <style>
        :root {
            --bg: #f1f5f9;
            --card: #fff;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --danger: #dc2626;
            --muted: #64748b;
            --log-bg: #0f172a;
            --log-fg: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 1.5rem 1rem 3rem;
            color: #0f172a;
        }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 0.5rem; }
        .subtitle { color: var(--muted); margin-bottom: 1.25rem; font-size: 0.95rem; }
        .warn {
            background: #fff7ed;
            border: 1px solid #fdba74;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .grid-2 { display: grid; gap: 1rem; }
        @media (min-width: 900px) {
            .grid-2 { grid-template-columns: 1fr 1fr; }
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.1rem 1.25rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            margin-bottom: 1rem;
        }
        .card h2 { font-size: 1rem; margin: 0 0 0.75rem; }
        .cmd-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.85rem;
        }
        .cmd-card:last-child { margin-bottom: 0; }
        .cmd-title { font-weight: 700; margin-bottom: 0.25rem; }
        .cmd-sig {
            font-family: ui-monospace, monospace;
            font-size: 0.82rem;
            color: var(--primary);
        }
        .cmd-desc { font-size: 0.88rem; color: var(--muted); margin: 0.4rem 0 0.6rem; }
        .badge {
            display: inline-block;
            font-size: 0.72rem;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
            background: #dbeafe;
            color: #1e40af;
            margin-inline-start: 0.35rem;
        }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
        input[type="text"] {
            width: 100%;
            padding: 0.45rem 0.6rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        button.run {
            margin-top: 0.65rem;
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
        }
        button.run:hover { background: var(--primary-hover); }
        .result { border-radius: 10px; padding: 1rem; margin-bottom: 1.5rem; }
        .result.ok { background: #f0fdf4; border: 1px solid #86efac; }
        .result.fail { background: #fef2f2; border: 1px solid #fca5a5; }
        .log-box {
            background: var(--log-bg);
            color: var(--log-fg);
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.82rem;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 320px;
            overflow: auto;
            margin-top: 0.5rem;
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th, td {
            padding: 0.5rem 0.4rem;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }
        th { font-weight: 600; color: var(--muted); font-size: 0.8rem; }
        .status-success { color: var(--success); font-weight: 600; }
        .status-failed { color: var(--danger); font-weight: 600; }
        .status-running { color: #ca8a04; font-weight: 600; }
        a.link { color: var(--primary); text-decoration: none; }
        a.link:hover { text-decoration: underline; }
        .meta { font-size: 0.8rem; color: var(--muted); }
        .detail-block { margin-top: 0.75rem; }
    </style>
</head>
<body>
<div class="wrap">
    <p style="margin-bottom:1rem;font-size:0.9rem;">
        <a href="{{ route('test.user-sessions') }}" style="color:#2563eb;">جلسات المستخدمين</a>
    </p>
    <h1>إدارة مهام الكرون (Scheduler)</h1>
    <p class="subtitle">
        شغّل أوامر <code>php artisan</code> من المتصفح واطّلع على النتيجة وسجل
        <code>cron_job_logs</code>.
    </p>

    <div class="warn">
        للاستخدام المحلي/الإداري. على الإنتاج عطّل الصفحة عبر
        <code>CRON_MANAGER_WEB_ENABLED=false</code> في <code>.env</code>.
        المجدول: <code>checks:send-due-reminders</code> يومياً 00:00،
        <code>employees:send-daily-task-reminders</code> يومياً 10:00 بتوقيت فلسطين.
    </div>

    @if(session('run_result'))
        @php $r = session('run_result'); @endphp
        <div class="result {{ !empty($r['success']) ? 'ok' : 'fail' }}">
            <strong>نتيجة التشغيل:</strong>
            <code>{{ $r['command'] ?? '' }}</code>
            — كود الخروج: <strong>{{ $r['exit_code'] ?? '?' }}</strong>
            @if(!empty($r['success']))
                <span class="status-success">نجاح</span>
            @else
                <span class="status-failed">فشل</span>
            @endif
            @if(!empty($r['error']))
                <p class="meta" style="color:var(--danger);">{{ $r['error'] }}</p>
            @endif
            @if(!empty($r['output']))
                <div class="log-box">{{ $r['output'] }}</div>
            @endif
            @if(!empty($r['log_id']))
                <p class="meta detail-block">
                    <a class="link" href="{{ route('test.cron-jobs.log', $r['log_id']) }}">عرض السجل #{{ $r['log_id'] }}</a>
                </p>
            @endif
        </div>
    @endif

    <div class="grid-2">
        <div class="card">
            <h2>تشغيل أمر</h2>
            @foreach($commands as $signature => $meta)
                <div class="cmd-card">
                    <div class="cmd-title">
                        {{ $meta['label'] ?? $signature }}
                        @if(!empty($meta['scheduled']))
                            <span class="badge">مجدول: {{ $meta['schedule_human'] ?? '' }}</span>
                        @endif
                    </div>
                    <div class="cmd-sig">php artisan {{ $signature }}</div>
                    @if(!empty($meta['description']))
                        <p class="cmd-desc">{{ $meta['description'] }}</p>
                    @endif
                    <form method="post" action="{{ route('test.cron-jobs.run') }}">
                        @csrf
                        <input type="hidden" name="command" value="{{ $signature }}">
                        @foreach($meta['arguments'] ?? [] as $argName => $argMeta)
                            @if(($argMeta['type'] ?? 'text') === 'checkbox')
                                <label style="display:flex;align-items:center;gap:0.5rem;margin:0.5rem 0;font-size:0.9rem;">
                                    <input type="checkbox" name="{{ $argName }}" value="1">
                                    {{ $argMeta['label'] ?? $argName }}
                                </label>
                            @else
                                <label for="{{ $argName }}_{{ md5($signature) }}">{{ $argMeta['label'] ?? $argName }}</label>
                                <input
                                    type="text"
                                    id="{{ $argName }}_{{ md5($signature) }}"
                                    name="{{ $argName }}"
                                    placeholder="{{ $argMeta['placeholder'] ?? '' }}"
                                >
                            @endif
                        @endforeach
                        <button type="submit" class="run">تشغيل الآن</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="card">
            <h2>آخر السجلات</h2>
            @if($recentLogs->isEmpty())
                <p class="meta">لا توجد سجلات بعد. شغّل أمراً أو نفّذ
                    <code>php artisan migrate</code> لإنشاء الجدول.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الأمر</th>
                            <th>الحالة</th>
                            <th>المدة</th>
                            <th>الوقت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentLogs as $log)
                            <tr>
                                <td>{{ $log->id }}</td>
                                <td><code>{{ $log->command_name }}</code></td>
                                <td>
                                    <span class="status-{{ $log->status }}">{{ $log->status }}</span>
                                </td>
                                <td>{{ $log->duration_seconds ?? '-' }}ث</td>
                                <td class="meta">{{ $log->started_at?->format('Y-m-d H:i') }}</td>
                                <td>
                                    <a class="link" href="{{ route('test.cron-jobs.log', $log->id) }}">تفاصيل</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    @if($selectedLog)
        <div class="card">
            <h2>تفاصيل السجل #{{ $selectedLog->id }}</h2>
            <p class="meta">
                <strong>{{ $selectedLog->job_name }}</strong> /
                {{ $selectedLog->command_name }} —
                <span class="status-{{ $selectedLog->status }}">{{ $selectedLog->status }}</span>
            </p>
            <p class="meta">
                بدء: {{ $selectedLog->started_at?->format('Y-m-d H:i:s') }}
                @if($selectedLog->finished_at)
                    — انتهاء: {{ $selectedLog->finished_at->format('Y-m-d H:i:s') }}
                    ({{ $selectedLog->duration_seconds }} ث)
                @endif
            </p>
            @if($selectedLog->error_message)
                <p style="color:var(--danger);"><strong>خطأ:</strong> {{ $selectedLog->error_message }}</p>
            @endif
            @if($selectedLog->output)
                <strong>المخرجات:</strong>
                <div class="log-box">{{ $selectedLog->output }}</div>
            @endif
            @if($selectedLog->payload)
                <p class="meta" style="margin-top:0.75rem;"><strong>payload:</strong></p>
                <div class="log-box">{{ json_encode($selectedLog->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</div>
            @endif
        </div>
    @endif
</div>
</body>
</html>
