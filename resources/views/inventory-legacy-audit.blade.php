<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مراجعة تغطية تكلفة المخزون القديم</title>
    <style>
        :root { color-scheme: light; --bg:#f4f6fb; --card:#fff; --ink:#152033; --muted:#687387; --line:#dfe4ed; --blue:#2563eb; --green:#137a45; --amber:#a65b00; --red:#b42318; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:Tahoma,Arial,sans-serif; }
        .wrap { width:min(1500px,96%); margin:24px auto 60px; }
        h1 { margin:0 0 8px; font-size:26px; }
        .lead { color:var(--muted); margin:0 0 18px; line-height:1.8; }
        .notice { padding:13px 15px; border:1px solid #bcd0ff; background:#eef4ff; border-radius:12px; margin-bottom:16px; line-height:1.7; }
        .notice.danger { border-color:#f2b8b5; background:#fff1f0; color:#7a271a; }
        .notice.success { border-color:#a9dfbf; background:#edf9f2; color:#145c35; }
        .schema { display:flex; flex-wrap:wrap; gap:7px; margin-top:8px; }
        .chip { padding:5px 9px; border-radius:999px; font-size:12px; background:#e8f7ee; color:var(--green); }
        .chip.off { background:#ffebe9; color:var(--red); }
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin:16px 0; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:13px; padding:14px; box-shadow:0 3px 12px rgba(25,38,65,.04); }
        .card small { display:block; color:var(--muted); margin-bottom:8px; }
        .card strong { font-size:22px; }
        form.filters { display:flex; flex-wrap:wrap; gap:8px; background:var(--card); border:1px solid var(--line); padding:12px; border-radius:13px; margin-bottom:12px; }
        .operation { background:var(--card); border:1px solid var(--line); padding:14px; border-radius:13px; margin:12px 0; }
        .operation .fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:9px; margin:10px 0; }
        .operation label { display:flex; flex-direction:column; gap:6px; color:var(--muted); font-size:13px; }
        .operation label.check { flex-direction:row; align-items:center; color:var(--ink); }
        .operation label.check input { width:auto; }
        .operation input,.operation select { width:100%; min-width:0; }
        .breakdown { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:10px; margin:12px 0; }
        .breakdown ul { margin:8px 0 0; padding-right:20px; line-height:1.9; }
        input,select,button,a.button { min-height:40px; border:1px solid var(--line); border-radius:9px; padding:8px 11px; background:#fff; color:var(--ink); font:inherit; }
        input { min-width:260px; flex:1; }
        button,a.button { background:var(--blue); border-color:var(--blue); color:#fff; cursor:pointer; text-decoration:none; }
        a.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
        .table-card { overflow:hidden; background:var(--card); border:1px solid var(--line); border-radius:13px; }
        .scroll { overflow:auto; }
        table { width:100%; border-collapse:collapse; min-width:1180px; }
        th,td { padding:11px 10px; border-bottom:1px solid var(--line); text-align:right; vertical-align:middle; white-space:nowrap; }
        th { background:#f8f9fc; color:#465268; font-size:13px; position:sticky; top:0; }
        tr:last-child td { border-bottom:0; }
        .product { white-space:normal; min-width:240px; }
        .muted { color:var(--muted); font-size:12px; }
        .status { display:inline-block; padding:5px 9px; border-radius:999px; font-size:12px; font-weight:700; }
        .covered { background:#e8f7ee; color:var(--green); }
        .ready { background:#fff3dc; color:var(--amber); }
        .review,.over_covered { background:#ffebe9; color:var(--red); }
        .pagination { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px; }
        .empty { padding:35px; text-align:center; color:var(--muted); }
        code { direction:ltr; display:inline-block; background:#eef1f6; padding:2px 6px; border-radius:5px; }
        @media (max-width:700px) { .wrap{width:94%;margin-top:14px} h1{font-size:21px} input{min-width:100%} .cards{grid-template-columns:1fr 1fr} }
    </style>
</head>
<body>
@php
    $statusLabels = [
        'covered' => 'مغطى بالكامل',
        'ready' => 'جاهز لإنشاء طبقة',
        'review' => 'يحتاج مراجعة',
        'over_covered' => 'التكلفة أكبر من المخزون',
    ];
    $reasonLabels = [
        'cost_quantity_exceeds_physical_stock' => 'كمية طبقات التكلفة أكبر من المخزون الفعلي',
        'existing_opening_layer_has_insufficient_coverage' => 'توجد طبقة افتتاحية لكنها لا تغطي كامل الكمية',
        'reliable_opening_unit_cost_not_found' => 'لم يتم العثور على تكلفة شراء موثوقة',
    ];
    $sourceLabels = [
        'purchase_price_histories.latest' => 'آخر تكلفة استلام شراء مقبول',
        'purchase_products.latest_legacy_purchase_cost' => 'آخر سعر شراء قديم',
        'admin_review_required' => 'لا يوجد مصدر موثوق',
        'administrative_manual_cost' => 'تكلفة اعتمدها المسؤول يدوياً',
    ];
@endphp
<main class="wrap">
    <h1>مراجعة تغطية تكلفة المخزون القديم</h1>
    <p class="lead">فتح الصفحة واستخدام الفلاتر والتصدير عمليات قراءة فقط. إجراءات المعالجة المنفصلة في الأسفل تتطلب نسخة احتياطية حديثة وتأكيداً صريحاً، ولا تغيّر كمية المخزون.</p>

    @if($errors->any())
        <section class="notice danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>
    @endif

    @if(session('review_result'))
        <section class="notice success">{{ session('review_result') }}</section>
    @endif
    @if(session('backfill_result'))
        @php($batch = session('backfill_result'))
        <section class="notice {{ $batch['failed'] ? 'danger' : 'success' }}">
            نتيجة الدفعة: تم اختيار {{ $batch['selected'] }}، إنشاء {{ $batch['created'] }}، تجاوز {{ $batch['skipped'] }}، فشل {{ $batch['failed'] }}.
            @if($batch['errors'])
                <ul>@foreach($batch['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
        </section>
    @endif
    @if(session('variant_reference_result'))
        @php($batch = session('variant_reference_result'))
        <section class="notice {{ $batch['failed'] ? 'danger' : 'success' }}">
            نتيجة متغيرات المنتجات: تم اختيار {{ $batch['selected'] }}، إنشاء {{ $batch['created'] }}، تجاوز {{ $batch['skipped'] }}، فشل {{ $batch['failed'] }}.
            @if($batch['errors'])
                <ul>@foreach($batch['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
        </section>
    @endif
    @if(session('review_import_result'))
        @php($batch = session('review_import_result'))
        <section class="notice {{ $batch['failed'] ? 'danger' : 'success' }}">
            نتيجة ملف الأسعار: مجموعات {{ $batch['selected_groups'] }}، هويات {{ $batch['selected'] }}، إنشاء {{ $batch['created'] }}، تجاوز {{ $batch['skipped'] }}، فشل {{ $batch['failed'] }}.
            @if($batch['errors'])
                <ul>@foreach($batch['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif
        </section>
    @endif

    <section class="notice {{ in_array(false, $schema, true) ? 'danger' : '' }}">
        @if(in_array(false, $schema, true))
            بعض جداول البنية الجديدة غير موجودة بعد. المعاينة ستعرض ما يمكن حسابه من الجداول الحالية، لكن التنفيذ الفعلي غير متاح قبل تجهيز البنية.
        @else
            بنية محاسبة المخزون جاهزة. التنفيذ متاح فقط من النماذج المؤكدة أدناه وعلى دفعات محدودة.
        @endif
        <div class="schema">
            @foreach($schema as $table => $exists)
                <span class="chip {{ $exists ? '' : 'off' }}">{{ $table }}: {{ $exists ? 'موجود' : 'غير موجود' }}</span>
            @endforeach
        </div>
    </section>

    <section class="breakdown">
        <div class="card">
            <strong>مصادر التكلفة</strong>
            <ul>
                @foreach($sourceBreakdown as $source => $values)
                    <li>{{ $sourceLabels[$source] ?? $source }}: {{ number_format($values['identities']) }} هوية، {{ number_format($values['missing_quantity'], 2) }} قطعة</li>
                @endforeach
            </ul>
        </div>
        <div class="card">
            <strong>تفصيل المراجعة</strong>
            <ul>
                <li>منتجات عادية بلا تكلفة معتمدة: {{ number_format($reviewBreakdown['main']) }}</li>
                <li>هويات أحجام/ألوان تحتاج تكلفة مستقلة: {{ number_format($reviewBreakdown['variants']) }}</li>
                <li>منها لديها تكلفة منتج عامة كمرجع فقط: {{ number_format($reviewBreakdown['variants_with_reference']) }}</li>
            </ul>
        </div>
    </section>

    <form class="operation" method="post" action="{{ route('inventory.legacy-audit.backfill-ready') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <strong>تنفيذ دفعة آمنة للهويات الجاهزة</strong>
        <p class="lead">ينشئ تغطية تكلفة فقط ولا يغيّر كمية المنتج. يعيد فحص الكمية والتكلفة داخل معاملة وقفل قاعدة بيانات، ويمكن إعادة تشغيله دون تكرار.</p>
        <div class="fields">
            <label>اسم منفذ المراجعة<input name="operator" required maxlength="120" value="{{ old('operator') }}"></label>
            <label>حجم الدفعة<select name="batch_size"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option></select></label>
            <label>اكتب BACKFILL للتأكيد<input name="confirmation" required autocomplete="off"></label>
        </div>
        <label class="check"><input type="checkbox" name="backup_confirmed" value="1" required> أؤكد وجود نسخة قاعدة بيانات حديثة قبل التنفيذ.</label>
        <button type="submit" @disabled($summary['ready'] === 0 || in_array(false, $schema, true))>تنفيذ الدفعة</button>
    </form>

    <form class="operation" method="post" action="{{ route('inventory.legacy-audit.product-reference-batch') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <strong>اعتماد سعر الشراء الأساسي لمتغيرات المنتج</strong>
        <p class="lead">
            يوجد {{ number_format($productReferenceBatch['identities']) }} لوناً/حجماً تابعاً لـ{{ number_format($productReferenceBatch['products']) }} منتجاً، بكمية ناقصة {{ number_format($productReferenceBatch['quantity'], 2) }} وقيمة تغطية {{ number_format($productReferenceBatch['value'], 2) }} شيكل.
            ستعتمد العملية آخر <b>سعر شراء قديم</b> مسجل للمنتج الأساسي على متغيراته التي لا تملك تكلفة مستقلة؛ لا تستخدم سعر البيع ولا تغيّر المخزون، وتسجل طبقة وحركة ومصدر المراجعة لكل متغير.
        </p>
        <div class="fields">
            <label>اسم منفذ المراجعة<input name="operator" required maxlength="120" value="{{ old('operator') }}"></label>
            <label>حجم الدفعة<select name="batch_size"><option value="10">10</option><option value="25">25</option><option value="50" selected>50</option></select></label>
            <label>اكتب VARIANTS للتأكيد<input name="confirmation" required autocomplete="off"></label>
        </div>
        <label class="check"><input type="checkbox" name="backup_confirmed" value="1" required> راجعت قاعدة الاعتماد وأؤكد وجود نسخة احتياطية حديثة.</label>
        <button type="submit" @disabled($productReferenceBatch['identities'] === 0 || in_array(false, $schema, true))>تنفيذ دفعة المتغيرات</button>
    </form>

    <section class="operation">
        <strong>ملف Excel لمراجعة الأسعار المتبقية مع صاحب المتجر</strong>
        <p class="lead">
            يحتوي الملف الحالي على {{ number_format($manualReviewSummary['groups']) }} قرار تكلفة تغطي {{ number_format($manualReviewSummary['identities']) }} هوية بكمية {{ number_format($manualReviewSummary['quantity'], 2) }}.
            صفوف الألوان والأحجام مجمعة حسب المنتج. يعبئ صاحب المتجر تكلفة الوحدة ومصدرها ويكتب APPROVE للحالات المؤكدة، ويمكنه ترك الحالات غير المعروفة فارغة.
        </p>
        <a class="button" href="{{ route('inventory.legacy-audit.review-workbook', ['token' => $token]) }}">تنزيل ملف Excel للأسعار الناقصة</a>
        <form method="post" enctype="multipart/form-data" action="{{ route('inventory.legacy-audit.review-workbook.preview') }}" style="margin-top:14px">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="fields">
                <label>ملف Excel بعد تعبئته<input type="file" name="file" accept=".xlsx" required></label>
            </div>
            <button type="submit" @disabled($manualReviewSummary['groups'] === 0 || in_array(false, $schema, true))>رفع الملف ومعاينته فقط</button>
        </form>
    </section>

    @if($reviewImportPreview)
        <section class="operation">
            <strong>معاينة ملف الأسعار: {{ $reviewImportPreview['file_name'] ?? '—' }}</strong>
            <p class="lead">
                مجموعات معتمدة {{ number_format($reviewImportPreview['summary']['groups'] ?? 0) }}، هويات {{ number_format($reviewImportPreview['summary']['identities'] ?? 0) }}، كمية {{ number_format($reviewImportPreview['summary']['quantity'] ?? 0, 2) }}، قيمة {{ number_format($reviewImportPreview['summary']['value'] ?? 0, 2) }}.
                لم تُكتب أي بيانات حتى الآن.
            </p>
            @if($reviewImportPreview['errors'] ?? [])
                <section class="notice danger"><strong>يجب تصحيح الملف وإعادة رفعه:</strong><ul>@foreach($reviewImportPreview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></section>
            @endif
            @if($reviewImportPreview['rows'] ?? [])
                <div class="scroll">
                    <table>
                        <thead><tr><th>المنتج</th><th>النطاق</th><th>الهويات</th><th>الكمية</th><th>تكلفة الوحدة</th><th>العملة</th><th>مصدر التكلفة</th></tr></thead>
                        <tbody>
                        @foreach($reviewImportPreview['rows'] as $row)
                            <tr>
                                <td class="product"><strong>{{ $row['product_name'] }}</strong><br><span class="muted">#{{ $row['product_id'] }} · {{ $row['product_code'] }}</span></td>
                                <td>{{ $row['scope_label'] }}</td>
                                <td>{{ count($row['identities']) }}</td>
                                <td>{{ number_format($row['missing_quantity'], 2) }}</td>
                                <td>{{ number_format($row['unit_cost'], 6) }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td class="product">{{ $row['cost_evidence'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(($reviewImportPreview['errors'] ?? []) === [] && ($reviewImportPreview['rows'] ?? []) !== [])
                <form method="post" action="{{ route('inventory.legacy-audit.review-workbook.apply') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="preview_token" value="{{ $reviewImportPreview['preview_token'] }}">
                    <div class="fields">
                        <label>اسم منفذ الاستيراد<input name="operator" required maxlength="120" value="{{ old('operator') }}"></label>
                        <label>اكتب IMPORT للتأكيد<input name="confirmation" required autocomplete="off"></label>
                    </div>
                    <label class="check"><input type="checkbox" name="backup_confirmed" value="1" required> راجعت المعاينة وأؤكد وجود نسخة احتياطية حديثة.</label>
                    <button type="submit">اعتماد الأسعار وإنشاء التغطية</button>
                </form>
            @endif
        </section>
    @endif

    @if($resolveRow)
        <form class="operation" method="post" action="{{ route('inventory.legacy-audit.reviewed-cost') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="identity_key" value="{{ $resolveRow['identity_key'] }}">
            <strong>اعتماد تكلفة بعد المراجعة: {{ $resolveRow['product_name'] }} {{ $resolveRow['variant_label'] ? '— '.$resolveRow['variant_label'] : '' }}</strong>
            <p class="lead">الكمية الفعلية {{ number_format($resolveRow['physical_quantity'], 2) }}، الناقصة {{ number_format($resolveRow['missing_quantity'], 2) }}.
                @if($resolveRow['reference_unit_cost'] !== null)
                    تكلفة المنتج العامة القديمة: {{ number_format($resolveRow['reference_unit_cost'], 4) }} {{ $resolveRow['reference_currency'] }}، وهي مرجع فقط وليست تكلفة متغير مؤكدة.
                @endif
            </p>
            @if(in_array(false, $schema, true))
                <section class="notice danger">التنفيذ غير متاح قبل اكتمال جداول محاسبة المخزون.</section>
            @elseif($resolveRow['status'] === 'over_covered')
                <section class="notice danger">لا يمكن إضافة تكلفة لهذه الهوية لأن طبقات التكلفة أكبر من المخزون الفعلي؛ يلزم تصحيح منفصل.</section>
            @else
                <div class="fields">
                    <label>تكلفة الوحدة<input type="number" name="unit_cost" min="0.000001" step="0.000001" required value="{{ old('unit_cost', $resolveRow['reference_unit_cost']) }}"></label>
                    <label>العملة<select name="currency"><option value="شيكل" selected>شيكل</option><option value="دولار">دولار</option><option value="دينار">دينار</option></select></label>
                    <label>اسم منفذ المراجعة<input name="operator" required maxlength="120" value="{{ old('operator') }}"></label>
                    <label>السبب<input name="reason" required maxlength="120" value="{{ old('reason', 'مراجعة تكلفة افتتاحية للمخزون القديم') }}"></label>
                    <label>ملاحظات<input name="notes" maxlength="1000" value="{{ old('notes') }}"></label>
                    <label>اكتب REVIEW للتأكيد<input name="confirmation" required autocomplete="off"></label>
                </div>
                <label class="check"><input type="checkbox" name="backup_confirmed" value="1" required> راجعت التكلفة وأؤكد وجود نسخة احتياطية حديثة.</label>
                <button type="submit">اعتماد التكلفة وإنشاء التغطية</button>
            @endif
        </form>
    @endif

    <section class="cards">
        <div class="card"><small>هويات المخزون</small><strong>{{ number_format($summary['identities']) }}</strong></div>
        <div class="card"><small>مغطى بالكامل</small><strong>{{ number_format($summary['covered']) }}</strong></div>
        <div class="card"><small>جاهز تلقائياً</small><strong>{{ number_format($summary['ready']) }}</strong></div>
        <div class="card"><small>يحتاج مراجعة</small><strong>{{ number_format($summary['review']) }}</strong></div>
        <div class="card"><small>الكمية الفعلية</small><strong>{{ number_format($summary['physical_quantity'], 2) }}</strong></div>
        <div class="card"><small>الكمية المغطاة</small><strong>{{ number_format($summary['costed_quantity'], 2) }}</strong></div>
        <div class="card"><small>الكمية الناقصة</small><strong>{{ number_format($summary['missing_quantity'], 2) }}</strong></div>
        <div class="card"><small>القيمة المقترحة المتاحة</small><strong>
            @forelse($summary['suggested_values'] as $currency => $value)
                {{ number_format($value, 2) }} {{ $currency }}{{ $loop->last ? '' : ' · ' }}
            @empty
                —
            @endforelse
        </strong></div>
    </section>

    <form class="filters" method="get">
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="search" name="search" value="{{ $search }}" placeholder="ابحث بالاسم أو الكود أو رقم المنتج">
        <select name="status">
            <option value="">كل الحالات</option>
            @foreach($statusLabels as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit">تطبيق الفلتر</button>
        <a class="button secondary" href="{{ route('inventory.legacy-audit', ['token' => $token]) }}">إلغاء الفلتر</a>
        <a class="button secondary" href="{{ route('inventory.legacy-audit.export', ['token' => $token]) }}">تصدير CSV كامل</a>
        <button type="button" id="copySummary">نسخ ملخص التشخيص</button>
    </form>

    <section class="table-card">
        @if($rows->isEmpty())
            <div class="empty">لا توجد نتائج مطابقة.</div>
        @else
            <div class="scroll">
                <table>
                    <thead><tr>
                        <th>المنتج</th><th>المتغير</th><th>الفعلي</th><th>المغطى</th><th>الناقص</th>
                        <th>التكلفة المقترحة</th><th>القيمة المقترحة</th><th>مصدر التكلفة</th><th>الحالة</th><th>سبب المراجعة</th><th>إجراء</th>
                    </tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td class="product"><strong>{{ $row['product_name'] }}</strong><br><span class="muted">#{{ $row['product_id'] }} · كود {{ $row['product_code'] ?: '—' }}</span></td>
                            <td>{{ $row['variant_label'] ?: 'بدون متغير' }}</td>
                            <td>{{ number_format($row['physical_quantity'], 2) }}</td>
                            <td>{{ number_format($row['costed_quantity'], 2) }}</td>
                            <td>{{ number_format($row['missing_quantity'], 2) }}</td>
                            <td>{{ $row['suggested_unit_cost'] === null ? '—' : number_format($row['suggested_unit_cost'], 4).' '.$row['suggested_currency'] }}</td>
                            <td>{{ $row['suggested_value'] === null ? '—' : number_format($row['suggested_value'], 2).' '.$row['suggested_currency'] }}</td>
                            <td>{{ $sourceLabels[$row['cost_source']] ?? $row['cost_source'] }}</td>
                            <td><span class="status {{ $row['status'] }}">{{ $statusLabels[$row['status']] ?? $row['status'] }}</span></td>
                            <td class="product">{{ $reasonLabels[$row['reason']] ?? ($row['reason'] ?: '—') }}</td>
                            <td>
                                @if(in_array($row['status'], ['review', 'over_covered'], true))
                                    <a class="button secondary" href="{{ route('inventory.legacy-audit', ['token' => $token, 'status' => $row['status'], 'resolve' => $row['identity_key']]) }}">مراجعة</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <div>عرض {{ $rows->firstItem() }}–{{ $rows->lastItem() }} من {{ $rows->total() }}</div>
                <div>
                    @if($rows->previousPageUrl())<a class="button secondary" href="{{ $rows->previousPageUrl() }}">السابق</a>@endif
                    @if($rows->nextPageUrl())<a class="button secondary" href="{{ $rows->nextPageUrl() }}">التالي</a>@endif
                </div>
            </div>
        @endif
    </section>
</main>
<script>
document.getElementById('copySummary').addEventListener('click', async function () {
    const text = `تقرير تغطية المخزون القديم
الهويات: {{ $summary['identities'] }}
مغطى: {{ $summary['covered'] }}
جاهز تلقائياً: {{ $summary['ready'] }}
يحتاج مراجعة: {{ $summary['review'] }}
الكمية الفعلية: {{ $summary['physical_quantity'] }}
المغطاة: {{ $summary['costed_quantity'] }}
الناقصة: {{ $summary['missing_quantity'] }}`;
    try { await navigator.clipboard.writeText(text); this.textContent = 'تم النسخ'; }
    catch (_) { window.prompt('انسخ الملخص:', text); }
});
</script>
</body>
</html>
