<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تنظيف نقاط الموظفين | Doctor Bike</title>
    <style>
        :root{--bg:#07111f;--panel:#101d2e;--line:#24364d;--muted:#94a8c1;--text:#eaf1fa;--green:#39d29b;--red:#ff6f7d;--amber:#f7bf58;--blue:#65a8ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,Arial,sans-serif}button,input{font:inherit}.wrap{width:min(1200px,calc(100% - 32px));margin:0 auto;padding:24px 0 50px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.top h1{margin:0;font-size:27px}.subtitle{color:var(--muted);margin-top:7px}.actions,.search{display:flex;gap:9px;align-items:center}.link,.logout{display:inline-block;background:transparent;border:1px solid var(--line);border-radius:10px;padding:9px 13px;color:#c8d8eb;text-decoration:none;cursor:pointer}.logout{color:#ffb6be;border-color:#6a3440}.notice,.flash,.errors{padding:13px 15px;border-radius:12px;margin-bottom:17px}.notice{background:#3b2517;border:1px solid #80502c;color:#ffd8ac;line-height:1.8}.flash{background:#123a31;border:1px solid #246f59;color:#aaf0d7}.errors{background:#3b1720;border:1px solid #793242;color:#ffc0ca}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:18px}.stat{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:17px}.stat .label{color:var(--muted);font-size:13px}.stat .value{font-size:29px;font-weight:bold;margin-top:8px}.green{color:var(--green)}.red{color:var(--red)}.amber{color:var(--amber)}.blue{color:var(--blue)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;margin-bottom:18px;overflow:hidden}.panel-head{padding:17px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-head h2{font-size:18px;margin:0}.panel-body{padding:17px 18px}.field{width:100%;background:#091422;border:1px solid #30455f;color:white;border-radius:10px;padding:11px 12px;outline:none}.field:focus{border-color:var(--red)}.primary,.danger{border:0;border-radius:10px;padding:11px 15px;font-weight:bold;cursor:pointer}.primary{background:var(--green);color:#062218}.danger{background:#7a2835;color:#ffe1e5}.danger:disabled{opacity:.4;cursor:not-allowed}.bulk{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.hint,.muted{color:var(--muted);font-size:12px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:880px}th,td{text-align:right;padding:13px 14px;border-bottom:1px solid #1f3044;vertical-align:middle}th{color:#91a7c0;font-size:12px;background:#0c1827}td{font-size:13px}.employee strong{display:block;margin-bottom:4px}.balance{direction:ltr;display:inline-block;font-size:17px;font-weight:bold}.badge{display:inline-block;padding:5px 8px;border-radius:8px;font-size:12px;background:#1b2c40;color:#c1d3e7}.badge.ok{background:#153c33;color:#9aebce}.badge.warn{background:#49391b;color:#ffe0a1}.empty{text-align:center;color:var(--muted);padding:35px}.search{min-width:min(460px,100%)}
        @media(max-width:800px){.stats{grid-template-columns:repeat(2,1fr)}.top,.panel-head{align-items:stretch;flex-direction:column}.actions{flex-wrap:wrap}.search{min-width:0}.bulk{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="wrap">
    <header class="top">
        <div><h1>تنظيف نقاط الموظفين</h1><div class="subtitle">حذف نهائي لبيانات النقاط التجريبية من مركز الأمان</div></div>
        <div class="actions">
            <a class="link" href="{{ route('security-center.index') }}">مركز الأمان</a>
            <a class="link" href="{{ route('security-center.accounting.index') }}">سلامة المحاسبة</a>
            <form method="POST" action="{{ route('security-center.logout') }}">@csrf<button class="logout" type="submit">تسجيل خروج</button></form>
        </div>
    </header>

    <div class="notice"><strong>تنبيه: هذا حذف نهائي وليس تصفيرًا.</strong> تُحذف حركات النقاط وصور/فيديوهات الإثبات وإشعارات وسجل نشاط النقاط. لا يمكن استعادة البيانات من هذه الصفحة بعد تنفيذ الحذف، لذلك خذ نسخة احتياطية قبل حذف الجميع.</div>
    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

    <section class="stats">
        <div class="stat"><div class="label">الموظفون المعروضون</div><div class="value blue">{{ number_format($stats['employees']) }}</div></div>
        <div class="stat"><div class="label">لديهم سجل نقاط</div><div class="value amber">{{ number_format($stats['with_history']) }}</div></div>
        <div class="stat"><div class="label">حركات النقاط</div><div class="value red">{{ number_format($stats['logs']) }}</div></div>
        <div class="stat"><div class="label">ملفات الإثبات</div><div class="value green">{{ number_format($stats['evidence']) }}</div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>حذف نقاط جميع الموظفين نهائيًا</h2><span class="muted">يشمل الجميع حتى عند استخدام البحث</span></div>
        <div class="panel-body">
            <form class="bulk" method="POST" action="{{ route('security-center.employee-points.destroy-all') }}" onsubmit="return confirm('هذا حذف نهائي لجميع حركات النقاط وملفات الإثبات المرتبطة بها. هل أخذت نسخة احتياطية وتريد المتابعة؟')">
                @csrf
                @method('DELETE')
                <div>
                    <input class="field" name="confirmation" value="{{ old('confirmation') }}" placeholder="اكتب: حذف جميع النقاط نهائيا" autocomplete="off" required>
                    <div class="hint" style="margin-top:7px">اكتب العبارة «حذف جميع النقاط نهائيا» حرفيًا لتفعيل العملية.</div>
                </div>
                <button class="danger" type="submit">حذف نقاط الجميع نهائيًا</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>سجلات الموظفين الحالية</h2>
            <form class="search" method="GET">
                <input class="field" name="search" value="{{ $search }}" placeholder="ابحث بالاسم، المسمى أو رقم الموظف">
                <button class="primary" type="submit">بحث</button>
                @if ($search !== '')<a class="link" href="{{ route('security-center.employee-points.index') }}">مسح</a>@endif
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>الموظف</th><th>المسمى</th><th>الحالة</th><th>الرصيد</th><th>عدد الحركات</th><th>الإثباتات</th><th>الإجراء</th></tr></thead>
                <tbody>
                @forelse ($employees as $employee)
                    @php($name = $employee->user?->name ?? 'موظف #'.$employee->id)
                    <tr>
                        <td class="employee"><strong>{{ $name }}</strong><span class="muted">#{{ $employee->id }}</span></td>
                        <td>{{ $employee->job_title ?: '—' }}</td>
                        <td><span class="badge {{ $employee->is_suspended ? 'warn' : 'ok' }}">{{ $employee->is_suspended ? 'موقوف' : 'فعال' }}</span></td>
                        <td><span class="balance {{ $employee->current_points > 0 ? 'green' : ($employee->current_points < 0 ? 'red' : '') }}">{{ $employee->current_points > 0 ? '+' : '' }}{{ number_format($employee->current_points) }}</span></td>
                        <td>{{ number_format($employee->points_logs_count) }}</td>
                        <td>{{ number_format($employee->evidence_count) }}</td>
                        <td>
                            <form method="POST" action="{{ route('security-center.employee-points.destroy', $employee) }}" onsubmit="return confirm(@js('سيتم حذف جميع حركات وإثباتات نقاط '.$name.' نهائيًا، وليس تصفير الرصيد فقط. هل تريد المتابعة؟'))">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="confirmed" value="1">
                                <button class="danger" type="submit" {{ $employee->points_logs_count === 0 ? 'disabled' : '' }}>حذف السجل نهائيًا</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="7">لا يوجد موظفون مطابقون للبحث.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
