<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اختبار مزامنة المخزون مع المتجر</title>
    <style>
        body { font-family: system-ui, Segoe UI, Tahoma, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background: #f6f7f9; }
        h1 { font-size: 1.25rem; }
        .warn { background: #fff3cd; border: 1px solid #ffc107; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        form { background: #fff; padding: 1.25rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.35rem; font-weight: 600; }
        input[type="number"] { width: 100%; max-width: 280px; padding: 0.5rem 0.65rem; border: 1px solid #ccc; border-radius: 6px; }
        button { margin-top: 1rem; padding: 0.55rem 1.2rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #1d4ed8; }
        .log { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 8px; font-size: 0.85rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
        .ok { color: #86efac; }
        .fail { color: #fca5a5; }
        .meta { color: #94a3b8; font-size: 0.8rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <h1>اختبار مزامنة المخزون (ManageItem)</h1>
    <div class="warn">
        للتجربة المحلية فقط. لا تعرض الرابط على إنتاج عام بدون حماية (مثلاً IP أو كلمة سر).
        السجلات تُكتب أيضاً في <code>storage/logs/laravel.log</code> تحت البادئة <code>[store-sync]</code> و <code>[store-sync-test page]</code>.
    </div>

    <form method="post" action="{{ route('test.store-sync.run') }}">
        @csrf
        <label for="product_id">رقم المنتج (products.id)</label>
        <input type="number" id="product_id" name="product_id" value="{{ old('product_id', 532) }}" required min="1">

        <label for="add_quantity" style="margin-top:1rem;">زيادة مخزون محلي (اختياري)</label>
        <input type="number" id="add_quantity" name="add_quantity" value="{{ old('add_quantity', 0) }}" min="0">
        <p class="meta">إذا وضعت 0 يُرسل للمتجر المخزون الحالي في قاعدتك كما هو. إذا أكبر من 0 يُضاف أولاً محلياً ثم تُزامن القيمة الجديدة.</p>

        <button type="submit">تشغيل المزامنة</button>
    </form>

    @if(session('result'))
        @php $r = session('result'); @endphp
        <p>
            <strong>النتيجة:</strong>
            @if(!empty($r['ok']))
                <span class="ok">نجاح</span>
            @else
                <span class="fail">فشل أو خطأ</span>
            @endif
            @if(!empty($r['error']))
                — {{ $r['error'] }}
            @endif
            @if(!empty($r['skipped']))
                — تم التخطي (إعدادات أو تعطيل المزامنة)
            @endif
        </p>
    @endif

    @if(session('product_model'))
        @php $p = session('product_model'); @endphp
        <p class="meta">المخزون المحلي بعد الطلب: <strong>{{ $p->stock }}</strong> (منتج #{{ $p->id }})</p>
    @endif

    @if(session('steps'))
        <h2>سجل الخطوات (نفسه في اللوج)</h2>
        <div class="log">@foreach(session('steps') as $row)
<span class="meta">[{{ $row['time'] }}]</span> {{ $row['message'] }}
@if(!empty($row['context']))
{{ json_encode($row['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}
@endif

@endforeach</div>
    @endif
</body>
</html>
