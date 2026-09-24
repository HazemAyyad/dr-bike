<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سلامة المحاسبة | Doctor Bike</title>
    <style>
        :root{--bg:#07111f;--panel:#101d2e;--line:#273a52;--text:#eaf1fa;--muted:#94a8c1;--green:#39d29b;--amber:#f7bf58;--red:#ff6f7d;--blue:#65a8ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,Arial,sans-serif}button,input{font:inherit}.wrap{width:min(1480px,calc(100% - 28px));margin:auto;padding:24px 0 50px}.top,.actions,.panel-head,.status-row{display:flex;align-items:center;gap:12px}.top{justify-content:space-between;margin-bottom:20px}.top h1{margin:0;font-size:27px}.subtitle,.muted{color:var(--muted)}.subtitle{margin-top:7px}.actions{flex-wrap:wrap}.link,.button{border-radius:10px;padding:10px 14px;text-decoration:none;font-weight:bold}.link{color:#c9daee;border:1px solid var(--line)}.button{border:0;cursor:pointer}.primary{background:var(--green);color:#062218}.danger{background:#6b2733;color:#ffe4e8}.panel{background:var(--panel);border:1px solid var(--line);border-radius:17px;margin-bottom:17px;overflow:hidden}.panel-head{justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:18px}.panel-body{padding:17px 18px}.notice,.error,.success,.warning{padding:13px 15px;border-radius:11px;margin-bottom:15px}.notice{background:#182a3f;border:1px solid #31506d;color:#c8ddf3}.error{background:#3b1720;border:1px solid #793242;color:#ffc0ca}.success{background:#123a31;border:1px solid #246f59;color:#aaf0d7}.warning{background:#463719;border:1px solid #82642b;color:#ffe3a5}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.step,.card{background:#0b1726;border:1px solid var(--line);border-radius:13px;padding:15px}.step strong,.card strong{display:block;margin-bottom:8px}.step span,.card span{color:var(--muted);font-size:13px;line-height:1.7}.step.safe{border-color:#286a58}.step.risk{border-color:#74404a}.status-row{justify-content:space-between}.badge{display:inline-block;border-radius:8px;padding:5px 9px;font-size:12px;font-weight:bold}.pass,.repairable,.ready,.projected{background:#153c33;color:#9aebce}.warning-badge,.skipped,.blocked{background:#49391b;color:#ffe0a1}.error-badge,.still_failing,.failed{background:#4b222b;color:#ffc0c9}.already_valid_or_stale,.already_posted,.already_depreciated{background:#173a59;color:#b9dcff}.summary{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.metric{background:#0b1726;border:1px solid var(--line);border-radius:12px;padding:13px}.metric .label{color:var(--muted);font-size:12px;min-height:31px}.metric .value{font-size:25px;font-weight:bold;margin-top:5px}.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px}.repair-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:10px}.field{width:100%;background:#081321;border:1px solid #314861;color:white;border-radius:10px;padding:11px 12px;outline:none}.field:focus{border-color:var(--green)}label{display:block;color:#b9cce2;font-size:13px;margin-bottom:6px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:930px}th,td{text-align:right;padding:12px 14px;border-bottom:1px solid #203247;vertical-align:top;font-size:13px}th{background:#0c1827;color:#91a7c0;font-size:12px}.ltr{direction:ltr;text-align:left;display:inline-block}.code{font-family:Consolas,monospace}.ids{max-width:420px;word-break:break-word}.details{margin:5px 0 0;padding-right:18px;color:#c7d6e8}.migration-list{display:flex;flex-wrap:wrap;gap:8px}.migration-item{background:#0b1726;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:12px}.migration-item.ok{border-color:#286a58;color:#aaf0d7}.migration-item.bad{border-color:#743541;color:#ffc0ca}.danger-zone{border-color:#743541}.help{line-height:1.85}.empty{text-align:center;color:var(--muted);padding:28px}.check-name{font-weight:bold}.small{font-size:12px}.nowrap{white-space:nowrap}
        @media(max-width:960px){.grid,.steps{grid-template-columns:1fr}.summary{grid-template-columns:repeat(2,1fr)}.form-grid,.repair-grid{grid-template-columns:1fr}.top,.panel-head{align-items:stretch;flex-direction:column}}
    </style>
</head>
<body>
@php
    $summaryLabels = [
        'total_failures' => 'إجمالي الأخطاء المفتوحة',
        'repairable' => 'قابلة مبدئيًا للإصلاح',
        'successfully_repaired' => 'تم إصلاحها',
        'still_failing' => 'ما زالت فاشلة',
        'already_valid_or_stale' => 'قديمة/صالحة مسبقًا',
        'skipped' => 'تم تخطيها',
    ];
    $prepaymentSummaryLabels = [
        'total' => 'إجمالي العربونات المصنفة', 'already_posted' => 'مرحلة مسبقًا',
        'ready' => 'جاهزة للترحيل', 'projected' => 'تم ترحيلها',
        'failed' => 'فشلت', 'skipped' => 'محجوبة/متخطاة',
    ];
    $depreciationSummaryLabels = [
        'total' => 'إجمالي الأصول', 'eligible' => 'مستحقة',
        'already_depreciated' => 'مُهلكة لهذا الشهر', 'requires_review' => 'تحتاج مراجعة',
        'skipped' => 'غير منفذة', 'depreciation_amount' => 'قيمة الإهلاك المتوقع',
    ];
    $debtBalanceSummaryLabels = [
        'total_issues' => 'الفروقات المكتشفة', 'affected_groups' => 'أشخاص/عملات متأثرة',
        'repaired_rows' => 'صفوف balance_after المصححة', 'remaining_issues' => 'الفروقات المتبقية',
        'accounting_mismatches' => 'فروقات GL المتبقية',
    ];
    $checkLabels = [
        'journal_balance' => 'توازن القيود', 'product_sales' => 'مبيعات المنتجات',
        'profit_sales' => 'البيع الربحي/الخدمات', 'purchases' => 'المشتريات والمدفوعات',
        'purchase_returns' => 'مرتجعات المشتريات', 'sales_returns' => 'مرتجعات المبيعات',
        'maintenance' => 'الصيانة', 'maintenance_prepayments' => 'عربونات الصيانة', 'expenses' => 'المصروفات', 'assets' => 'الأصول والإهلاك',
        'cashboxes' => 'الصناديق', 'party_dimensions' => 'ربط الذمم بالأطراف',
        'debt_running_balance' => 'الرصيد المتسلسل للديون', 'source_debt_integrity' => 'سلامة مصادر الديون',
        'purchase_payment_source_identity' => 'هوية مصدر دفعات الشراء',
        'manual_debt_box' => 'صندوق الحركة اليدوية', 'debt_box_currency' => 'عملة الدين والصندوق',
        'negative_boxes' => 'الصناديق السالبة', 'box_unclassified_adjustments' => 'تصنيف تعديلات الصندوق',
        'clearing_balance' => 'رصيد الحساب المعلّق حسب العملة',
        'cash_reconciliation' => 'مطابقة النقد حسب الصندوق', 'party_reconciliation' => 'مطابقة الذمم حسب الطرف',
        'source_linked_manual_mutation' => 'تعديل يدوي لحركة مرتبطة بمصدر',
        'inventory_cost_integrity' => 'سلامة FIFO', 'projection_failures' => 'أخطاء الترحيل المفتوحة',
    ];
@endphp
<main class="wrap">
    <header class="top">
        <div><h1>سلامة المحاسبة</h1><div class="subtitle">معاينة أخطاء الترحيل وفحص القيود قبل اتخاذ أي إجراء</div></div>
        <div class="actions"><a class="link" href="{{ route('security-center.index') }}">مركز الأمان</a><form method="POST" action="{{ route('security-center.logout') }}">@csrf<button class="link" type="submit" style="background:transparent">تسجيل خروج</button></form></div>
    </header>

    <section class="steps">
        <div class="step"><strong>1. Migration يدوي</strong><span>الصفحة لا تشغّل Migration. أنت تشغّل <span class="ltr code">php artisan migrate --force</span> مرة واحدة بعد النسخة الاحتياطية.</span></div>
        <div class="step safe"><strong>2. معاينة وفحص — قراءة فقط</strong><span>تعرض الأخطاء، سببها، القابل للإصلاح، وفحوص PASS/WARNING/ERROR. لا تنشئ قيدًا ولا تعدّل Failure أو FIFO.</span></div>
        <div class="step risk"><strong>3. إصلاح فعلي — بتأكيد</strong><span>يعيد محاولة الأخطاء المفتوحة فقط. قد ينشئ قيودًا صحيحة ويحدّث FIFO الموثوق و resolved_at، مع منع التكرار.</span></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>حالة تجهيز المحاسبة</h2><span class="badge {{ $migrationStatus['ready'] ? 'pass' : 'error-badge' }}">{{ $migrationStatus['ready'] ? 'جاهزة' : 'Migration مطلوبة' }}</span></div>
        <div class="panel-body">
            <div class="migration-list">
                @foreach($migrationStatus['tables'] as $table => $exists)<span class="migration-item {{ $exists ? 'ok' : 'bad' }}"><span class="ltr code">{{ $table }}</span> · {{ $exists ? 'موجود' : 'مفقود' }}</span>@endforeach
                <span class="migration-item {{ $migrationStatus['service_revenue'] ? 'ok' : 'bad' }}"><span class="ltr code">service_revenue</span> · {{ $migrationStatus['service_revenue'] ? 'موجود' : 'مفقود' }}</span>
                <span class="migration-item {{ $migrationStatus['maintenance_payment_stage'] ? 'ok' : 'bad' }}"><span class="ltr code">maintenance_payments.payment_stage</span> · {{ $migrationStatus['maintenance_payment_stage'] ? 'موجود' : 'مفقود' }}</span>
                <span class="migration-item {{ $migrationStatus['asset_depreciation_fields'] ? 'ok' : 'bad' }}">حقول الإهلاك الشهري · {{ $migrationStatus['asset_depreciation_fields'] ? 'موجودة' : 'مفقودة' }}</span>
                <span class="migration-item {{ $migrationStatus['debt_ledger_safety'] ? 'ok' : 'bad' }}">حماية دفتر الديون وحقول Box Logs · {{ $migrationStatus['debt_ledger_safety'] ? 'موجودة' : 'مفقودة' }}</span>
                <span class="migration-item {{ $migrationStatus['cash_difference_accounts'] ? 'ok' : 'bad' }}">حسابا زيادة/عجز الصندوق · {{ $migrationStatus['cash_difference_accounts'] ? 'موجودان' : 'مفقودان' }}</span>
            </div>
        </div>
    </section>

    @if($errors->any())<div class="error"><strong>لم يتم تنفيذ الإجراء:</strong> {{ $errors->first() }}</div>@endif
    @if($mode === 'preview')<div class="success">اكتملت المعاينة بوضع القراءة فقط. لم يتم تعديل أي صف في قاعدة البيانات.</div>@endif
    @if($mode === 'repair')<div class="success">اكتملت محاولة الإصلاح. راجع نتيجة التنفيذ والأخطاء المتبقية والفحص اللاحق أدناه.</div>@endif
    @if($mode === 'prepayment_sync')<div class="success">اكتمل ترحيل عربونات الصيانة القابلة للترحيل. لم يتم تعديل أرصدة الصناديق أو مبالغ الصيانة.</div>@endif
    @if($mode === 'depreciation_run')<div class="success">اكتمل تنفيذ إهلاك الشهر المحدد. راجع عدد الأصول والقيود والتحذيرات أدناه.</div>@endif
    @if($mode === 'debt_balance_repair')<div class="success">اكتمل تصحيح الحقل المشتق balance_after فقط. لم تتغير المبالغ أو الصناديق أو القيود المحاسبية.</div>@endif

    <section class="panel">
        <div class="panel-head"><h2>المعاينة + فحص السلامة</h2><span class="muted">فلتر التاريخ يخص فحص السلامة فقط؛ أخطاء الترحيل تعرض كل السجلات المفتوحة.</span></div>
        <div class="panel-body">
            <form class="form-grid" method="POST" action="{{ route('security-center.accounting.inspect') }}">
                @csrf
                <div><label>من تاريخ (اختياري)</label><input class="field ltr" type="date" name="from" value="{{ old('from', $from) }}"></div>
                <div><label>إلى تاريخ (اختياري)</label><input class="field ltr" type="date" name="to" value="{{ old('to', $to) }}"></div>
                <div><label>شهر الإهلاك للمعاينة</label><input class="field ltr" type="month" name="depreciation_period" value="{{ old('depreciation_period', $depreciationPeriod) }}"></div>
                <div style="align-self:end"><button class="button primary" type="submit">معاينة آمنة</button></div>
            </form>
        </div>
    </section>

    @if($repairResult)
        <section class="panel">
            <div class="panel-head"><h2>{{ $mode === 'repair' ? 'نتيجة الإصلاح الفعلي' : 'نتيجة معاينة أخطاء الترحيل' }}</h2><span class="badge {{ $mode === 'repair' ? 'warning-badge' : 'pass' }}">{{ $mode === 'repair' ? 'تم التنفيذ' : 'قراءة فقط' }}</span></div>
            <div class="panel-body"><div class="summary">@foreach($summaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($repairResult['summary'][$key] ?? 0) }}</div></div>@endforeach</div></div>
            <div class="table-wrap"><table><thead><tr><th>Failure</th><th>المصدر</th><th>الحالة</th><th>قابل للإصلاح؟</th><th>الخطأ المسجل</th><th>التقييم الحالي</th><th>مشاكل البيانات</th></tr></thead><tbody>
            @forelse($repairResult['items'] as $item)
                <tr>
                    <td>#{{ $item['failure_id'] }}</td><td><span class="ltr code">{{ $item['source_type'] }}:{{ $item['source_id'] }}</span></td>
                    <td><span class="badge {{ $item['status'] }}">{{ $item['status'] }}</span></td><td>{{ $item['repairable'] ? 'نعم' : 'لا' }}</td>
                    <td>{{ $item['previous_error'] ?: '—' }}</td><td>{{ $item['message'] }}</td>
                    <td>@if(!empty($item['issues']))<ul class="details">@foreach($item['issues'] as $issue)<li><span class="ltr code">{{ $issue['code'] ?? 'issue' }}</span> — {{ $issue['message'] ?? '' }}</li>@endforeach</ul>@else — @endif</td>
                </tr>
            @empty<tr><td class="empty" colspan="7">لا توجد Accounting Projection Failures مفتوحة.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    @if($remainingResult)
        <section class="panel"><div class="panel-head"><h2>المتبقي بعد الإصلاح</h2><span class="muted">معاينة قراءة فقط بعد التنفيذ</span></div><div class="panel-body"><div class="summary">@foreach($summaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($remainingResult['summary'][$key] ?? 0) }}</div></div>@endforeach</div></div></section>
    @endif

    @if($prepaymentResult)
        <section class="panel">
            <div class="panel-head"><h2>عربونات الصيانة</h2><span class="badge {{ $mode === 'prepayment_sync' ? 'warning-badge' : 'pass' }}">{{ $mode === 'prepayment_sync' ? 'نتيجة التنفيذ' : 'معاينة فقط' }}</span></div>
            <div class="panel-body"><div class="summary">@foreach($prepaymentSummaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($prepaymentResult['summary'][$key] ?? 0, $key === 'depreciation_amount' ? 2 : 0) }}</div></div>@endforeach</div></div>
            <div class="table-wrap"><table><thead><tr><th>Payment</th><th>الصيانة</th><th>المبلغ</th><th>الصندوق</th><th>الحالة</th><th>ماذا سيحدث؟</th></tr></thead><tbody>
            @forelse($prepaymentResult['items'] as $item)
                <tr><td>#{{ $item['payment_id'] }}</td><td>#{{ $item['maintenance_id'] }}</td><td>{{ number_format($item['amount'], 2) }} {{ $item['currency'] }}</td><td>{{ $item['box_id'] ? '#'.$item['box_id'] : '—' }}</td><td><span class="badge {{ $item['status'] }}">{{ $item['status'] }}</span></td><td>{{ $item['message'] }}</td></tr>
            @empty<tr><td class="empty" colspan="6">لا توجد عربونات صيانة مصنفة تحتاج عرضًا.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    @if($remainingPrepaymentResult)
        <section class="panel"><div class="panel-head"><h2>حالة العربونات بعد التنفيذ</h2><span class="muted">معاينة قراءة فقط</span></div><div class="panel-body"><div class="summary">@foreach($prepaymentSummaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($remainingPrepaymentResult['summary'][$key] ?? 0) }}</div></div>@endforeach</div></div></section>
    @endif

    @if($depreciationResult)
        <section class="panel">
            <div class="panel-head"><h2>إهلاك الأصول — <span class="ltr code">{{ $depreciationResult['period'] }}</span></h2><span class="badge {{ $mode === 'depreciation_run' ? 'warning-badge' : 'pass' }}">{{ $mode === 'depreciation_run' ? 'بعد التنفيذ' : 'معاينة فقط' }}</span></div>
            @if($depreciationExecution)<div class="panel-body"><div class="success">نُفذ الإهلاك على {{ number_format($depreciationExecution['execution']['processed'] ?? 0) }} أصل بقيمة متوقعة {{ number_format($depreciationExecution['before']['summary']['depreciation_amount'] ?? 0, 2) }}، وتم تخطي {{ number_format($depreciationExecution['execution']['skipped'] ?? 0) }} أصل. عدد التحذيرات: {{ number_format(count($depreciationExecution['execution']['warnings'] ?? [])) }}.</div></div>@endif
            <div class="panel-body"><div class="summary">@foreach($depreciationSummaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($depreciationResult['summary'][$key] ?? 0, $key === 'depreciation_amount' ? 2 : 0) }}</div></div>@endforeach</div></div>
            <div class="table-wrap"><table><thead><tr><th>الأصل</th><th>القيمة الحالية</th><th>العمر</th><th>المستخدم/المتبقي</th><th>إهلاك الفترة</th><th>بعد الإهلاك</th><th>الحالة</th><th>الملاحظة</th></tr></thead><tbody>
            @forelse($depreciationResult['items'] as $item)
                <tr><td>#{{ $item['asset_id'] }} — {{ $item['name'] }}</td><td>{{ number_format($item['current_book_value'], 2) }}</td><td>{{ $item['useful_life_months'] }} شهر</td><td>{{ $item['used_periods'] }} / {{ $item['remaining_periods'] }}</td><td>{{ number_format($item['depreciation_amount'], 2) }}</td><td>{{ number_format($item['value_after'], 2) }}</td><td><span class="badge {{ $item['status'] }}">{{ $item['status'] }}</span></td><td>{{ $item['warning'] ?: ($item['skip_reason'] ?: '—') }}</td></tr>
            @empty<tr><td class="empty" colspan="8">لا توجد أصول لعرضها.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    @if($debtBalanceResult)
        <section class="panel">
            <div class="panel-head"><h2>الرصيد المتسلسل لدفتر الديون</h2><span class="badge {{ $mode === 'debt_balance_repair' ? 'warning-badge' : 'pass' }}">{{ $mode === 'debt_balance_repair' ? 'بعد الإصلاح' : 'معاينة فقط' }}</span></div>
            <div class="panel-body">
                <div class="notice help">المعاينة تقارن <span class="ltr code">balance_after</span> المخزن بالرصيد المتوقع حسب <span class="ltr code">transaction_date ثم id</span> لكل شخص وعملة. الإصلاح لا يغيّر المبلغ أو النوع أو العملة أو الصندوق أو المصدر أو النقد أو القيود.</div>
                <div class="summary">@foreach($debtBalanceSummaryLabels as $key => $label)<div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ number_format($debtBalanceResult['summary'][$key] ?? 0) }}</div></div>@endforeach</div>
            </div>
            <div class="table-wrap"><table><thead><tr><th>الطرف</th><th>العملة</th><th>الحركة</th><th>المخزن</th><th>المتوقع</th><th>الفرق</th></tr></thead><tbody>
            @forelse($debtBalanceResult['items'] as $item)
                <tr><td>{{ $item['person_type'] === 'customer' ? 'زبون' : 'مورد' }} #{{ $item['person_id'] }}</td><td>{{ $item['currency'] }}</td><td>#{{ $item['transaction_id'] }}</td><td>{{ number_format($item['stored_balance'], 2) }}</td><td>{{ number_format($item['expected_balance'], 2) }}</td><td>{{ number_format($item['difference'], 2) }}</td></tr>
            @empty<tr><td class="empty" colspan="6">كل أرصدة دفتر الديون المتسلسلة صحيحة.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    @if($integrityResult)
        <section class="panel">
            <div class="panel-head"><h2>نتيجة فحص السلامة</h2><div class="actions"><span class="badge pass">PASS {{ $integrityResult['summary']['pass'] }}</span><span class="badge warning-badge">WARNING {{ $integrityResult['summary']['warning'] }}</span><span class="badge error-badge">ERROR {{ $integrityResult['summary']['error'] }}</span></div></div>
            <div class="table-wrap"><table><thead><tr><th>النتيجة</th><th>الفحص</th><th>المعنى</th><th>IDs تحتاج مراجعة</th></tr></thead><tbody>
            @foreach($integrityResult['checks'] as $check)<tr><td><span class="badge {{ strtolower($check['status']) === 'pass' ? 'pass' : (strtolower($check['status']) === 'warning' ? 'warning-badge' : 'error-badge') }}">{{ $check['status'] }}</span></td><td class="check-name">{{ $checkLabels[$check['name']] ?? $check['name'] }}</td><td>{{ $check['message'] }}</td><td class="ids ltr">{{ empty($check['ids']) ? '—' : collect($check['ids'])->take(100)->implode(', ') }}{{ count($check['ids']) > 100 ? ' …' : '' }}</td></tr>@endforeach
            </tbody></table></div>
        </section>
    @endif

    <section class="panel danger-zone">
        <div class="panel-head"><h2>إصلاح الرصيد المتسلسل لدفتر الديون</h2><span class="badge error-badge">يعدل balance_after فقط</span></div>
        <div class="panel-body">
            <div class="warning help"><strong>ما الذي سيتغير؟</strong> يعيد حساب الحقل المشتق <span class="ltr code">debt_transactions.balance_after</span> حسب التاريخ وID للشخص والعملة المتأثرة فقط. لا يغيّر المبالغ أو الصناديق أو النقد أو القيود، ثم يعرض نتيجة المطابقة المحاسبية بدون اختراع أي قيد.</div>
            <form class="repair-grid" method="POST" action="{{ route('security-center.accounting.debt-ledger.balances.repair') }}" onsubmit="return confirm('هل راجعت معاينة فروقات دفتر الديون والنسخة الاحتياطية وتريد تصحيح balance_after فقط؟')">
                @csrf
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                <div><label>رمز إصلاح المحاسبة</label><input class="field ltr" type="password" name="access_token" autocomplete="current-password" required></div>
                <div><label>اكتب بالضبط: إصلاح أرصدة دفتر الديون</label><input class="field" name="confirmation" autocomplete="off" placeholder="إصلاح أرصدة دفتر الديون" required></div>
                <div style="align-self:end"><button class="button danger" type="submit" {{ $migrationStatus['ready'] && $webRepairEnabled ? '' : 'disabled' }}>تنفيذ إصلاح balance_after</button></div>
            </form>
        </div>
    </section>

    <section class="panel danger-zone">
        <div class="panel-head"><h2>ترحيل عربونات الصيانة</h2><span class="badge error-badge">يضيف قيودًا محاسبية</span></div>
        <div class="panel-body">
            <div class="warning help"><strong>ما الذي سيتغير؟</strong> لكل عربون مصنف وجاهز: مدين Cash ودائن Customer Deposits. لا يعدّل رصيد الصندوق التشغيلي أو مبلغ الصيانة أو الدين، والقيد المترحل مسبقًا لا يتكرر.</div>
            <form class="repair-grid" method="POST" action="{{ route('security-center.accounting.maintenance-prepayments.sync') }}" onsubmit="return confirm('هل راجعت جدول العربونات والنسخة الاحتياطية وتريد إنشاء القيود؟')">
                @csrf
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                <div><label>رمز إصلاح المحاسبة</label><input class="field ltr" type="password" name="access_token" autocomplete="current-password" required></div>
                <div><label>اكتب بالضبط: ترحيل عربونات الصيانة</label><input class="field" name="confirmation" autocomplete="off" placeholder="ترحيل عربونات الصيانة" required></div>
                <div style="align-self:end"><button class="button danger" type="submit" {{ $migrationStatus['ready'] && $webRepairEnabled ? '' : 'disabled' }}>ترحيل العربونات</button></div>
            </form>
        </div>
    </section>

    <section class="panel danger-zone">
        <div class="panel-head"><h2>تنفيذ إهلاك الأصول</h2><span class="badge error-badge">يخفض القيمة الدفترية</span></div>
        <div class="panel-body">
            <div class="warning help"><strong>ما الذي سيتغير؟</strong> ينشئ Asset Log وقيد مصروف إهلاك/مجمع إهلاك ويخفض القيمة الدفترية للأصول المستحقة. التنفيذ من الصفحة مقيد بالشهر الحالي فقط، ولا يعيد كتابة السجلات القديمة.</div>
            <form class="form-grid" method="POST" action="{{ route('security-center.accounting.assets.depreciation.run') }}" onsubmit="return confirm('هل راجعت معاينة كل أصل وتريد تنفيذ إهلاك الشهر الحالي؟')">
                @csrf
                <div><label>الشهر الحالي فقط</label><input class="field ltr" type="month" name="depreciation_period" value="{{ $depreciationPeriod }}" required></div>
                <div><label>رمز إصلاح المحاسبة</label><input class="field ltr" type="password" name="access_token" autocomplete="current-password" required></div>
                <div><label>اكتب بالضبط: تنفيذ إهلاك الأصول</label><input class="field" name="confirmation" autocomplete="off" placeholder="تنفيذ إهلاك الأصول" required></div>
                <div style="align-self:end"><button class="button danger" type="submit" {{ $migrationStatus['ready'] && $webRepairEnabled ? '' : 'disabled' }}>تنفيذ الإهلاك</button></div>
            </form>
        </div>
    </section>

    <section class="panel danger-zone">
        <div class="panel-head"><h2>الإصلاح الفعلي</h2><span class="badge error-badge">يكتب في قاعدة البيانات</span></div>
        <div class="panel-body">
            <div class="warning help"><strong>تنبيه مستقل عن عربونات الصيانة:</strong> هذا الزر يفحص كل Accounting Projection Failures المفتوحة، وقد يشمل أخطاء FIFO التاريخية. لا تستخدمه ضمن مرحلة العربونات والإهلاك إلا بعد مراجعة جدول الأخطاء أعلاه. عند تنفيذه قد ينشئ/يحدّث القيد المحاسبي للمصدر القابل للإصلاح ويعلّم Failure كمحلول، لكنه لا يخمّن تكلفة ولا يشغّل Cutover أو Opening Balances.</div>
            @if(!$webRepairEnabled)<div class="error">الإصلاح من الويب معطّل حاليًا. اضبط قيمة سرية قوية وفريدة في <span class="ltr code">ACCOUNTING_REPAIR_WEB_TOKEN</span> ثم نفّذ <span class="ltr code">php artisan config:clear</span>. المعاينة تبقى متاحة.</div>@endif
            <form class="repair-grid" method="POST" action="{{ route('security-center.accounting.repair') }}" onsubmit="return confirm('هل راجعت المعاينة والنسخة الاحتياطية وتريد تنفيذ الإصلاح الفعلي؟')">
                @csrf
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                <div><label>رمز إصلاح المحاسبة المنفصل</label><input class="field ltr" type="password" name="access_token" autocomplete="current-password" required></div>
                <div><label>اكتب بالضبط: إصلاح القيود</label><input class="field" name="confirmation" autocomplete="off" placeholder="إصلاح القيود" required></div>
                <div style="align-self:end"><button class="button danger" type="submit" {{ $migrationStatus['ready'] && $webRepairEnabled ? '' : 'disabled' }}>تنفيذ الإصلاح</button></div>
            </form>
        </div>
    </section>
</main>
</body>
</html>
