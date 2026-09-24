<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تصفير نقاط الموظفين | Doctor Bike</title>
    <style>
        :root{--bg:#07111f;--panel:#101d2e;--line:#24364d;--muted:#94a8c1;--text:#eaf1fa;--green:#39d29b;--red:#ff6f7d;--amber:#f7bf58;--blue:#65a8ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,Arial,sans-serif}button,input{font:inherit}.wrap{width:min(1200px,calc(100% - 32px));margin:0 auto;padding:24px 0 50px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.top h1{margin:0;font-size:27px}.subtitle{color:var(--muted);margin-top:7px}.actions,.search,.inline-form{display:flex;gap:9px;align-items:center}.link,.logout{display:inline-block;background:transparent;border:1px solid var(--line);border-radius:10px;padding:9px 13px;color:#c8d8eb;text-decoration:none;cursor:pointer}.logout{color:#ffb6be;border-color:#6a3440}.notice,.flash,.errors{padding:13px 15px;border-radius:12px;margin-bottom:17px}.notice{background:#182a3f;border:1px solid #31506d;color:#c8ddf3;line-height:1.8}.flash{background:#123a31;border:1px solid #246f59;color:#aaf0d7}.errors{background:#3b1720;border:1px solid #793242;color:#ffc0ca}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:18px}.stat{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:17px}.stat .label{color:var(--muted);font-size:13px}.stat .value{font-size:29px;font-weight:bold;margin-top:8px}.green{color:var(--green)}.red{color:var(--red)}.amber{color:var(--amber)}.blue{color:var(--blue)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;margin-bottom:18px;overflow:hidden}.panel-head{padding:17px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-head h2{font-size:18px;margin:0}.panel-body{padding:17px 18px}.field{width:100%;background:#091422;border:1px solid #30455f;color:white;border-radius:10px;padding:11px 12px;outline:none}.field:focus{border-color:var(--green)}.primary,.danger{border:0;border-radius:10px;padding:11px 15px;font-weight:bold;cursor:pointer}.primary{background:var(--green);color:#062218}.danger{background:#652b35;color:#ffdce1}.danger:disabled{opacity:.45;cursor:not-allowed}.bulk{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.hint,.muted{color:var(--muted);font-size:12px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:right;padding:13px 14px;border-bottom:1px solid #1f3044;vertical-align:middle}th{color:#91a7c0;font-size:12px;background:#0c1827}td{font-size:13px}.employee strong{display:block;margin-bottom:4px}.balance{direction:ltr;display:inline-block;font-size:17px;font-weight:bold}.badge{display:inline-block;padding:5px 8px;border-radius:8px;font-size:12px;background:#1b2c40;color:#c1d3e7}.badge.ok{background:#153c33;color:#9aebce}.badge.bad{background:#4b222b;color:#ffc0c9}.badge.warn{background:#49391b;color:#ffe0a1}.empty{text-align:center;color:var(--muted);padding:35px}.search{min-width:min(460px,100%)}
        @media(max-width:800px){.stats{grid-template-columns:repeat(2,1fr)}.top,.panel-head{align-items:stretch;flex-direction:column}.actions{flex-wrap:wrap}.search{min-width:0}.bulk{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="wrap">
    <header class="top">
        <div><h1>تصفير نقاط الموظفين</h1><div class="subtitle">أداة إدارية محمية ضمن مركز الأمان</div></div>
        <div class="actions">
            <a class="link" href="{{ route('security-center.index') }}">مركز الأمان</a>
            <a class="link" href="{{ route('security-center.accounting.index') }}">سلامة المحاسبة</a>
            <form method="POST" action="{{ route('security-center.logout') }}">@csrf<button class="logout" type="submit">تسجيل خروج</button></form>
        </div>
    </header>

    <div class="notice"><strong>كيف يعمل التصفير؟</strong> لا تُحذف حركات النقاط أو صور الإثبات السابقة. ينشئ النظام حركة تسوية معاكسة تجعل الرصيد الحالي صفرًا وتبقى العملية ظاهرة في سجل الموظف وتقارير الشهر الحالي.</div>
    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

    <section class="stats">
        <div class="stat"><div class="label">الموظفون المعروضون</div><div class="value blue">{{ number_format($stats['employees']) }}</div></div>
        <div class="stat"><div class="label">رصيد غير صفري</div><div class="value amber">{{ number_format($stats['non_zero']) }}</div></div>
        <div class="stat"><div class="label">رصيد موجب</div><div class="value green">{{ number_format($stats['positive']) }}</div></div>
        <div class="stat"><div class="label">رصيد سالب</div><div class="value red">{{ number_format($stats['negative']) }}</div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>تصفير جميع الموظفين</h2><span class="muted">يشمل الجميع حتى عند استخدام البحث</span></div>
        <div class="panel-body">
            <form class="bulk" method="POST" action="{{ route('security-center.employee-points.reset-all') }}" onsubmit="return confirm('سيتم تصفير أرصدة جميع الموظفين غير الصفرية بحركات تسوية موثقة. هل تريد المتابعة؟')">
                @csrf
                <div>
                    <input class="field" name="confirmation" value="{{ old('confirmation') }}" placeholder="اكتب: تصفير الجميع" autocomplete="off" required>
                    <div class="hint" style="margin-top:7px">اكتب العبارة «تصفير الجميع» حرفيًا لتفعيل العملية.</div>
                </div>
                <button class="danger" type="submit">تصفير نقاط الجميع</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>الموظفون والأرصدة الحالية</h2>
            <form class="search" method="GET">
                <input class="field" name="search" value="{{ $search }}" placeholder="ابحث بالاسم، المسمى أو رقم الموظف">
                <button class="primary" type="submit">بحث</button>
                @if ($search !== '')<a class="link" href="{{ route('security-center.employee-points.index') }}">مسح</a>@endif
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>الموظف</th><th>المسمى</th><th>الحالة</th><th>الرصيد الحالي</th><th>الإجراء</th></tr></thead>
                <tbody>
                @forelse ($employees as $employee)
                    @php($name = $employee->user?->name ?? 'موظف #'.$employee->id)
                    <tr>
                        <td class="employee"><strong>{{ $name }}</strong><span class="muted">#{{ $employee->id }}</span></td>
                        <td>{{ $employee->job_title ?: '—' }}</td>
                        <td><span class="badge {{ $employee->is_suspended ? 'warn' : 'ok' }}">{{ $employee->is_suspended ? 'موقوف' : 'فعال' }}</span></td>
                        <td><span class="balance {{ $employee->current_points > 0 ? 'green' : ($employee->current_points < 0 ? 'red' : '') }}">{{ $employee->current_points > 0 ? '+' : '' }}{{ number_format($employee->current_points) }}</span></td>
                        <td>
                            <form method="POST" action="{{ route('security-center.employee-points.reset', $employee) }}" onsubmit="return confirm(@js('هل تريد تصفير رصيد '.$name.' الحالي ('.number_format($employee->current_points).' نقطة)؟'))">
                                @csrf
                                <input type="hidden" name="confirmed" value="1">
                                <button class="danger" type="submit" {{ $employee->current_points === 0 ? 'disabled' : '' }}>تصفير الموظف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="5">لا يوجد موظفون مطابقون للبحث.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
