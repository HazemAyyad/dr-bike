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
        .schema { display:flex; flex-wrap:wrap; gap:7px; margin-top:8px; }
        .chip { padding:5px 9px; border-radius:999px; font-size:12px; background:#e8f7ee; color:var(--green); }
        .chip.off { background:#ffebe9; color:var(--red); }
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin:16px 0; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:13px; padding:14px; box-shadow:0 3px 12px rgba(25,38,65,.04); }
        .card small { display:block; color:var(--muted); margin-bottom:8px; }
        .card strong { font-size:22px; }
        form.filters { display:flex; flex-wrap:wrap; gap:8px; background:var(--card); border:1px solid var(--line); padding:12px; border-radius:13px; margin-bottom:12px; }
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
        'purchase_price_histories.latest' => 'آخر سعر شراء موثق',
        'purchase_products.latest_legacy_purchase_cost' => 'آخر سعر شراء قديم',
        'admin_review_required' => 'لا يوجد مصدر موثوق',
    ];
@endphp
<main class="wrap">
    <h1>مراجعة تغطية تكلفة المخزون القديم</h1>
    <p class="lead">صفحة تشخيصية للقراءة فقط. فتح الصفحة أو استخدام الفلاتر لا يغيّر المخزون ولا ينشئ طبقات تكلفة.</p>

    <section class="notice {{ in_array(false, $schema, true) ? 'danger' : '' }}">
        @if(in_array(false, $schema, true))
            بعض جداول البنية الجديدة غير موجودة بعد. المعاينة ستعرض ما يمكن حسابه من الجداول الحالية، لكن التنفيذ الفعلي غير متاح قبل تجهيز البنية.
        @else
            بنية محاسبة المخزون جاهزة للمعاينة. لا يوجد في هذه الصفحة أي زر تنفيذ أو كتابة.
        @endif
        <div class="schema">
            @foreach($schema as $table => $exists)
                <span class="chip {{ $exists ? '' : 'off' }}">{{ $table }}: {{ $exists ? 'موجود' : 'غير موجود' }}</span>
            @endforeach
        </div>
    </section>

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
                        <th>التكلفة المقترحة</th><th>القيمة المقترحة</th><th>مصدر التكلفة</th><th>الحالة</th><th>سبب المراجعة</th>
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
