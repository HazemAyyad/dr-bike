@php
    $summary = $report['summary'] ?? [];
    $sent = $report['sent'] ?? [];
    $notSent = $report['not_sent'] ?? [];
@endphp

<div class="cron-report">
    <p class="cron-report-head">
        <strong>تاريخ التذكير:</strong> {{ $report['date'] ?? '—' }}
        @if(!empty($report['force']))
            <span class="badge badge-warn">إعادة إرسال (force)</span>
        @endif
    </p>

    <div class="cron-report-block">
        <h3>ماذا يُرسل؟</h3>
        <p class="meta">نفس النص لكل موظف لديه مهام، مع عدد المهام وأسمائها:</p>
        <table class="report-table">
            <tr><th>العنوان</th><td>{{ $report['message_template']['title'] ?? 'مهامك لليوم' }}</td></tr>
            <tr><th>صيغة النص</th><td>{{ $report['message_template']['body_pattern'] ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="cron-report-block">
        <h3>ملخص التشغيل</h3>
        <div class="summary-chips">
            <span class="chip">موظفون: {{ $summary['total_employees'] ?? 0 }}</span>
            <span class="chip chip-ok">وصل FCM: {{ $summary['fcm_sent'] ?? 0 }}</span>
            <span class="chip chip-warn">فشل FCM: {{ $summary['fcm_failed'] ?? 0 }}</span>
            <span class="chip">داخل التطبيق فقط: {{ $summary['in_app_only'] ?? 0 }}</span>
            <span class="chip chip-muted">لم يُرسل: {{ $summary['skipped'] ?? 0 }}</span>
        </div>
    </div>

    <div class="cron-report-block">
        <h3>لمن أُرسل؟ ({{ count($sent) }})</h3>
        @if(count($sent) === 0)
            <p class="meta">لا أحد — لم يُنشأ إشعار جديد في هذا التشغيل.</p>
        @else
            <table class="report-table report-table-wide">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>النتيجة</th>
                        <th>العنوان</th>
                        <th>النص المُرسل</th>
                        <th>المهام</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sent as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>
                                <strong>{{ $row['name'] ?? '?' }}</strong>
                                <span class="meta"> #{{ $row['employee_id'] ?? '?' }}</span>
                                @if(!empty($row['notification_id']))
                                    <br><span class="meta">إشعار #{{ $row['notification_id'] }}</span>
                                @endif
                            </td>
                            <td>
                                @php $st = $row['status'] ?? ''; @endphp
                                <span class="result-{{ $st }}">{{ $row['result'] ?? $st }}</span>
                            </td>
                            <td>{{ $row['title'] ?? '—' }}</td>
                            <td class="body-cell">{{ $row['body'] ?? '—' }}</td>
                            <td>
                                @if(!empty($row['task_names']))
                                    {{ implode('، ', $row['task_names']) }}
                                    <br><span class="meta">({{ $row['tasks_count'] ?? count($row['task_names']) }} مهمة)</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if(count($notSent) > 0)
        <div class="cron-report-block">
            <h3>لم يُرسل ({{ count($notSent) }})</h3>
            <table class="report-table report-table-wide">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>السبب</th>
                        <th>مهامه اليوم (إن وُجدت)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($notSent as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td><strong>{{ $row['name'] ?? '?' }}</strong> <span class="meta">#{{ $row['employee_id'] ?? '?' }}</span></td>
                            <td><span class="result-muted">{{ $row['result'] ?? '—' }}</span></td>
                            <td>
                                @if(!empty($row['task_names']))
                                    {{ implode('، ', $row['task_names']) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
