<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إدارة جلسات الموظفين والمدراء</title>
    <style>
        :root {
            --bg: #f1f5f9; --card: #fff; --border: #e2e8f0;
            --primary: #2563eb; --primary-hover: #1d4ed8;
            --success: #16a34a; --danger: #dc2626; --muted: #64748b;
        }
        body { font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background: var(--bg); margin: 0; padding: 1.5rem 1rem 3rem; color: #0f172a; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
        .subtitle { color: var(--muted); margin-bottom: 1rem; font-size: 0.95rem; }
        .warn { background: #fff7ed; border: 1px solid #fdba74; padding: 0.85rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.9rem; }
        .flash { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .flash.success { background: #f0fdf4; border: 1px solid #86efac; }
        .flash.error { background: #fef2f2; border: 1px solid #fca5a5; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(15,23,42,.06); }
        .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-bottom: 1rem; }
        .filters label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; }
        .filters input, .filters select { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; }
        .btn { display: inline-block; padding: 0.45rem 0.85rem; border-radius: 6px; font-size: 0.88rem; text-decoration: none; border: none; cursor: pointer; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: #fff; color: var(--primary); border: 1px solid var(--border); }
        .btn-danger { background: var(--danger); color: #fff; font-weight: 700; }
        .btn-danger-lg {
            width: 100%; padding: 0.85rem 1rem; font-size: 1rem; margin-top: 0.5rem;
            background: var(--danger); color: #fff; border: 2px solid #b91c1c; border-radius: 8px;
            cursor: pointer; font-weight: 700;
        }
        .btn-danger-lg:hover { background: #b91c1c; }
        .danger-zone {
            border: 2px solid #dc2626; background: #fef2f2; border-radius: 12px;
            padding: 1rem 1.25rem; margin-bottom: 1.25rem;
        }
        .danger-zone h2 { margin: 0 0 0.35rem; font-size: 1.1rem; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th, td { padding: 0.55rem 0.4rem; text-align: right; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-size: 0.78rem; font-weight: 600; }
        .badge { display: inline-block; padding: 0.12rem 0.45rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-admin { background: #dbeafe; color: #1e40af; }
        .badge-employee { background: #dcfce7; color: #166534; }
        .badge-fcm-yes { background: #dcfce7; color: #166534; }
        .badge-fcm-no { background: #f1f5f9; color: #64748b; }
        .badge-fcm-warn { background: #fff7ed; color: #9a3412; }
        .fcm-preview { font-size: 0.72rem; color: var(--muted); font-family: ui-monospace, monospace; display: block; margin-top: 0.15rem; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .count-active { font-weight: 700; color: var(--primary); }
        .count-zero { color: var(--muted); }
        .nav-links { margin-bottom: 1rem; font-size: 0.9rem; }
        .nav-links a { color: var(--primary); margin-left: 1rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="nav-links">
        <a href="{{ route('test.cron-jobs') }}">مهام الكرون</a>
        <a href="{{ route('test.admin-notify') }}">إشعارات الأدمن</a>
        <a href="{{ route('test.employee-notify') }}">إشعارات الموظفين</a>
    </div>

    <h1>إدارة جلسات الموظفين والمدراء</h1>
    <p class="subtitle">عرض الجلسات النشطة (رموز Sanctum)، تسجيل خروج قسري، وتغيير كلمات المرور.</p>

    <div class="danger-zone" id="logout-all-staff">
        <h2>⚠ مسح كل جلسات الدخول (مدراء + موظفون)</h2>
        <p style="margin:0;font-size:0.9rem;color:#7f1d1d;line-height:1.5">
            يحذف <strong>جميع</strong> رموز تسجيل الدخول ويمسح <code>fcm_token</code> للجميع.
            بعدها يجب تسجيل الدخول <strong>بكلمة المرور</strong> من التطبيق (ليس البصمة فقط) حتى تظهر جلسة جديدة هنا.
            <br><small style="color:#64748b">زر «إلغاء الفلتر» أسفل الصفحة يمسح البحث فقط — ليس هذا الزر.</small>
        </p>
        <form method="post" action="{{ route('test.user-sessions.logout-all-staff') }}"
              onsubmit="return confirm('تأكيد: تسجيل خروج كل المدراء والموظفين من كل الأجهزة ومسح توكنات FCM؟');">
            @csrf
            <button type="submit" class="btn-danger-lg">
                مسح كل الجلسات — إعادة تسجيل الدخول للجميع
            </button>
        </form>
    </div>

    <div class="warn">
        للاستخدام الإداري. على الإنتاج: <code>USER_SESSIONS_WEB_ENABLED=false</code> يعطّل الصفحة بالكامل.
        خروج مستخدم واحد من زر «إدارة» بجانب اسمه.
    </div>

    @if(!empty($flash))
        <div class="flash {{ ($flash['type'] ?? '') === 'success' ? 'success' : 'error' }}">
            {{ $flash['message'] ?? '' }}
        </div>
    @endif

    <div class="card">
        <form method="get" action="{{ route('test.user-sessions') }}" class="filters">
            <div>
                <label>النوع</label>
                <select name="type">
                    <option value="">الكل</option>
                    <option value="admin" @selected($filterType === 'admin')>مدراء</option>
                    <option value="employee" @selected($filterType === 'employee')>موظفون</option>
                </select>
            </div>
            <div>
                <label>بحث (اسم / بريد / جوال)</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="بحث...">
            </div>
            <button type="submit" class="btn btn-primary">تطبيق</button>
            <a href="{{ route('test.user-sessions') }}" class="btn btn-outline">إلغاء الفلتر</a>
        </form>

        @if($users->isEmpty())
            <p style="color:var(--muted);">لا يوجد مستخدمون مطابقون.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>البريد</th>
                        <th>النوع</th>
                        <th>جلسات نشطة</th>
                        <th>آخر جلسة</th>
                        <th>إجمالي الرموز</th>
                        <th>FCM</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                        @php $fcm = app(\App\Services\UserSessionManager::class)->fcmStatusForUser($u); @endphp
                        <tr>
                            <td>{{ $u->id }}</td>
                            <td>{{ $u->name }}</td>
                            <td>{{ $u->email }}</td>
                            <td>
                                <span class="badge badge-{{ $u->type }}">
                                    {{ $u->type === 'admin' ? 'مدير' : 'موظف' }}
                                </span>
                            </td>
                            <td>
                                <span class="{{ $u->active_sessions_count > 0 ? 'count-active' : 'count-zero' }}">
                                    {{ $u->active_sessions_count }}
                                </span>
                            </td>
                            <td class="{{ $u->latest_token_at ? '' : 'count-zero' }}" style="font-size:0.82rem">
                                @if($u->latest_token_at)
                                    {{ \Illuminate\Support\Carbon::parse($u->latest_token_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $u->total_tokens_count }}</td>
                            <td>
                                @php
                                    $fcmBadge = $fcm['has_fcm']
                                        ? 'badge-fcm-yes'
                                        : (($fcm['is_no_token_placeholder'] ?? false) ? 'badge-fcm-warn' : 'badge-fcm-no');
                                @endphp
                                <span class="badge {{ $fcmBadge }}">
                                    {{ $fcm['label'] }}
                                </span>
                                @if($fcm['token_preview'])
                                    <span class="fcm-preview" title="{{ $u->fcm_token }}">{{ $fcm['token_preview'] }}</span>
                                @endif
                            </td>
                            <td>
                                <a class="btn btn-outline" href="{{ route('test.user-sessions.show', $u->id) }}">إدارة</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
</body>
</html>
