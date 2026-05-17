<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إرسال إشعار للموظفين</title>
    <style>
        body { font-family: system-ui, Segoe UI, Tahoma, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; background: #f6f7f9; color: #1e293b; }
        h1 { font-size: 1.35rem; margin-bottom: 0.5rem; }
        .sub { color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem; }
        .nav { margin-bottom: 1rem; font-size: 0.88rem; }
        .nav a { color: #2563eb; margin-left: 0.75rem; }
        .warn { background: #fff3cd; border: 1px solid #ffc107; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .info { background: #e0f2fe; border: 1px solid #7dd3fc; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .ok-box { background: #dcfce7; border: 1px solid #86efac; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .fail-box { background: #fee2e2; border: 1px solid #fca5a5; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        form { background: #fff; padding: 1.25rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        label { display: block; margin-bottom: 0.35rem; font-weight: 600; font-size: 0.95rem; }
        input[type="text"], textarea, select {
            width: 100%; padding: 0.55rem 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px;
            font-size: 1rem; box-sizing: border-box;
        }
        textarea { min-height: 100px; resize: vertical; }
        .field { margin-bottom: 1rem; }
        .check { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; }
        .check input { width: auto; }
        button {
            width: 100%; margin-top: 0.5rem; padding: 0.65rem 1rem; background: #2563eb; color: #fff;
            border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: 600;
        }
        button:hover { background: #1d4ed8; }
        .stats { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .stat { background: #fff; padding: 0.75rem 1rem; border-radius: 8px; flex: 1; min-width: 140px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .stat strong { display: block; font-size: 1.5rem; color: #059669; }
        .stat span { font-size: 0.85rem; color: #64748b; }
        .fcm-box { background: #fff; padding: 1.25rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1.25rem; }
        .fcm-box h2 { font-size: 1.1rem; margin: 0 0 0.75rem; }
        .btn-row { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .btn-link {
            display: block; text-align: center; padding: 0.6rem 1rem; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.95rem;
        }
        .btn-primary { background: #059669; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .url-list { font-size: 0.8rem; word-break: break-all; margin: 0; padding: 0; list-style: none; }
        .url-list li { margin-bottom: 0.5rem; padding: 0.5rem; background: #f8fafc; border-radius: 4px; border: 1px solid #e2e8f0; }
        .meta { font-size: 0.85rem; color: #475569; margin-top: 0.5rem; }
        .meta code { font-size: 0.8rem; background: #f1f5f9; padding: 0.1rem 0.35rem; border-radius: 3px; word-break: break-all; }
        input.fcm-token { font-family: ui-monospace, monospace; font-size: 0.85rem; }
        .fcm-ok { color: #16a34a; font-size: 0.8rem; }
        .fcm-no { color: #b45309; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="nav">
        <a href="{{ route('test.admin-notify', $token ? ['token' => $token] : []) }}">إشعارات الأدمن</a>
        <a href="{{ route('test.user-sessions') }}">جلسات المستخدمين</a>
        <a href="{{ route('test.cron-jobs') }}">مهام الكرون</a>
    </div>

    <h1>إرسال إشعار للموظفين</h1>
    <p class="sub">يُحفظ في مركز إشعارات الموظف ويُرسل FCM لتوكن <code>users.fcm_token</code> عند التفعيل.</p>

    @if(!$tokenRequired)
        <div class="warn">
            <strong>تنبيه:</strong> لم يُضبط <code>EMPLOYEE_NOTIFY_WEB_TOKEN</code> (أو <code>ADMIN_NOTIFY_WEB_TOKEN</code>) في <code>.env</code>.
        </div>
    @endif

    <div class="info">
        افتح التطبيق كموظف مرة واحدة لتسجيل رمز FCM عند تسجيل الدخول.
        @if($tokenRequired)
            <br>الرابط المحمي: <code>/test/employee-notify?token=YOUR_TOKEN</code>
        @endif
    </div>

    @php
        $fb = $firebaseDiagnostics ?? [];
        $fbReady = !empty($fb['messaging_ready']);
        $fbProject = $fb['project_id'] ?? null;
    @endphp

    <div class="{{ $fbReady ? 'ok-box' : 'fail-box' }}">
        <strong>حالة Firebase</strong>
        @if($fbReady)
            <br>جاهز — project_id: <code>{{ $fbProject }}</code>
        @else
            <br><span style="color:#b91c1c">{{ $fb['last_error'] ?? 'غير مهيأ' }}</span>
        @endif
    </div>

    <div class="stats">
        <div class="stat">
            <strong>{{ $employeeTokenCount }}</strong>
            <span>موظفون بتوكن FCM</span>
        </div>
        <div class="stat">
            <strong>{{ $unreadCount }}</strong>
            <span>إشعارات غير مقروءة (الكل)</span>
        </div>
    </div>

    @if(session('result'))
        <div class="{{ !empty(session('result.ok')) ? 'ok-box' : 'fail-box' }}">
            {{ session('result.message') }}
            @if(!empty(session('result.notification_count')))
                <br><small>عدد الموظفين: {{ session('result.notification_count') }}</small>
            @endif
            @if(!empty(session('result.mode')) && session('result.mode') === 'fcm_test')
                <div class="meta">
                    @if(!empty(session('result.used_latest')))
                        <br>المستخدم: أحدث موظف (#{{ session('result.user_id') ?? '—' }})
                    @endif
                    @if(!empty(session('result.token_prefix')))
                        <br>بادئة التوكن: <code>{{ session('result.token_prefix') }}</code>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="fcm-box">
        <h2>اختبار FCM مباشر</h2>
        <p class="sub" style="margin-top:0">بدون حفظ في قاعدة البيانات — لأحدث موظف لديه توكن.</p>
        <div class="btn-row">
            <a class="btn-link btn-primary" href="{{ $fcmTestLatestUrl }}">إرسال DoctorBike Test (موظف)</a>
            @if($latestUser)
                <a class="btn-link btn-secondary" href="{{ route('test.employee-notify.fcm-test', array_merge($token ? ['token' => $token] : [], ['fcm_token' => $latestUser->fcm_token])) }}">
                    إرسال لتوكن {{ $latestUser->name }}
                </a>
            @endif
        </div>
        <ul class="url-list">
            @foreach($fcmTestUrls as $example)
                <li><strong>{{ $example['label'] }}</strong><br><a href="{{ $example['url'] }}">{{ $example['url'] }}</a></li>
            @endforeach
        </ul>
        @if($latestUser)
            <p class="meta">أحدث: <strong>{{ $latestUser->name }}</strong> — <code>{{ \Illuminate\Support\Str::limit($latestUser->fcm_token, 48) }}</code></p>
        @else
            <p class="meta" style="color:#b45309">لا يوجد موظف بتوكن FCM بعد.</p>
        @endif
        <form method="post" action="{{ route('test.employee-notify.fcm-test.post') }}" style="margin-top:1rem;box-shadow:none;padding:0">
            @csrf
            @if($token)<input type="hidden" name="token" value="{{ $token }}">@endif
            <div class="field">
                <label for="fcm_token">توكن محدد</label>
                <input type="text" class="fcm-token" id="fcm_token" name="fcm_token"
                       value="{{ old('fcm_token', $latestUser->fcm_token ?? '') }}" maxlength="512">
            </div>
            <button type="submit" style="background:#059669">إرسال FCM Test</button>
        </form>
    </div>

    @if($errors->any())
        <ul style="color:#b91c1c">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif

    <form method="post" action="{{ route('test.employee-notify.send') }}">
        @csrf
        @if($token)<input type="hidden" name="token" value="{{ $token }}">@endif

        <div class="field">
            <label class="check">
                <input type="hidden" name="send_to_all" value="0">
                <input type="checkbox" name="send_to_all" value="1" id="send_to_all" @checked(old('send_to_all') == '1')>
                إرسال لجميع الموظفين
            </label>
        </div>

        <div class="field" id="employee_pick">
            <label for="employee_id">الموظف</label>
            <select id="employee_id" name="employee_id">
                <option value="">— اختر موظفاً —</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp['id'] }}" @selected(old('employee_id') == $emp['id'])>
                        {{ $emp['name'] }} ({{ $emp['email'] }})
                        {{ $emp['has_fcm'] ? '✓ FCM' : '— بدون توكن' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="type">نوع الإشعار</label>
            <select id="type" name="type" required>
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', 'employee_manual') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="title">العنوان</label>
            <input type="text" id="title" name="title" value="{{ old('title', 'اختبار إشعار موظف') }}" required maxlength="255">
        </div>

        <div class="field">
            <label for="body">النص</label>
            <textarea id="body" name="body" required maxlength="2000">{{ old('body', 'هذا إشعار تجريبي من صفحة الويب للموظفين.') }}</textarea>
        </div>

        <label class="check">
            <input type="hidden" name="send_push" value="0">
            <input type="checkbox" name="send_push" value="1" @checked(old('send_push', '1') == '1')>
            إرسال إشعار فوري (Firebase)
        </label>

        <button type="submit">إرسال للموظف</button>
    </form>

    <script>
        const allCb = document.getElementById('send_to_all');
        const pick = document.getElementById('employee_pick');
        const sel = document.getElementById('employee_id');
        function sync() {
            const all = allCb.checked;
            pick.style.opacity = all ? '0.5' : '1';
            sel.disabled = all;
            if (all) sel.removeAttribute('required'); else sel.setAttribute('required', 'required');
        }
        allCb.addEventListener('change', sync);
        sync();
    </script>
</body>
</html>
