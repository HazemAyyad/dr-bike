<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تصفير المشتريات التجريبية | Doctor Bike</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef0f3;color:#202530;font:15px/1.8 Tahoma,Arial,sans-serif}main{max-width:1000px;margin:auto;padding:24px 16px 48px}h1{font-size:25px;margin:0}h2{font-size:18px;margin:0 0 12px}p{margin:8px 0}.muted{color:#606875}.top,.nav{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.nav a{color:#603c91;text-decoration:none}.card{background:#f8f9fb;border:1px solid #d7dce3;border-radius:14px;padding:20px;margin-top:18px}.warning,.errors{background:#fff0ed;border-color:#e3b1a8;color:#822d24}.success{background:#edf7f1;border-color:#a5c8b4;color:#245d40}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}.stat{padding:15px;border:1px solid #d7dce3;border-radius:12px;background:#f8f9fb}.stat strong{display:block;font-size:27px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:block;font-weight:bold;margin-bottom:5px}input,textarea,button{font:inherit}input:not([type=checkbox]),textarea{width:100%;border:1px solid #bfc6d0;background:#f2f4f7;color:#202530;border-radius:8px;padding:10px}input:focus,textarea:focus{outline:2px solid #8d73b0;outline-offset:2px}textarea{resize:vertical}.full{grid-column:1/-1}.check{display:flex;align-items:flex-start;gap:9px}.check input{margin-top:8px;flex-shrink:0}.danger{background:#f6dad6;color:#842b24;border:1px solid #cc8f85;border-radius:9px;padding:12px 20px;font-weight:bold;cursor:pointer}.danger:disabled{opacity:.6;cursor:wait}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%}th,td{text-align:right;padding:10px;border-bottom:1px solid #d7dce3}.reference{overflow-wrap:anywhere;direction:ltr;display:inline-block}ul{padding-right:22px}@media(max-width:640px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}.card{padding:16px}h1{font-size:21px}.danger{width:100%}}
    </style>
</head>
<body>
<main>
    <header class="top"><div><h1>تصفير المشتريات التجريبية</h1><p class="muted">ابدأ قسم المشتريات بسجل فارغ بعد إزالة البيانات التجريبية.</p></div><nav class="nav"><a href="{{ route('security-center.index') }}">مركز الأمان</a><a href="{{ route('security-center.purchase-reset.index') }}">تحديث المعاينة</a></nav></header>

    @if(session('purchase_reset_result'))
        @php($result = session('purchase_reset_result'))
        <section class="card success" role="status"><h2>تم تصفير المشتريات بنجاح</h2><p>حُذفت {{ number_format($result['counts']['bills']) }} فاتورة و{{ number_format($result['counts']['returns']) }} مرتجع و{{ number_format($result['counts']['purchase_payments']) }} دفعة، مع عكس آثارها المرتبطة.</p><p>مرجع نسخة التدقيق: <span class="reference">{{ $result['backup_reference'] }}</span></p></section>
    @endif
    @if($errors->any())
        <section class="card errors" role="alert"><h2>تعذر إكمال الطلب</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>
    @endif
    <section class="card warning"><h2>هذه العملية تحذف كل بيانات المشتريات</h2><p>تشمل الفواتير في جميع المراحل، الاستلام والأمانات، المرتجعات، الدفعات على الحساب، المرفقات وسجل أسعار الشراء. تُعكس آثار المشتريات على المخزون والديون والصناديق، وتُحفظ نسخة تدقيق قبل الحذف.</p><p>المصاريف والمبيعات والمنتجات والموردون والزبائن لا تُحذف. قد يصبح المخزون سالبًا إذا كانت كميات الشراء التجريبية قد بيعت أو استُهلكت. تبقى قيود العكس وسجلات التدقيق محفوظة.</p></section>

    @if($missing !== [])
        <section class="card errors"><h2>التصفير غير متاح حاليًا</h2><p>جداول السيرفر غير مكتملة. طبّق ترحيلات المشتريات والتدقيق أولًا.</p><p class="reference">{{ implode(', ', $missing) }}</p></section>
    @elseif($preview)
        <div class="stats">
            @foreach(['bills'=>'فواتير الشراء', 'returns'=>'مرتجعات الشراء', 'purchase_payments'=>'الدفعات', 'purchase_receipts'=>'سجلات الاستلام'] as $key=>$label)
                <div class="stat"><span class="muted">{{ $label }}</span><strong>{{ number_format($preview['counts'][$key]) }}</strong></div>
            @endforeach
        </div>
        @if($preview['totals'] !== [])
            <section class="card"><h2>إجماليات الفواتير حسب العملة</h2><div class="table-wrap"><table><thead><tr><th>العملة</th><th>الإجمالي النهائي</th><th>المدفوع على الفواتير</th></tr></thead><tbody>@foreach($preview['totals'] as $currency=>$totals)<tr><td>{{ $currency }}</td><td>{{ number_format($totals['total'], 2) }}</td><td>{{ number_format($totals['paid'], 2) }}</td></tr>@endforeach</tbody></table></div></section>
        @endif
        @if(array_sum($preview['counts']) > 0)
            <section class="card"><h2>تأكيد التصفير</h2><p class="muted">أدخل بيانات حساب أدمن فعّال. المعاينة صالحة لمدة 15 دقيقة؛ إذا تغيرت بيانات المشتريات ستحتاج إلى تحديثها.</p>
                <form id="reset-form" method="POST" action="{{ route('security-center.purchase-reset.destroy') }}" class="grid">
                    @csrf
                    <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                    <div><label for="email">البريد الإلكتروني للأدمن</label><input id="email" name="email" type="email" autocomplete="username" dir="ltr" required maxlength="255"></div>
                    <div><label for="password">كلمة مرور حساب الأدمن</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="255"></div>
                    <div class="full"><label for="reason">سبب التصفير</label><textarea id="reason" name="reason" minlength="3" maxlength="1000" rows="2" required>حذف بيانات المشتريات التجريبية</textarea></div>
                    <label class="full check"><input type="checkbox" name="confirmed" value="1" required><span>أؤكد حذف جميع المشتريات التجريبية وعكس آثارها على المخزون والديون والصناديق.</span></label>
                    <div class="full"><button id="reset-button" class="danger" type="submit">تصفير جميع المشتريات</button><p id="progress" class="muted" aria-live="polite"></p></div>
                </form>
            </section>
        @else
            <section class="card success"><h2>قسم المشتريات فارغ</h2><p>لا توجد بيانات مشتريات لتصفيرها.</p></section>
        @endif
    @endif
</main>
<script>
    document.getElementById('reset-form')?.addEventListener('submit', function () {
        document.getElementById('reset-button').disabled = true;
        document.getElementById('progress').textContent = 'جارٍ تصفير المشتريات وعكس آثارها… انتظر حتى تظهر النتيجة.';
    });
</script>
</body>
</html>
