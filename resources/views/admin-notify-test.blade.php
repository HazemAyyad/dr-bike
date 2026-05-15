<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إرسال إشعار للأدمن</title>
    <style>
        body { font-family: system-ui, Segoe UI, Tahoma, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; background: #f6f7f9; color: #1e293b; }
        h1 { font-size: 1.35rem; margin-bottom: 0.5rem; }
        .sub { color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem; }
        .warn { background: #fff3cd; border: 1px solid #ffc107; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .info { background: #e0f2fe; border: 1px solid #7dd3fc; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .ok-box { background: #dcfce7; border: 1px solid #86efac; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
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
        .stat strong { display: block; font-size: 1.5rem; color: #2563eb; }
        .stat span { font-size: 0.85rem; color: #64748b; }
        .errors { color: #b91c1c; font-size: 0.9rem; margin-bottom: 1rem; }
        .errors ul { margin: 0; padding-right: 1.2rem; }
        .fcm-box { background: #fff; padding: 1.25rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1.25rem; }
        .fcm-box h2 { font-size: 1.1rem; margin: 0 0 0.75rem; }
        .btn-row { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .btn-link {
            display: block; text-align: center; padding: 0.6rem 1rem; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.95rem;
        }
        .btn-primary { background: #059669; color: #fff; }
        .btn-primary:hover { background: #047857; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .btn-secondary:hover { background: #cbd5e1; }
        .url-list { font-size: 0.8rem; word-break: break-all; margin: 0; padding: 0; list-style: none; }
        .url-list li { margin-bottom: 0.5rem; padding: 0.5rem; background: #f8fafc; border-radius: 4px; border: 1px solid #e2e8f0; }
        .url-list a { color: #2563eb; }
        .fail-box { background: #fee2e2; border: 1px solid #fca5a5; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .meta { font-size: 0.85rem; color: #475569; margin-top: 0.5rem; }
        .meta code { font-size: 0.8rem; background: #f1f5f9; padding: 0.1rem 0.35rem; border-radius: 3px; word-break: break-all; }
        input.fcm-token { font-family: ui-monospace, monospace; font-size: 0.85rem; }
    </style>
</head>
<body>
    <h1>إرسال إشعار للأدمن</h1>
    <p class="sub">يُحفظ في مركز الإشعارات ويُرسل كـ FCM لكل أجهزة الأدمن المسجّلة.</p>

    @if(!$tokenRequired)
        <div class="warn">
            <strong>تنبيه:</strong> لم يُضبط <code>ADMIN_NOTIFY_WEB_TOKEN</code> في <code>.env</code>.
            أي شخص يصل للرابط يمكنه الإرسال — للتجربة المحلية فقط.
        </div>
    @endif

    <div class="info">
        افتح التطبيق كأدمن مرة واحدة لتسجيل رمز FCM.
        @if($tokenRequired)
            <br>الرابط المحمي: <code>/test/admin-notify?token=YOUR_TOKEN</code>
        @endif
    </div>

    @php
        $fb = $firebaseDiagnostics ?? [];
        $fbReady = !empty($fb['messaging_ready']);
        $fbProject = $fb['project_id'] ?? null;
        $flutterProject = $flutterExpectedProjectId ?? 'drbike-7fa3a';
        $projectMismatch = $fbProject && $fbProject !== $flutterProject;
    @endphp

    <div class="{{ $fbReady ? 'ok-box' : 'fail-box' }}" style="margin-bottom:1rem">
        <strong>حالة Firebase على السيرفر</strong>
        @if($fbReady)
            <br>جاهز — project_id: <code>{{ $fbProject }}</code>
        @else
            <br><span style="color:#b91c1c">{{ $fb['last_error'] ?? 'غير مهيأ' }}</span>
        @endif
        <div class="meta">
            @if(!empty($fb['env_path']))
                <br>FIREBASE_CREDENTIALS: <code>{{ $fb['env_path'] }}</code>
            @else
                <br>FIREBASE_CREDENTIALS: غير مضبوط في .env
            @endif
            @if(!empty($fb['resolved_path']))
                <br>ملف credentials: <code>{{ $fb['resolved_path'] }}</code>
            @else
                <br>لا يوجد ملف Admin SDK JSON على السيرفر
            @endif
            <br>مشروع التطبيق: <code>{{ $flutterProject }}</code>
            @if($projectMismatch)
                <br><strong style="color:#b45309">تحذير: مشروع Laravel ≠ التطبيق</strong>
            @endif
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <strong>{{ $deviceCount }}</strong>
            <span>جهاز أدمن (FCM)</span>
        </div>
        <div class="stat">
            <strong>{{ $unreadCount }}</strong>
            <span>إشعارات غير مقروءة</span>
        </div>
    </div>

    @if(session('result'))
        <div class="{{ !empty(session('result.ok')) ? 'ok-box' : 'fail-box' }}">
            {{ session('result.message') }}
            @if(!empty(session('result.notification_id')))
                <br><small>معرّف الإشعار: #{{ session('result.notification_id') }}</small>
            @endif
            @if(!empty(session('result.mode')) && session('result.mode') === 'fcm_test')
                <div class="meta">
                    @if(!empty(session('result.used_latest')))
                        <br>الجهاز: أحدث سجل
                        @if(!empty(session('result.device_token_id')))
                            (#{{ session('result.device_token_id') }})
                        @endif
                    @endif
                    @if(!empty(session('result.token_prefix')))
                        <br>بادئة التوكن: <code>{{ session('result.token_prefix') }}</code>
                    @endif
                    @if(!empty(session('result.firebase_project_id')))
                        <br>مشروع Firebase (Laravel): <code>{{ session('result.firebase_project_id') }}</code>
                    @endif
                    @if(!empty(session('result.channel_id')))
                        <br>قناة Android: <code>{{ session('result.channel_id') }}</code>
                    @endif
                    @if(!empty(session('result.firebase_response')))
                        <br>استجابة Firebase: <code>{{ session('result.firebase_response') }}</code>
                    @endif
                    @if(!empty(session('result.credentials_diagnostics.last_error')))
                        <br>تفاصيل Firebase: <code>{{ session('result.credentials_diagnostics.last_error') }}</code>
                    @endif
                    @if(!empty(session('result.credentials_diagnostics.resolved_path')))
                        <br>ملف credentials: <code>{{ session('result.credentials_diagnostics.resolved_path') }}</code>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="fcm-box">
        <h2>اختبار FCM مباشر</h2>
        <p class="sub" style="margin-top:0">مثل <code>php artisan admin:fcm-test</code> — بدون حفظ في قاعدة البيانات.</p>

        <div class="btn-row">
            <a class="btn-link btn-primary" href="{{ $fcmTestLatestUrl }}">
                إرسال DoctorBike Test لأحدث جهاز
            </a>
            @if($latestDevice)
                <a class="btn-link btn-secondary" href="{{ route('test.admin-notify.fcm-test', array_merge($token ? ['token' => $token] : [], ['fcm_token' => $latestDevice->fcm_token])) }}">
                    إرسال لنفس أحدث جهاز (توكن صريح في الرابط)
                </a>
            @endif
        </div>

        <p style="font-size:0.9rem;font-weight:600;margin:0 0 0.35rem">روابط جاهزة (للنسخ):</p>
        <ul class="url-list">
            @foreach($fcmTestUrls as $example)
                <li>
                    <strong>{{ $example['label'] }}</strong><br>
                    <a href="{{ $example['url'] }}">{{ $example['url'] }}</a>
                </li>
            @endforeach
        </ul>

        @if($latestDevice)
            <p class="meta" style="margin-top:0.75rem">
                أحدث توكن (معاينة): <code>{{ \Illuminate\Support\Str::limit($latestDevice->fcm_token, 48) }}</code>
                — user_id {{ $latestDevice->user_id }} — id #{{ $latestDevice->id }}
            </p>
        @else
            <p class="meta" style="margin-top:0.75rem;color:#b45309">لا يوجد توكن مسجّل بعد.</p>
        @endif

        <form method="post" action="{{ route('test.admin-notify.fcm-test.post') }}" style="margin-top:1rem;box-shadow:none;padding:0">
            @csrf
            @if($token)
                <input type="hidden" name="token" value="{{ $token }}">
            @endif
            <div class="field">
                <label for="fcm_token">توكن محدد (مثل: admin:fcm-test "TOKEN")</label>
                <input type="text" class="fcm-token" id="fcm_token" name="fcm_token"
                       value="{{ old('fcm_token', $latestDevice->fcm_token ?? '') }}"
                       placeholder="الصق fcm_token هنا" maxlength="512">
            </div>
            <button type="submit" style="background:#059669">إرسال FCM Test لهذا التوكن</button>
        </form>
    </div>

    @if($errors->any())
        <div class="errors">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('test.admin-notify.send') }}">
        @csrf
        @if($token)
            <input type="hidden" name="token" value="{{ $token }}">
        @endif

        <div class="field">
            <label for="type">نوع الإشعار</label>
            <select id="type" name="type" required>
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', 'admin_manual') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="title">العنوان</label>
            <input type="text" id="title" name="title" value="{{ old('title', 'اختبار إشعار') }}" required maxlength="255">
        </div>

        <div class="field">
            <label for="body">النص</label>
            <textarea id="body" name="body" required maxlength="2000">{{ old('body', 'هذا إشعار تجريبي من صفحة الويب.') }}</textarea>
        </div>

        <label class="check">
            <input type="hidden" name="send_push" value="0">
            <input type="checkbox" name="send_push" value="1" @checked(old('send_push', '1') == '1')>
            إرسال إشعار فوري (Firebase) للأجهزة
        </label>

        <button type="submit">إرسال للأدمن</button>
    </form>
</body>
</html>
