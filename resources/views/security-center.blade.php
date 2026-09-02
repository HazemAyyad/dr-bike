<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مركز الأمان | Doctor Bike</title>
    <style>
        :root{--bg:#07111f;--panel:#101d2e;--line:#24364d;--muted:#94a8c1;--text:#eaf1fa;--green:#39d29b;--red:#ff6f7d;--amber:#f7bf58;--blue:#65a8ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,Arial,sans-serif}button,input,select{font:inherit}
        .wrap{width:min(1480px,calc(100% - 32px));margin:0 auto;padding:24px 0 50px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.top h1{margin:0;font-size:27px}.subtitle{color:var(--muted);margin-top:7px}.actions{display:flex;gap:10px;align-items:center}.ip{direction:ltr;display:inline-block;background:#16263a;border:1px solid var(--line);padding:9px 12px;border-radius:10px;color:#bcd0e8}.logout{background:transparent;color:#ffb6be;border:1px solid #6a3440;padding:9px 13px;border-radius:10px;cursor:pointer}
        .notice,.flash,.errors{padding:13px 15px;border-radius:12px;margin-bottom:17px}.notice{background:#182a3f;border:1px solid #31506d;color:#c8ddf3}.flash{background:#123a31;border:1px solid #246f59;color:#aaf0d7}.errors{background:#3b1720;border:1px solid #793242;color:#ffc0ca}.notice strong{color:white}
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:18px}.stat{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:17px}.stat .label{color:var(--muted);font-size:13px}.stat .value{font-size:29px;font-weight:bold;margin-top:8px}.green{color:var(--green)}.red{color:var(--red)}.amber{color:var(--amber)}.blue{color:var(--blue)}
        .panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;margin-bottom:18px;overflow:hidden}.panel-head{padding:17px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-head h2{font-size:18px;margin:0}.panel-body{padding:17px 18px}.form-grid{display:grid;grid-template-columns:1fr 2fr 180px auto;gap:10px}.field{width:100%;background:#091422;border:1px solid #30455f;color:white;border-radius:10px;padding:11px 12px;outline:none}.field:focus{border-color:var(--green)}.primary,.danger,.safe{border:0;border-radius:10px;padding:11px 15px;font-weight:bold;cursor:pointer}.primary{background:var(--green);color:#062218}.danger{background:#652b35;color:#ffdce1}.safe{background:#174d3e;color:#b9f7e0}
        .filters{display:flex;gap:9px;flex-wrap:wrap}.chip{color:#b9c9dd;text-decoration:none;border:1px solid var(--line);border-radius:9px;padding:8px 11px}.chip.active{background:#214a40;color:#baf6df;border-color:#347b68}.search{display:flex;gap:8px;min-width:min(440px,100%)}
        .table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:960px}th,td{text-align:right;padding:13px 14px;border-bottom:1px solid #1f3044;vertical-align:middle}th{color:#91a7c0;font-size:12px;background:#0c1827;position:sticky;top:0}td{font-size:13px}.ltr{direction:ltr;text-align:left;display:inline-block}.who strong{display:block;margin-bottom:4px}.muted{color:var(--muted);font-size:12px}.badge{display:inline-block;padding:5px 8px;border-radius:8px;font-size:12px;background:#1b2c40;color:#c1d3e7}.badge.ok{background:#153c33;color:#9aebce}.badge.bad{background:#4b222b;color:#ffc0c9}.badge.warn{background:#49391b;color:#ffe0a1}.route{max-width:270px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:ltr;text-align:left}.empty{text-align:center;color:var(--muted);padding:35px}.pagination{padding:14px 18px}.pagination nav>div:first-child{display:none}.pagination nav>div:last-child{display:flex;justify-content:space-between;align-items:center;gap:12px}.pagination a,.pagination span{color:#c4d3e5}.pagination a{text-decoration:none}.pagination svg{width:18px}.pagination nav span[aria-current=page] span{color:var(--green);font-weight:bold}
        @media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.top,.panel-head{align-items:stretch;flex-direction:column}.search{min-width:0}.actions{justify-content:space-between}}
    </style>
</head>
<body>
<main class="wrap">
    <header class="top">
        <div><h1>مركز أمان Doctor Bike</h1><div class="subtitle">نشاط تطبيق الإدارة وحظر Laravel — آخر تحديث {{ now()->format('Y-m-d H:i:s') }}</div></div>
        <div class="actions"><span class="ip" title="عنوانك الحالي">{{ $currentIp }}</span><form method="POST" action="{{ route('security-center.logout') }}">@csrf<button class="logout">تسجيل خروج</button></form></div>
    </header>

    <div class="notice"><strong>حدود العرض:</strong> تظهر هنا الطلبات التي وصلت إلى Laravel فقط. أي حظر من Hostinger أو WAF قبل وصول الطلب لن يظهر في هذه الصفحة. الحظر هنا يخص API والتطبيق، وتبقى صفحة مركز الأمان متاحة للإصلاح.</div>
    @if (! $tablesReady)<div class="errors">جداول مركز الأمان غير موجودة بعد. شغّل <span class="ltr">php artisan migrate --force</span> على السيرفر.</div>@endif
    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

    <section class="stats">
        <div class="stat"><div class="label">نشطون آخر 5 دقائق</div><div class="value green">{{ number_format($stats['online']) }}</div></div>
        <div class="stat"><div class="label">ظهروا خلال 24 ساعة</div><div class="value blue">{{ number_format($stats['today']) }}</div></div>
        <div class="stat"><div class="label">آخر استجابة خطأ</div><div class="value amber">{{ number_format($stats['errors']) }}</div></div>
        <div class="stat"><div class="label">IP محظور حالياً</div><div class="value red">{{ number_format($stats['blocked']) }}</div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>حظر عنوان IP</h2><span class="muted">لا يمكن حظر عنوانك الحالي</span></div>
        <div class="panel-body">
            <form class="form-grid" method="POST" action="{{ route('security-center.blocks.store') }}">
                @csrf
                <input class="field ltr" name="ip_address" placeholder="192.0.2.10" value="{{ old('ip_address') }}" required>
                <input class="field" name="reason" placeholder="سبب الحظر" value="{{ old('reason') }}">
                <select class="field" name="duration_minutes"><option value="60">ساعة</option><option value="1440">24 ساعة</option><option value="10080">7 أيام</option><option value="">دائم</option></select>
                <button class="danger" type="submit">حظر IP</button>
            </form>
        </div>
        <div class="table-wrap">
            <table><thead><tr><th>IP</th><th>الحالة</th><th>السبب</th><th>بداية الحظر</th><th>ينتهي</th><th></th></tr></thead><tbody>
            @forelse ($blocks as $block)
                @php($effective = $block->isEffective())
                <tr><td><span class="ltr">{{ $block->ip_address }}</span></td><td><span class="badge {{ $effective ? 'bad' : 'ok' }}">{{ $effective ? 'محظور' : 'غير فعال' }}</span></td><td>{{ $block->reason ?: '—' }}</td><td>{{ optional($block->blocked_at)->format('Y-m-d H:i') }}</td><td>{{ $block->expires_at ? $block->expires_at->format('Y-m-d H:i') : 'دائم' }}</td><td>@if($effective)<form method="POST" action="{{ route('security-center.blocks.unblock', $block) }}">@csrf<button class="safe" type="submit">إلغاء الحظر</button></form>@endif</td></tr>
            @empty<tr><td class="empty" colspan="6">لا توجد عناوين IP محظورة.</td></tr>@endforelse
            </tbody></table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="filters"><a class="chip {{ $filter === 'all' ? 'active' : '' }}" href="{{ route('security-center.index', ['search'=>$search]) }}">الكل</a><a class="chip {{ $filter === 'online' ? 'active' : '' }}" href="{{ route('security-center.index', ['filter'=>'online','search'=>$search]) }}">نشط الآن</a><a class="chip {{ $filter === 'errors' ? 'active' : '' }}" href="{{ route('security-center.index', ['filter'=>'errors','search'=>$search]) }}">أخطاء</a></div>
            <div class="actions">
                <form method="POST" action="{{ route('security-center.geolocation.refresh') }}">@csrf<button class="safe" type="submit" title="يحدد مواقع أحدث 15 عنواناً ويحفظها لمدة 30 يوماً">تحديث الدول والمدن</button></form>
                <form class="search" method="GET"><input type="hidden" name="filter" value="{{ $filter }}"><input class="field" name="search" value="{{ $search }}" placeholder="الاسم، IP، الدولة، المدينة أو المسار"><button class="primary">بحث</button></form>
            </div>
        </div>
        <div class="table-wrap"><table><thead><tr><th>المستخدم</th><th>IP</th><th>الدولة والمدينة</th><th>الشبكة</th><th>الجهاز</th><th>آخر مسار</th><th>الحالة</th><th>أول ظهور</th><th>آخر نشاط</th><th>الرصد</th><th></th></tr></thead><tbody>
        @forelse($visitors as $visitor)
            <tr>
                <td class="who"><strong>{{ $visitor->user_name ?: 'زائر/غير مسجل' }}</strong><span class="muted">{{ $visitor->user_type ?: 'guest' }}{{ $visitor->user_id ? ' · #'.$visitor->user_id : '' }}</span></td>
                <td><span class="ltr">{{ $visitor->ip_address }}</span></td>
                <td class="who"><strong>{{ $visitor->country_code ? $visitor->country_code.' · ' : '' }}{{ $visitor->country ?: 'غير محددة' }}</strong><span class="muted">{{ collect([$visitor->region, $visitor->city])->filter()->join('، ') ?: ($visitor->geo_error ? 'تعذر التحديد' : 'اضغط تحديث الدول والمدن') }}</span></td>
                <td><span class="muted">{{ $visitor->isp ?: '—' }}</span></td><td><span class="badge">{{ $visitor->device_type ?: 'غير معروف' }}</span></td><td><div class="route" title="{{ $visitor->last_route }}">{{ $visitor->last_method }} {{ $visitor->last_route }}</div></td>
                <td><span class="badge {{ $visitor->last_status >= 400 ? 'bad' : ($visitor->last_status >= 300 ? 'warn' : 'ok') }}">{{ $visitor->last_status }}</span></td><td>{{ optional($visitor->first_seen_at)->format('Y-m-d H:i') }}</td><td>{{ optional($visitor->last_seen_at)->diffForHumans() }}</td><td>{{ number_format($visitor->observations) }}</td>
                <td>@if($visitor->ip_address !== $currentIp)<form method="POST" action="{{ route('security-center.blocks.store') }}">@csrf<input type="hidden" name="ip_address" value="{{ $visitor->ip_address }}"><input type="hidden" name="reason" value="حظر من سجل زوار التطبيق"><input type="hidden" name="duration_minutes" value="1440"><button class="danger" type="submit">حظر 24 ساعة</button></form>@endif</td>
            </tr>
        @empty<tr><td class="empty" colspan="11">لا توجد زيارات مسجلة بعد. ستظهر البيانات بعد تشغيل migration ووصول طلبات جديدة من التطبيق.</td></tr>@endforelse
        </tbody></table></div>
        <div class="pagination">{{ $visitors->links() }}</div>
    </section>
</main>
</body>
</html>
