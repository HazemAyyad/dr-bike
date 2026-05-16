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
        .btn-danger { background: var(--danger); color: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th, td { padding: 0.55rem 0.4rem; text-align: right; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-size: 0.78rem; font-weight: 600; }
        .badge { display: inline-block; padding: 0.12rem 0.45rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-admin { background: #dbeafe; color: #1e40af; }
        .badge-employee { background: #dcfce7; color: #166534; }
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
        <a href="{{ route('test.user-sessions') }}">جلسات المستخدمين</a>
    </div>

    <h1>إدارة جلسات الموظفين والمدراء</h1>
    <p class="subtitle">عرض الجلسات النشطة (رموز Sanctum)، تسجيل خروج قسري، وتغيير كلمات المرور.</p>

    <div class="warn">
        للاستخدام الإداري المحلي. على الإنتاج عطّل عبر <code>USER_SESSIONS_WEB_ENABLED=false</code> في <code>.env</code>.
        تسجيل الخروج يحذف جميع رموز الوصول — يجب على المستخدم تسجيل الدخول من التطبيق مرة أخرى.
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
            <a href="{{ route('test.user-sessions') }}" class="btn btn-outline">مسح</a>
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
                        <th>إجمالي الرموز</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
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
                            <td>{{ $u->total_tokens_count }}</td>
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
