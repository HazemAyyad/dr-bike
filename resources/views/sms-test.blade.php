<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اختبار SMS - Twilio</title>
    <style>
        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #f3f4f6;
            color: #111827;
        }
        .wrap {
            max-width: 860px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .top-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .top-links a {
            color: #2563eb;
            text-decoration: none;
            font-size: 14px;
        }
        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            padding: 22px;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }
        .sub {
            color: #6b7280;
            margin: 0 0 18px;
            line-height: 1.7;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0 4px;
        }
        .stat {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: #f9fafb;
        }
        .stat span {
            display: block;
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 4px;
        }
        label {
            display: block;
            font-weight: 700;
            margin: 14px 0 6px;
        }
        input, textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 11px 12px;
            font: inherit;
            background: #fff;
        }
        textarea {
            min-height: 130px;
            resize: vertical;
            line-height: 1.7;
        }
        button {
            border: 0;
            border-radius: 8px;
            background: #166534;
            color: #fff;
            padding: 11px 18px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 14px;
        }
        button:hover {
            background: #14532d;
        }
        .alert {
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
            line-height: 1.7;
        }
        .ok {
            background: #ecfdf5;
            border: 1px solid #86efac;
            color: #14532d;
        }
        .bad {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #7f1d1d;
        }
        .errors {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #7c2d12;
        }
        code, pre {
            direction: ltr;
            text-align: left;
            unicode-bidi: embed;
        }
        pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #111827;
            color: #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            max-height: 320px;
            overflow: auto;
        }
        .meta {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 8px 12px;
            margin-top: 12px;
            font-size: 14px;
        }
        .meta strong {
            color: #374151;
        }
        @media (max-width: 720px) {
            .grid, .meta {
                grid-template-columns: 1fr;
            }
            .panel {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="top-links">
        <a href="{{ route('test.cron-jobs') }}">مهام الكرون</a>
        <a href="{{ route('test.admin-notify', $token ? ['token' => $token] : []) }}">إشعارات الأدمن</a>
        <a href="{{ route('test.employee-notify', $token ? ['token' => $token] : []) }}">إشعارات الموظفين</a>
    </div>

    <section class="panel">
        <h1>اختبار إرسال SMS عبر Twilio</h1>
        <p class="sub">
            اكتب رقم الجوال بصيغة دولية مثل <code>+97059xxxxxxx</code>. إذا ظهرت حالة مقبول من Twilio فهذا يعني أن الطلب وصل لهم، أما وصول الرسالة للجهاز يعتمد على حالة الرقم والشبكة وحساب Twilio.
            @if($tokenRequired)
                <br>الرابط محمي: <code>/test/sms?token=YOUR_TOKEN</code>
            @endif
        </p>

        <div class="grid">
            <div class="stat"><span>Account SID</span>{{ $twilio['account_sid'] }}</div>
            <div class="stat"><span>Auth Token</span>{{ $twilio['auth_token'] }}</div>
            <div class="stat"><span>From</span><code>{{ $twilio['from'] }}</code></div>
        </div>
    </section>

    @if($errors->any())
        <div class="alert errors">
            <strong>راجع الحقول:</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($result)
        <section class="alert {{ !empty($result['ok']) ? 'ok' : 'bad' }}">
            <strong>{{ !empty($result['ok']) ? 'نجح الاختبار' : 'فشل الاختبار' }}</strong>
            <div>{{ $result['message'] ?? '' }}</div>

            <div class="meta">
                @if(isset($result['http_status']))
                    <strong>HTTP Status</strong>
                    <code>{{ $result['http_status'] }}</code>
                @endif
                @if(!empty($result['twilio_sid']))
                    <strong>Twilio SID</strong>
                    <code>{{ $result['twilio_sid'] }}</code>
                @endif
                @if(!empty($result['twilio_status']))
                    <strong>Twilio Status</strong>
                    <code>{{ $result['twilio_status'] }}</code>
                @endif
                @if(!empty($result['twilio_error']))
                    <strong>Twilio Error</strong>
                    <code>{{ $result['twilio_error'] }}</code>
                @endif
            </div>

            @if(!empty($result['response']))
                <pre>{{ $result['response'] }}</pre>
            @endif
        </section>
    @endif

    <section class="panel">
        <form method="post" action="{{ route('test.sms.send') }}">
            @csrf
            @if($token)
                <input type="hidden" name="token" value="{{ $token }}">
            @endif

            <label for="phone">رقم الجوال</label>
            <input id="phone" name="phone" type="text" value="{{ old('phone') }}" placeholder="+97059xxxxxxx" dir="ltr" required>

            <label for="message">نص الرسالة</label>
            <textarea id="message" name="message" required>{{ old('message', 'Doctor Bike SMS test') }}</textarea>

            <button type="submit">إرسال رسالة اختبار</button>
        </form>
    </section>
</main>
</body>
</html>
