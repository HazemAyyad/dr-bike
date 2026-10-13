<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>حذف المشتريات التجريبية | Doctor Bike</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef0f3;color:#202530;font:15px/1.8 Tahoma,Arial,sans-serif}main{max-width:1000px;margin:auto;padding:24px 16px 48px}h1{font-size:25px;margin:0}h2{font-size:18px;margin:0 0 12px}p{margin:8px 0}.muted{color:#606875}.top,.nav{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.nav a{color:#603c91;text-decoration:none}.card{background:#f8f9fb;border:1px solid #d7dce3;border-radius:14px;padding:20px;margin-top:18px}.warning,.errors{background:#fff0ed;border-color:#e3b1a8;color:#822d24}.success{background:#edf7f1;border-color:#a5c8b4;color:#245d40}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}.stat{padding:15px;border:1px solid #d7dce3;border-radius:12px;background:#f8f9fb}.stat strong{display:block;font-size:27px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:block;font-weight:bold;margin-bottom:5px}input,textarea,button{font:inherit}input:not([type=checkbox]),textarea{width:100%;border:1px solid #bfc6d0;background:#f2f4f7;color:#202530;border-radius:8px;padding:10px}input:focus,textarea:focus{outline:2px solid #8d73b0;outline-offset:2px}textarea{resize:vertical}.full{grid-column:1/-1}.check{display:flex;align-items:flex-start;gap:9px}.check input{margin-top:8px;flex-shrink:0}.danger{background:#f6dad6;color:#842b24;border:1px solid #cc8f85;border-radius:9px;padding:12px 20px;font-weight:bold;cursor:pointer}.danger:disabled{opacity:.6;cursor:wait}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%}th,td{text-align:right;padding:10px;border-bottom:1px solid #d7dce3}.reference{overflow-wrap:anywhere;direction:ltr;display:inline-block}ul{padding-right:22px}@media(max-width:640px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}.card{padding:16px}h1{font-size:21px}.danger{width:100%}}
        .invoice{border:1px solid #d7dce3;border-radius:10px;padding:14px;margin-top:12px}.invoice-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.invoice-info{display:flex;flex-wrap:wrap;gap:8px 20px;margin:8px 0;color:#606875}.search{display:flex;gap:10px;flex-wrap:wrap}.search input{flex:1;min-width:180px}.secondary{color:#603c91;border:1px solid #b5a6ca;background:#efedf3;border-radius:8px;padding:10px 14px;text-decoration:none;cursor:pointer}.pagination{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px}dialog{width:min(580px,calc(100% - 24px));max-height:calc(100dvh - 32px);overflow:auto;padding:20px;border:1px solid #bfc6d0;border-radius:14px;background:#f8f9fb;color:#202530}dialog::backdrop{background:rgba(25,30,40,.5)}.dialog-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.close{background:transparent;border:1px solid #bfc6d0;border-radius:8px;padding:5px 12px;cursor:pointer;color:#202530}.invoice-form{display:grid;gap:12px}.invoice-form .check{font-weight:normal}#selected-invoice{font-weight:bold;color:#822d24;overflow-wrap:anywhere}
    </style>
</head>
<body>
<main>
    <header class="top"><div><h1>حذف المشتريات التجريبية</h1><p class="muted">احذف فاتورة شراء واحدة أو صفّر جميع بيانات المشتريات.</p></div><nav class="nav"><a href="{{ route('security-center.index') }}">مركز الأمان</a><a href="{{ route('security-center.purchase-reset.index') }}">تحديث المعاينة</a></nav></header>

    @if(session('purchase_reset_result'))
        @php($result = session('purchase_reset_result'))
        <section class="card success" role="status"><h2>تم تصفير المشتريات بنجاح</h2><p>حُذفت {{ number_format($result['counts']['bills']) }} فاتورة و{{ number_format($result['counts']['returns']) }} مرتجع و{{ number_format($result['counts']['purchase_payments']) }} دفعة، مع عكس آثارها المرتبطة.</p><p>مرجع نسخة التدقيق: <span class="reference">{{ $result['backup_reference'] }}</span></p></section>
    @endif
    @if(session('purchase_invoice_deleted'))
        @php($deleted = session('purchase_invoice_deleted'))
        <section class="card success" role="status"><h2>تم حذف الفاتورة <span class="reference">PUR-{{ $deleted['bill_id'] }}</span></h2><p>تم عكس آثار الفاتورة على المخزون والديون والصناديق وحفظ نسخة التدقيق.</p><p>مرجع نسخة التدقيق: <span class="reference">{{ $deleted['backup_reference'] }}</span></p></section>
    @endif
    @if($errors->any())
        <section class="card errors" role="alert"><h2>تعذر إكمال الطلب</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>
    @endif

    @if($missing !== [])
        <section class="card errors"><h2>التصفير غير متاح حاليًا</h2><p>جداول السيرفر غير مكتملة. طبّق ترحيلات المشتريات والتدقيق أولًا.</p><p class="reference">{{ implode(', ', $missing) }}</p></section>
    @elseif($preview)
        <div class="stats">
            @foreach(['bills'=>'فواتير الشراء', 'returns'=>'مرتجعات الشراء', 'purchase_payments'=>'الدفعات', 'purchase_receipts'=>'سجلات الاستلام'] as $key=>$label)
                <div class="stat"><span class="muted">{{ $label }}</span><strong>{{ number_format($preview['counts'][$key]) }}</strong></div>
            @endforeach
        </div>
        @if($invoices ?? null)
            <section class="card"><h2>حذف فاتورة واحدة</h2><p class="muted">اختر الفاتورة ثم أكّد حذفها بكلمة مرور الأدمن. يشمل الحذف استلامها ودفعاتها ومرتجعاتها المرتبطة، مع عكس آثارها وحفظ نسخة تدقيق. قد يصبح المخزون سالبًا إذا كانت كمياتها قد بيعت أو استُهلكت.</p>
                <form method="GET" class="search"><input name="search" value="{{ $search ?? '' }}" maxlength="255" aria-label="البحث عن فاتورة" placeholder="رقم الفاتورة أو اسم المورد / الزبون"><button type="submit" class="secondary">بحث</button>@if($search ?? '')<a class="secondary" href="{{ route('security-center.purchase-reset.index') }}">عرض الكل</a>@endif</form>
                @php($workflowLabels = ['draft'=>'مسودة', 'awaiting_receiving'=>'بانتظار الاستلام', 'partially_received'=>'استلام جزئي', 'received'=>'مستلمة', 'awaiting_approval'=>'بانتظار الاعتماد', 'finalized'=>'معتمدة', 'completed'=>'مكتملة', 'cancelled'=>'ملغاة', 'issues_pending'=>'ملاحظات معلقة', 'receiving_issues'=>'فروقات في الاستلام', 'unfinished'=>'غير مكتملة', 'finished'=>'مكتملة'])
                @forelse($invoices as $invoice)
                    @php($party = $invoice->seller?->name ?? $invoice->customer?->name ?? 'بدون مورد / زبون')
                    <article class="invoice">
                        <div class="invoice-head"><strong><span class="reference">PUR-{{ $invoice->id }}</span> · {{ $party }}</strong><button type="button" class="danger invoice-delete" data-action="{{ route('security-center.purchase-reset.invoices.destroy', $invoice) }}" data-reference="PUR-{{ $invoice->id }}" data-party="{{ $party }}" data-version="{{ \App\Services\PurchaseInvoicePurgeService::invoiceVersion($invoice) }}">حذف هذه الفاتورة</button></div>
                        <div class="invoice-info"><span>التاريخ: {{ $invoice->created_at?->format('Y-m-d') ?? '—' }}</span><span>الحالة: {{ $workflowLabels[$invoice->workflow_status] ?? $invoice->workflow_status ?? $invoice->status }}</span><span>عدد البنود: {{ $invoice->items_count }}</span><span>الإجمالي النهائي: {{ number_format($invoice->final_total, 2) }} {{ $invoice->currency }}</span><span>المدفوع: {{ number_format($invoice->paid_amount, 2) }} {{ $invoice->currency }}</span></div>
                    </article>
                @empty
                    <p class="muted">لا توجد فواتير مطابقة.</p>
                @endforelse
                <nav class="pagination" aria-label="صفحات الفواتير">@if($invoices->previousPageUrl())<a class="secondary" href="{{ $invoices->previousPageUrl() }}">السابق</a>@endif<span class="muted">صفحة {{ $invoices->currentPage() }} من {{ $invoices->lastPage() }} · {{ number_format($invoices->total()) }} فاتورة</span>@if($invoices->nextPageUrl())<a class="secondary" href="{{ $invoices->nextPageUrl() }}">التالي</a>@endif</nav>
            </section>
            <dialog id="invoice-dialog" aria-labelledby="invoice-dialog-title">
                <div class="dialog-title"><h2 id="invoice-dialog-title">حذف فاتورة شراء واحدة</h2><button type="button" class="close" id="close-invoice-dialog" aria-label="إغلاق">×</button></div>
                <p id="selected-invoice"></p><p class="muted">سيتم حذف الفاتورة المحددة وعكس آثارها على المخزون والديون والصناديق وحفظ نسخة تدقيق.</p>
                <form id="invoice-delete-form" method="POST" class="invoice-form">
                    @csrf
                    <input id="invoice-version" name="bill_version" type="hidden">
                    <div><label for="invoice-email">البريد الإلكتروني للأدمن</label><input id="invoice-email" name="email" type="email" autocomplete="username" dir="ltr" required maxlength="255"></div>
                    <div><label for="invoice-password">كلمة مرور حساب الأدمن</label><input id="invoice-password" name="password" type="password" autocomplete="current-password" required maxlength="255"></div>
                    <div><label for="invoice-reason">سبب الحذف</label><textarea id="invoice-reason" name="reason" minlength="3" maxlength="1000" rows="2" required>حذف فاتورة شراء تجريبية</textarea></div>
                    <label class="check"><input name="confirmed" type="checkbox" value="1" required><span>أؤكد حذف الفاتورة المحددة وعكس آثارها المرتبطة.</span></label>
                    <button type="submit" class="danger" id="invoice-submit">حذف الفاتورة المحددة</button><p id="invoice-progress" class="muted" aria-live="polite"></p>
                </form>
            </dialog>
        @endif
        <section class="card warning"><h2>عند تصفير جميع المشتريات</h2><p>تشمل العملية الفواتير في جميع المراحل، الاستلام والأمانات، المرتجعات، الدفعات على الحساب، المرفقات وسجل أسعار الشراء. تُعكس آثار المشتريات على المخزون والديون والصناديق، وتُحفظ نسخة تدقيق قبل الحذف.</p><p>المصاريف والمبيعات والمنتجات والموردون والزبائن لا تُحذف. قد يصبح المخزون سالبًا إذا كانت كميات الشراء التجريبية قد بيعت أو استُهلكت. تبقى قيود العكس وسجلات التدقيق محفوظة.</p></section>
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
    const invoiceDialog = document.getElementById('invoice-dialog');
    const invoiceForm = document.getElementById('invoice-delete-form');
    document.querySelectorAll('.invoice-delete').forEach(button => {
        button.addEventListener('click', () => {
            invoiceForm.reset();
            invoiceForm.action = button.dataset.action;
            document.getElementById('invoice-version').value = button.dataset.version;
            document.getElementById('selected-invoice').textContent = button.dataset.reference + ' · ' + button.dataset.party;
            document.getElementById('invoice-submit').disabled = false;
            document.getElementById('invoice-progress').textContent = '';
            invoiceDialog.showModal();
        });
    });
    document.getElementById('close-invoice-dialog')?.addEventListener('click', () => invoiceDialog.close());
    invoiceDialog?.addEventListener('close', () => invoiceForm.reset());
    invoiceForm?.addEventListener('submit', () => {
        document.getElementById('invoice-submit').disabled = true;
        document.getElementById('invoice-progress').textContent = 'جارٍ حذف الفاتورة المحددة وعكس آثارها…';
    });
    document.getElementById('reset-form')?.addEventListener('submit', function () {
        document.getElementById('reset-button').disabled = true;
        document.getElementById('progress').textContent = 'جارٍ تصفير المشتريات وعكس آثارها… انتظر حتى تظهر النتيجة.';
    });
</script>
</body>
</html>
