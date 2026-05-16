<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>جلسات — {{ $user->name }}</title>
    <style>
        :root {
            --bg: #f1f5f9; --card: #fff; --border: #e2e8f0;
            --primary: #2563eb; --primary-hover: #1d4ed8;
            --success: #16a34a; --danger: #dc2626; --muted: #64748b;
            --warn-bg: #fff7ed;
        }
        body { font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background: var(--bg); margin: 0; padding: 1.5rem 1rem 3rem; }
        .wrap { max-width: 900px; margin: 0 auto; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .flash { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .flash.success { background: #f0fdf4; border: 1px solid #86efac; }
        .btn { display: inline-block; padding: 0.45rem 0.85rem; border-radius: 6px; font-size: 0.88rem; text-decoration: none; border: none; cursor: pointer; margin-left: 0.35rem; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-outline { background: #fff; color: var(--primary); border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th, td { padding: 0.5rem 0.4rem; text-align: right; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-size: 0.78rem; }
        .meta { color: var(--muted); font-size: 0.88rem; }
        .badge { padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.72rem; font-weight: 600; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-expired { background: #fee2e2; color: #991b1b; }
        label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem; }
        input[type="password"] { width: 100%; max-width: 320px; padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; }
        .grid-2 { display: grid; gap: 1rem; }
        @media (min-width: 700px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="{{ route('test.user-sessions') }}" class="btn btn-outline">← العودة للقائمة</a></p>

    <h1>{{ $user->name }}</h1>
    <p class="meta">{{ $user->email }} — {{ $user->type === 'admin' ? 'مدير' : 'موظف' }} — #{{ $user->id }}</p>

    @if(!empty($flash))
        <div class="flash success">{{ $flash['message'] ?? '' }}</div>
    @endif

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem;">تسجيل خروج من كل الأجهزة</h2>
        <p class="meta">يحذف جميع رموز الدخول (Sanctum) و FCM للمدير. المستخدم يحتاج تسجيل دخول جديد من التطبيق.</p>
        <form method="post" action="{{ route('test.user-sessions.logout-all', $user->id) }}" onsubmit="return confirm('تسجيل خروج من جميع الجلسات؟');">
            @csrf
            <button type="submit" class="btn btn-danger">تسجيل خروج من كل الجلسات</button>
        </form>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2 style="margin-top:0;font-size:1rem;">الجلسات / رموز الوصول</h2>
            @php
                $activeCount = $tokens->filter(fn ($t) => $t->expires_at === null || $t->expires_at->isFuture())->count();
            @endphp
            <p class="meta">نشطة: <strong>{{ $activeCount }}</strong> — إجمالي: {{ $tokens->count() }}</p>

            @if($tokens->isEmpty())
                <p class="meta">لا توجد جلسات مسجّلة.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>الجهاز</th>
                            <th>آخر استخدام</th>
                            <th>ينتهي</th>
                            <th>الحالة</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tokens as $token)
                            @php
                                $isActive = $token->expires_at === null || $token->expires_at->isFuture();
                            @endphp
                            <tr>
                                <td>{{ $token->name }}</td>
                                <td>{{ $token->last_used_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ $token->expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $isActive ? 'badge-ok' : 'badge-expired' }}">
                                        {{ $isActive ? 'نشطة' : 'منتهية' }}
                                    </span>
                                </td>
                                <td>
                                    @if($isActive)
                                        <form method="post" action="{{ route('test.user-sessions.revoke', $token->id) }}" style="display:inline" onsubmit="return confirm('إنهاء هذه الجلسة؟');">
                                            @csrf
                                            <button type="submit" class="btn btn-danger" style="padding:0.25rem 0.5rem;font-size:0.75rem;">إنهاء</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <h2 style="margin-top:0;font-size:1rem;">تغيير كلمة المرور</h2>
            <p class="meta">تعيين كلمة مرور جديدة بدون الحاجة للقديمة (إدارة).</p>
            <form method="post" action="{{ route('test.user-sessions.password', $user->id) }}">
                @csrf
                <label for="password">كلمة المرور الجديدة</label>
                <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
                <label for="password_confirmation">تأكيد كلمة المرور</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="6" autocomplete="new-password">
                <button type="submit" class="btn btn-primary">حفظ كلمة المرور</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
