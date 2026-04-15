<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doctor Bike — تعديل منتج (اختبار)</title>
    <style>
        :root {
            --bg: #f0f2f5;
            --card: #fff;
            --border: #e2e8f0;
            --primary: #0d6efd;
            --text: #1e293b;
            --muted: #64748b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }
        .wrap { max-width: 960px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;
        }
        .topbar h1 { font-size: 1.35rem; margin: 0; font-weight: 700; }
        .topbar a { color: var(--primary); text-decoration: none; font-size: 0.9rem; }
        .warn {
            background: #fff8e6; border: 1px solid #f0d78c; border-radius: 10px;
            padding: 0.75rem 1rem; font-size: 0.88rem; margin-bottom: 1rem; color: #7c5e10;
        }
        .card {
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 1.25rem 1.35rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        .card-title {
            font-size: 0.95rem; font-weight: 700; margin: 0 0 1rem;
            padding-bottom: 0.5rem; border-bottom: 1px solid var(--border);
            color: #334155;
        }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem 1rem; }
        .grid-5 { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem 1rem; }
        @media (max-width: 720px) {
            .grid-3 { grid-template-columns: 1fr; }
        }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--muted); margin-bottom: 0.25rem; }
        input[type="text"], input[type="number"], input[type="date"], textarea, select {
            width: 100%; padding: 0.5rem 0.65rem; border: 1px solid var(--border);
            border-radius: 8px; font-size: 0.9rem;
        }
        textarea { min-height: 72px; resize: vertical; }
        .upload-zone {
            border: 2px dashed #cbd5e1; border-radius: 10px; min-height: 120px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 0.75rem; text-align: center; color: var(--muted); font-size: 0.85rem;
            background: #fafafa;
        }
        .upload-zone input[type="file"] { width: 100%; font-size: 0.8rem; }
        .upload-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        @media (max-width: 600px) { .upload-grid { grid-template-columns: 1fr; } }
        .cat-bar {
            background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;
            padding: 0.6rem 1rem; font-weight: 600; margin-bottom: 0.75rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.45rem 0.9rem; border-radius: 8px; border: none; cursor: pointer;
            font-size: 0.9rem; font-weight: 600;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { filter: brightness(1.05); }
        .btn-outline { background: #fff; border: 1px solid var(--border); color: var(--text); }
        .btn-sm { padding: 0.25rem 0.55rem; font-size: 0.8rem; }
        .btn-icon { width: 2rem; height: 2rem; border-radius: 8px; background: var(--primary); color: #fff; border: none; cursor: pointer; font-size: 1.1rem; line-height: 1; }
        .size-block {
            border: 1px solid var(--border); border-radius: 10px; padding: 1rem; margin-bottom: 0.75rem; background: #fafbfc;
        }
        .size-toolbar { display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 0.75rem; }
        .color-subhead {
            display: flex; align-items: center; justify-content: space-between;
            font-size: 0.85rem; font-weight: 700; color: #334155; margin: 0.75rem 0 0.5rem;
            padding-top: 0.5rem; border-top: 1px dashed var(--border);
        }
        .color-fields {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.5rem;
            margin-bottom: 0.65rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid #e8eef4;
        }
        .color-fields:last-child { border-bottom: none; }
        .flex { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
        .checks label { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 500; color: var(--text); }
        .log { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 10px; font-size: 0.8rem; white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow: auto; }
        .ok { color: #4ade80; } .fail { color: #f87171; }
        .meta { font-size: 0.8rem; color: var(--muted); }
        .hint { font-size: 0.78rem; color: var(--muted); margin-top: 0.2rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <h1>Doctor Bike — تعديل منتج</h1>
        <div>
            <a href="{{ route('test.store-sync') }}">اختبار المخزون فقط</a>
            <span class="meta"> | </span>
            <a href="{{ route('test.product-edit', ['product_id' => request('product_id', 532)]) }}">تحديث الصفحة</a>
        </div>
    </div>

    <div class="warn">
        صفحة تجريبية: تحدّث قاعدة البيانات ثم ترسل إلى متجر .NET. لا تستخدم على إنتاج عام بدون حماية.
        رفع الصور/الفيديو يستخدم <strong>multipart</strong> إلى <code>ManageItem</code>.
    </div>

    @if ($errors->any())
        <div class="warn" style="background:#fef2f2;border-color:#fecaca;color:#991b1b">
            <strong>تحقق من الحقول:</strong>
            <ul style="margin:0.5rem 0 0 1.25rem;padding:0">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $p = $prefill;
        $selSubs = old('sub_categories', $selectedSubCategoryIds ?? []);
        if (! is_array($selSubs)) {
            $selSubs = $selSubs !== null && $selSubs !== '' ? [(int) $selSubs] : [];
        }
    @endphp

    <form method="post" action="{{ route('test.product-edit.run') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="product_id" value="{{ old('product_id', $p?->id ?? 532) }}">

        <div class="card">
            <h2 class="card-title">بيانات المنتج</h2>
            <div class="grid-3">
                <div>
                    <label>الاسم بالعربية *</label>
                    <input type="text" name="nameAr" required value="{{ old('nameAr', $p?->nameAr ?? '') }}">
                </div>
                <div>
                    <label>الاسم بالإنجليزية</label>
                    <input type="text" name="nameEng" value="{{ old('nameEng', $p?->nameEng ?? '') }}">
                </div>
                <div>
                    <label>الاسم بالعبرية</label>
                    <input type="text" name="nameAbree" value="{{ old('nameAbree', $p?->nameAbree ?? '') }}">
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">أسعار ومخزون</h2>
            <div class="grid-5">
                <div>
                    <label>سنة الصنع</label>
                    <input type="number" name="manufactureYear" min="0" max="2100" value="{{ old('manufactureYear', $p?->manufactureYear ?? '') }}">
                </div>
                <div>
                    <label>الخصم *</label>
                    <input type="number" name="discount" step="0.01" required value="{{ old('discount', $p?->discount ?? 0) }}">
                </div>
                <div>
                    <label>السعر القطاعي *</label>
                    <input type="number" name="normailPrice" step="0.01" required value="{{ old('normailPrice', $p?->normailPrice ?? '') }}">
                </div>
                <div>
                    <label>السعر جملة</label>
                    <input type="number" name="wholesalePrice" step="0.01" value="{{ old('wholesalePrice', $p?->wholesalePrice ?? 0) }}">
                </div>
                <div>
                    <label>العدد / المخزون (صنف بدون مقاسات)</label>
                    <input type="number" name="stock" min="0" value="{{ old('stock', $p?->stock ?? '') }}">
                    <p class="hint">إن وُجدت ألوان بالأسفل يُحسب المجموع منها للمتجر.</p>
                </div>
            </div>
            <div class="grid-5" style="margin-top:0.75rem">
                <div>
                    <label>تقييم rate</label>
                    <input type="number" name="rate" step="0.1" value="{{ old('rate', $p?->rate ?? 4) }}">
                </div>
                <div>
                    <label>موديل</label>
                    <input type="text" name="model" value="{{ old('model', $p?->model ?? '') }}">
                </div>
                <div>
                    <label>حد أدنى مخزون *</label>
                    <input type="number" name="min_stock" step="0.01" required value="{{ old('min_stock', $p?->min_stock ?? 0) }}">
                </div>
                <div>
                    <label>يُباع مع ورق *</label>
                    <select name="is_sold_with_paper" required>
                        <option value="1" @selected(old('is_sold_with_paper', $p?->is_sold_with_paper ?? 1) == 1)>نعم</option>
                        <option value="0" @selected(old('is_sold_with_paper', $p?->is_sold_with_paper ?? 1) == 0)>لا</option>
                    </select>
                </div>
            </div>
            <div class="flex checks" style="margin-top:0.75rem">
                <label><input type="checkbox" name="isShow" value="1" @checked(old('isShow', $p?->isShow ?? true))> معروض</label>
                <label><input type="checkbox" name="isNewItem" value="1" @checked(old('isNewItem', $p?->isNewItem ?? true))> جديد</label>
                <label><input type="checkbox" name="isMoreSales" value="1" @checked(old('isMoreSales', $p?->isMoreSales ?? true))> أكثر مبيعاً</label>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">الأوصاف</h2>
            <div class="grid-3">
                <div>
                    <label>الوصف عربي *</label>
                    <textarea name="descriptionAr" required>{{ old('descriptionAr', $p?->descriptionAr ?? '') }}</textarea>
                </div>
                <div>
                    <label>الوصف إنجليزي</label>
                    <textarea name="descriptionEng">{{ old('descriptionEng', $p?->descriptionEng ?? '') }}</textarea>
                </div>
                <div>
                    <label>الوصف عبري</label>
                    <textarea name="descriptionAbree">{{ old('descriptionAbree', $p?->descriptionAbree ?? '') }}</textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="cat-bar">
                <span>التصنيف — فئات فرعية</span>
            </div>
            <label for="sub_categories">اختر فئة أو أكثر (اضغط Ctrl أو السحب للاختيار المتعدد)</label>
            <select id="sub_categories" name="sub_categories[]" multiple size="10" style="min-height:12rem">
                @foreach ($subCategoriesList as $sub)
                    <option value="{{ $sub->id }}" @selected(in_array((int) $sub->id, array_map('intval', $selSubs), true))>
                        {{ $sub->category->nameAr ?? '—' }} — {{ $sub->nameAr }} ({{ $sub->id }})
                    </option>
                @endforeach
            </select>
            <p class="hint">يُحفظ كعلاقات many-to-many مثل لوحة المتجر (.NET: <code>supCategoriesIds</code>).</p>
        </div>

        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem">
                <h2 class="card-title" style="margin:0;border:0;padding:0">الحجم واللون</h2>
                <div style="display:flex;gap:0.35rem">
                    <button type="button" class="btn-icon" id="btn-remove-last-size" title="حذف آخر مقاس" style="background:#475569">−</button>
                    <button type="button" class="btn-icon" id="btn-add-size" title="إضافة مقاس">+</button>
                </div>
            </div>
            <div id="sizes-container">
                @if($p && $p->sizes->isNotEmpty())
                    @foreach($p->sizes as $si => $size)
                        @include('partials.product-edit-size-block', ['size' => $size, 'si' => $si, 'sizeOptions' => $sizeOptions])
                    @endforeach
                @else
                    @include('partials.product-edit-size-block', ['size' => null, 'si' => 0, 'sizeOptions' => $sizeOptions])
                @endif
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">الوسائط (اختياري — تُرفع للمتجر)</h2>
            <div class="upload-grid">
                <div class="upload-zone">
                    <strong>صور عادية</strong>
                    <p>عدة ملفات</p>
                    <input type="file" name="normal_images[]" multiple accept="image/*">
                </div>
                <div class="upload-zone">
                    <strong>صور ثلاثية الأبعاد</strong>
                    <input type="file" name="three_d_images[]" multiple accept="image/*">
                </div>
                <div class="upload-zone">
                    <strong>صور عرض</strong>
                    <input type="file" name="view_images[]" multiple accept="image/*">
                </div>
                <div class="upload-zone">
                    <strong>فيديو</strong>
                    <input type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo">
                </div>
            </div>
            <p class="hint" style="margin-top:0.75rem;text-align:left">
                الفيديو الحالي:
                @if($p?->videoUrl)
                    <a href="{{ $p->videoUrl }}" target="_blank" rel="noopener">عرض الرابط</a>
                @else
                    ليس هناك فيديو حالياً
                @endif
            </p>
        </div>

        <div class="card">
            <h2 class="card-title">حقول إضافية (Laravel)</h2>
            <div class="grid-3">
                <div>
                    <label>أقل سعر بيع</label>
                    <input type="number" name="min_sale_price" step="0.01" value="{{ old('min_sale_price', $p?->min_sale_price ?? '') }}">
                </div>
                <div>
                    <label>تاريخ الدوران</label>
                    <input type="date" name="rotation_date" value="{{ old('rotation_date', optional($p?->rotation_date)->format('Y-m-d')) }}">
                </div>
                <div>
                    <label>سعر price</label>
                    <input type="number" name="price" step="0.01" value="{{ old('price', $p?->price ?? '') }}">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding:0.65rem 1.5rem;font-size:1rem">حفظ محلياً + مزامنة المتجر</button>
    </form>

    @if(session('result'))
        @php $r = session('result'); @endphp
        <div class="card" style="margin-top:1rem">
            <strong>المتجر:</strong>
            @if(!empty($r['ok']))
                <span class="ok">نجاح</span>
            @else
                <span class="fail">فشل</span>
            @endif
            @if(!empty($r['error'])) — {{ $r['error'] }} @endif
            @if(!empty($r['skipped'])) — تخطي @endif
        </div>
    @endif

    @if(session('product_model'))
        @php $op = session('product_model'); @endphp
        <p class="meta" style="margin-top:0.75rem">آخر حفظ: منتج #{{ $op->id }} — مخزون {{ $op->stock }}</p>
    @endif

    @if(session('steps'))
        <h3 style="margin-top:1.25rem;font-size:1rem">سجل</h3>
        <div class="log">@foreach(session('steps') as $row)
[{{ $row['time'] }}] {{ $row['message'] }}
@if(!empty($row['context'])){{ json_encode($row['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}@endif

@endforeach</div>
    @endif
</div>

<template id="tpl-size-block">
    @include('partials.product-edit-size-block', ['size' => null, 'si' => '__SI__', 'sizeOptions' => $sizeOptions])
</template>

<script>
(function () {
    var container = document.getElementById('sizes-container');
    var tpl = document.getElementById('tpl-size-block');
    var idx = container ? container.querySelectorAll('.size-block').length : 1;

    function appendNewSizeBlock() {
        var html = tpl.innerHTML.replace(/__SI__/g, String(idx));
        var div = document.createElement('div');
        div.innerHTML = html.trim();
        var block = div.firstElementChild;
        if (block) {
            block.setAttribute('data-si', String(idx));
            container.appendChild(block);
            idx++;
        }
    }

    document.getElementById('btn-add-size').addEventListener('click', appendNewSizeBlock);

    document.getElementById('btn-remove-last-size').addEventListener('click', function () {
        var blocks = container.querySelectorAll('.size-block');
        if (blocks.length === 0) return;
        blocks[blocks.length - 1].remove();
    });

    document.addEventListener('click', function (e) {
        var addColor = e.target.closest('.btn-add-color');
        if (addColor) {
            var sizeBlock = addColor.closest('.size-block');
            var si = sizeBlock.getAttribute('data-si');
            var wrap = sizeBlock.querySelector('.colors-wrap');
            var cj = wrap.querySelectorAll('.color-fields').length;
            var row = document.createElement('div');
            row.className = 'color-fields';
            row.innerHTML =
                '<input type="hidden" name="sizes[' + si + '][color_sizes][' + cj + '][id]" value="">' +
                '<div><label>اللون بالعربية</label><input type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorAr]" placeholder="اللون بالعربية"></div>' +
                '<div><label>اللون بالإنجليزية</label><input type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorEn]" placeholder="Color in English"></div>' +
                '<div><label>اللون بالعبرية</label><input type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorAbbr]" placeholder="עברית"></div>' +
                '<div><label>الكمية</label><input type="number" name="sizes[' + si + '][color_sizes][' + cj + '][stock]" value="0" min="0"></div>' +
                '<div><label>السعر</label><input type="number" step="0.01" name="sizes[' + si + '][color_sizes][' + cj + '][normailPrice]" value="0" min="0"></div>';
            wrap.appendChild(row);
        }
    });
})();
</script>
</body>
</html>
