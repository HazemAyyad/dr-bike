<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تصفير قسم الطلبيات</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --ink: #172033;
            --muted: #667085;
            --line: #d9e2ef;
            --brand: #0f766e;
            --brand-dark: #115e59;
            --danger: #b42318;
            --danger-bg: #fff1f0;
            --ok: #087443;
            --ok-bg: #ecfdf3;
            --warn: #946100;
            --warn-bg: #fffaeb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font-family: Tahoma, Arial, sans-serif;
        }

        main {
            width: min(1080px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 44px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 20px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(26px, 4vw, 38px);
            line-height: 1.25;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.8;
        }

        .badge {
            flex: 0 0 auto;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 14px;
            background: var(--panel);
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
        }

        .notice {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 18px;
            background: var(--panel);
            box-shadow: 0 12px 34px rgba(15, 23, 42, .07);
        }

        .notice h2 {
            margin: 0 0 8px;
            font-size: 19px;
        }

        .notice p {
            margin: 0;
            color: var(--muted);
            line-height: 1.8;
        }

        .notice.preview { border-color: #fedf89; background: var(--warn-bg); }
        .notice.done { border-color: #abefc6; background: var(--ok-bg); }
        .notice.locked { border-color: #fecdca; background: var(--danger-bg); }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0;
        }

        .metric {
            min-height: 92px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
            background: var(--panel);
        }

        .metric .label {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
            word-break: break-word;
        }

        .metric .value {
            margin-top: 8px;
            font-size: 28px;
            font-weight: 700;
        }

        .section {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            padding: 18px;
            margin-top: 14px;
        }

        .section h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 16px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .list li {
            position: relative;
            padding-inline-start: 18px;
            color: var(--muted);
            line-height: 1.7;
        }

        .list li::before {
            content: "";
            position: absolute;
            inset-inline-start: 0;
            top: 12px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--brand);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
        }

        button, .button {
            border: 0;
            border-radius: 8px;
            padding: 13px 18px;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform .16s ease, box-shadow .16s ease, background .16s ease;
        }

        button:hover, .button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
        }

        .danger-button {
            background: var(--danger);
            color: #fff;
        }

        .neutral-button {
            background: #e9eef6;
            color: var(--ink);
        }

        .fine-print {
            margin-top: 10px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.8;
        }

        code {
            direction: ltr;
            display: inline-block;
            color: var(--danger);
            background: rgba(180, 35, 24, .08);
            border-radius: 6px;
            padding: 2px 6px;
        }

        @media (max-width: 760px) {
            main { width: min(100% - 22px, 1080px); padding-top: 20px; }
            .topbar { display: block; }
            .badge { display: inline-block; margin-top: 12px; }
            .grid, .list { grid-template-columns: 1fr; }
            .actions { display: grid; }
            button, .button { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
@php
    $titles = [
        'preview' => 'معاينة قبل التصفير',
        'done' => 'تم تصفير قسم الطلبيات',
        'locked' => 'تم تنفيذ التصفير مسبقاً',
    ];
    $messages = [
        'preview' => 'هذه الصفحة تعرض البيانات التي سيتم حذفها فقط. لم يتم تنفيذ أي تغيير بعد.',
        'done' => 'تم مسح بيانات قسم الطلبيات وفصل ربط فواتير المبيعات الفورية المرتبطة بها.',
        'locked' => 'يوجد قفل تنفيذ سابق، لذلك لم يتم تشغيل التصفير مرة ثانية.',
    ];
    $after = $after ?? [];
@endphp
<main>
    <div class="topbar">
        <div>
            <h1>تصفير قسم الطلبيات</h1>
            <p class="subtitle">صفحة مخصصة لتجهيز قسم الطلبيات قبل بداية التشغيل الحقيقي، مع معاينة واضحة قبل التنفيذ.</p>
        </div>
        <div class="badge">Doctor Bike / Sales Orders</div>
    </div>

    <section class="notice {{ $status }}" aria-live="polite">
        <h2>{{ $titles[$status] ?? 'حالة غير معروفة' }}</h2>
        <p>{{ $messages[$status] ?? '' }}</p>
        @if(!empty($executedAt))
            <p class="fine-print">وقت التنفيذ: {{ $executedAt }}</p>
        @endif
    </section>

    <div class="grid" aria-label="عدادات قسم الطلبيات">
        @foreach($before as $table => $count)
            <article class="metric">
                <div class="label">{{ $table }}</div>
                <div class="value">{{ number_format((int) $count) }}</div>
                @if($status === 'done' && array_key_exists($table, $after))
                    <div class="fine-print">بعد التنفيذ: {{ number_format((int) $after[$table]) }}</div>
                @endif
            </article>
        @endforeach
    </div>

    <section class="section">
        <h2>ما الذي سيتم تصفيره؟</h2>
        <ul class="list">
            <li>الطلبيات الرئيسية</li>
            <li>أصناف الطلبيات والبكجات</li>
            <li>حركات الحالة والوسائط</li>
            <li>سجلات التسليم و Shiply المرتبطة بالطلبيات</li>
            <li>المرتجعات التابعة للطلبيات</li>
            <li>فصل ربط فواتير المبيعات الفورية عن الطلبيات فقط</li>
        </ul>
    </section>

    <section class="section">
        <h2>ما الذي لن يتغير؟</h2>
        <ul class="list">
            <li>المنتجات والمخزون</li>
            <li>الصناديق وأرصدة الصناديق</li>
            <li>سجلات الصناديق</li>
            <li>دفتر الديون والعملاء والتجار</li>
            <li>فواتير المبيعات الفورية نفسها</li>
            <li>باقي أقسام النظام</li>
        </ul>
    </section>

    @if($status === 'preview')
        <section class="section">
            <h2>تأكيد التنفيذ</h2>
            <p class="subtitle">بعد الضغط على الزر سيتم تشغيل الرابط مع <code>confirm=yes</code> وإنشاء قفل يمنع التنفيذ مرة ثانية بالغلط.</p>
            <form class="actions" method="GET" action="{{ route('test.purge-sales-orders') }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="confirm" value="yes">
                <button class="danger-button" type="submit" aria-label="تأكيد تصفير قسم الطلبيات">تصفير قسم الطلبيات الآن</button>
                <a class="button neutral-button" href="{{ route('test.purge-sales-orders', ['token' => $token]) }}">تحديث المعاينة</a>
            </form>
        </section>
    @endif

    @if($status === 'locked')
        <section class="section">
            <h2>قفل التنفيذ</h2>
            <p class="subtitle">ملف القفل: <code>{{ $lockPath }}</code></p>
            <p class="fine-print">لإعادة التنفيذ عمداً يمكن استخدام force=yes، لكن لا تستخدمه إلا إذا كنت متأكد أن هذا مطلوب.</p>
        </section>
    @endif
</main>
</body>
</html>
