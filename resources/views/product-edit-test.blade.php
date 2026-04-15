<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doctor Bike — تعديل منتج (اختبار)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        .size-block { border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 1rem; margin-bottom: 0.75rem; background: #f8f9fa; }
        .size-toolbar { display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 0.75rem; }
        .color-subhead { display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; font-weight: 700; margin: 0.75rem 0 0.5rem; padding-top: 0.5rem; border-top: 1px dashed #dee2e6; }
        .color-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem; margin-bottom: 0.65rem; padding-bottom: 0.65rem; border-bottom: 1px solid #e9ecef; }
        .color-fields:last-child { border-bottom: none; }
        .upload-zone { border: 2px dashed #ced4da; border-radius: 0.5rem; min-height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 0.75rem; background: #fafafa; }
        .log { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; font-size: 0.8rem; white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow: auto; }
        .btn-icon { width: 2rem; height: 2rem; border-radius: 0.375rem; background: #0d6efd; color: #fff; border: none; cursor: pointer; font-size: 1.1rem; line-height: 1; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">تعديل منتج</h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('test.products-list') }}">كل المنتجات</a>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('test.store-sync') }}">اختبار المخزون</a>
            <a class="btn btn-outline-primary btn-sm" href="{{ route('test.product-edit', ['product_id' => $prefill->id]) }}">تحديث الصفحة</a>
        </div>
    </div>

    <div class="alert alert-warning small">
        صفحة تجريبية. رفع الصور/الفيديو عبر <code>multipart</code> إلى <code>ManageItem</code>.
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>تحقق من الحقول:</strong>
            <ul class="mb-0 mt-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @php
        $p = $prefill;
        $selSubs = old('sub_categories', $selectedSubCategoryIds ?? []);
        if (! is_array($selSubs)) {
            $selSubs = $selSubs !== null && $selSubs !== '' ? [(int) $selSubs] : [];
        }
    @endphp

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <label class="form-label fw-semibold" for="product-picker">اختر المنتج للتعديل</label>
            <select id="product-picker" class="form-select" @if($productsForPicker->isEmpty()) disabled @endif>
                @forelse ($productsForPicker as $pr)
                    <option value="{{ $pr->id }}" @selected((string) $p->id === (string) $pr->id)>
                        #{{ $pr->id }} — {{ $pr->nameAr }} (مخزون {{ $pr->stock }})
                    </option>
                @empty
                    <option value="">لا توجد منتجات في القاعدة</option>
                @endforelse
            </select>
            <p class="small text-muted mb-0 mt-2">
                قائمة الأحجام تُدمج من <code>config('store.size_options')</code> + قيم مميزة من جدول <code>sizes</code>؛
                <strong>ليست مرتبطة بالتصنيف</strong> في قاعدة Laravel (نص حر لكل منتج، كما في .NET <code>ItemSize.Size</code>).
            </p>
        </div>
    </div>

    @php
        $hasStoredMedia = $p->normalImages->isNotEmpty() || $p->viewImages->isNotEmpty() || $p->image3d->isNotEmpty();
    @endphp
    @if ($hasStoredMedia)
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 border-bottom pb-2 mb-3">صور مخزنة حالياً</h2>
                @if ($p->normalImages->isNotEmpty())
                    <p class="small fw-bold mb-2">صور عادية</p>
                    <div class="row g-2 mb-3">
                        @foreach ($p->normalImages as $img)
                            @php $u = $resolveMediaUrl($img->imageUrl); @endphp
                            @if ($u)
                                <div class="col-6 col-md-3">
                                    <a href="{{ $u }}" target="_blank" rel="noopener" class="d-block border rounded overflow-hidden bg-white">
                                        <img src="{{ $u }}" alt="" class="img-fluid w-100" style="object-fit: cover; max-height: 140px;" loading="lazy">
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($p->viewImages->isNotEmpty())
                    <p class="small fw-bold mb-2">صور عرض</p>
                    <div class="row g-2 mb-3">
                        @foreach ($p->viewImages as $img)
                            @php $u = $resolveMediaUrl($img->imageUrl); @endphp
                            @if ($u)
                                <div class="col-6 col-md-3">
                                    <a href="{{ $u }}" target="_blank" rel="noopener" class="d-block border rounded overflow-hidden bg-white">
                                        <img src="{{ $u }}" alt="" class="img-fluid w-100" style="object-fit: cover; max-height: 140px;" loading="lazy">
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($p->image3d->isNotEmpty())
                    <p class="small fw-bold mb-2">صور ثلاثية الأبعاد</p>
                    <div class="row g-2">
                        @foreach ($p->image3d as $img)
                            @php $u = $resolveMediaUrl($img->imageUrl); @endphp
                            @if ($u)
                                <div class="col-6 col-md-3">
                                    <a href="{{ $u }}" target="_blank" rel="noopener" class="d-block border rounded overflow-hidden bg-white">
                                        <img src="{{ $u }}" alt="" class="img-fluid w-100" style="object-fit: cover; max-height: 140px;" loading="lazy">
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    <form method="post" action="{{ route('test.product-edit.run') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="product_id" value="{{ old('product_id', $p->id) }}">

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">بيانات المنتج</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">الاسم بالعربية *</label>
                    <input type="text" class="form-control" name="nameAr" required value="{{ old('nameAr', $p?->nameAr ?? '') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الاسم بالإنجليزية</label>
                    <input type="text" class="form-control" name="nameEng" value="{{ old('nameEng', $p?->nameEng ?? '') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">الاسم بالعبرية</label>
                    <input type="text" class="form-control" name="nameAbree" value="{{ old('nameAbree', $p?->nameAbree ?? '') }}">
                </div>
            </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">أسعار ومخزون</h2>
            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">سنة الصنع</label>
                    <input type="number" class="form-control" name="manufactureYear" min="0" max="2100" value="{{ old('manufactureYear', $p?->manufactureYear ?? '') }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">الخصم *</label>
                    <input type="number" class="form-control" name="discount" step="0.01" required value="{{ old('discount', $p?->discount ?? 0) }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">السعر القطاعي *</label>
                    <input type="number" class="form-control" name="normailPrice" step="0.01" required value="{{ old('normailPrice', $p?->normailPrice ?? '') }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">السعر جملة</label>
                    <input type="number" class="form-control" name="wholesalePrice" step="0.01" value="{{ old('wholesalePrice', $p?->wholesalePrice ?? 0) }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">العدد / المخزون</label>
                    <input type="number" class="form-control" name="stock" min="0" value="{{ old('stock', $p?->stock ?? '') }}">
                    <p class="small text-muted mb-0">إن وُجدت ألوان بالأسفل يُحسب المجموع منها للمتجر.</p>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">تقييم rate</label>
                    <input type="number" class="form-control" name="rate" step="0.1" value="{{ old('rate', $p?->rate ?? 4) }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">موديل</label>
                    <input type="text" class="form-control" name="model" value="{{ old('model', $p?->model ?? '') }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">حد أدنى مخزون *</label>
                    <input type="number" class="form-control" name="min_stock" step="0.01" required value="{{ old('min_stock', $p?->min_stock ?? 0) }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">يُباع مع ورق *</label>
                    <select class="form-select" name="is_sold_with_paper" required>
                        <option value="1" @selected(old('is_sold_with_paper', $p?->is_sold_with_paper ?? 1) == 1)>نعم</option>
                        <option value="0" @selected(old('is_sold_with_paper', $p?->is_sold_with_paper ?? 1) == 0)>لا</option>
                    </select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-3">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="isShow" value="1" id="chkShow" @checked(old('isShow', $p?->isShow ?? true))><label class="form-check-label" for="chkShow">معروض</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="isNewItem" value="1" id="chkNew" @checked(old('isNewItem', $p?->isNewItem ?? true))><label class="form-check-label" for="chkNew">جديد</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="isMoreSales" value="1" id="chkSales" @checked(old('isMoreSales', $p?->isMoreSales ?? true))><label class="form-check-label" for="chkSales">أكثر مبيعاً</label></div>
            </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">الأوصاف</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">الوصف عربي *</label>
                    <textarea class="form-control" name="descriptionAr" rows="3" required>{{ old('descriptionAr', $p?->descriptionAr ?? '') }}</textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">الوصف إنجليزي</label>
                    <textarea class="form-control" name="descriptionEng" rows="3">{{ old('descriptionEng', $p?->descriptionEng ?? '') }}</textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">الوصف عبري</label>
                    <textarea class="form-control" name="descriptionAbree" rows="3">{{ old('descriptionAbree', $p?->descriptionAbree ?? '') }}</textarea>
                </div>
            </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">التصنيف — فئات فرعية</h2>
            <label class="form-label" for="sub_categories">اختر فئة أو أكثر (Ctrl + نقر للمتعدد)</label>
            <select class="form-select" id="sub_categories" name="sub_categories[]" multiple size="10" style="min-height:12rem">
                @foreach ($subCategoriesList as $sub)
                    <option value="{{ $sub->id }}" @selected(in_array((int) $sub->id, array_map('intval', $selSubs), true))>
                        {{ $sub->category->nameAr ?? '—' }} — {{ $sub->nameAr }} ({{ $sub->id }})
                    </option>
                @endforeach
            </select>
            <p class="small text-muted mb-0 mt-2">يُحفظ كعلاقات many-to-many مثل لوحة المتجر (<code>supCategoriesIds</code>).</p>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <h2 class="h6 mb-0">الحجم واللون</h2>
                <div class="d-flex gap-1">
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
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">الوسائط (رفع جديد — يُرسل للمتجر)</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="upload-zone">
                        <strong>صور عادية</strong>
                        <p class="small text-muted mb-2">عدة ملفات</p>
                        <input type="file" class="form-control form-control-sm" name="normal_images[]" multiple accept="image/*">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="upload-zone">
                        <strong>صور ثلاثية الأبعاد</strong>
                        <input type="file" class="form-control form-control-sm" name="three_d_images[]" multiple accept="image/*">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="upload-zone">
                        <strong>صور عرض</strong>
                        <input type="file" class="form-control form-control-sm" name="view_images[]" multiple accept="image/*">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="upload-zone">
                        <strong>فيديو</strong>
                        <input type="file" class="form-control form-control-sm" name="video" accept="video/mp4,video/quicktime,video/x-msvideo">
                    </div>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0">
                الفيديو الحالي:
                @php $vUrl = $p?->videoUrl ? $resolveMediaUrl($p->videoUrl) : null; @endphp
                @if($vUrl)
                    <a href="{{ $vUrl }}" target="_blank" rel="noopener">عرض الرابط</a>
                @else
                    ليس هناك فيديو حالياً
                @endif
            </p>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
            <h2 class="h6 border-bottom pb-2 mb-3">حقول إضافية (Laravel)</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">أقل سعر بيع</label>
                    <input type="number" class="form-control" name="min_sale_price" step="0.01" value="{{ old('min_sale_price', $p?->min_sale_price ?? '') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">تاريخ الدوران</label>
                    <input type="date" class="form-control" name="rotation_date" value="{{ old('rotation_date', optional($p?->rotation_date)->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">سعر price</label>
                    <input type="number" class="form-control" name="price" step="0.01" value="{{ old('price', $p?->price ?? '') }}">
                </div>
            </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg mb-4">حفظ محلياً + مزامنة المتجر</button>
    </form>

    @if(session('result'))
        @php $r = session('result'); @endphp
        <div class="alert {{ !empty($r['ok']) ? 'alert-success' : 'alert-danger' }}">
            <strong>المتجر:</strong>
            @if(!empty($r['ok']))
                نجاح
            @else
                فشل
            @endif
            @if(!empty($r['error'])) — {{ $r['error'] }} @endif
            @if(!empty($r['skipped'])) — تخطي @endif
        </div>
    @endif

    @if(session('product_model'))
        @php $op = session('product_model'); @endphp
        <p class="small text-muted">آخر حفظ: منتج #{{ $op->id }} — مخزون {{ $op->stock }}</p>
    @endif

    @if(session('steps'))
        <h3 class="h6 mt-3">سجل</h3>
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
                '<div><label class="form-label small">اللون بالعربية</label><input class="form-control form-control-sm" type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorAr]" placeholder="اللون بالعربية"></div>' +
                '<div><label class="form-label small">اللون بالإنجليزية</label><input class="form-control form-control-sm" type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorEn]" placeholder="Color in English"></div>' +
                '<div><label class="form-label small">اللون بالعبرية</label><input class="form-control form-control-sm" type="text" name="sizes[' + si + '][color_sizes][' + cj + '][colorAbbr]" placeholder="עברית"></div>' +
                '<div><label class="form-label small">الكمية</label><input class="form-control form-control-sm" type="number" name="sizes[' + si + '][color_sizes][' + cj + '][stock]" value="0" min="0"></div>' +
                '<div><label class="form-label small">السعر</label><input class="form-control form-control-sm" type="number" step="0.01" name="sizes[' + si + '][color_sizes][' + cj + '][normailPrice]" value="0" min="0"></div>';
            wrap.appendChild(row);
        }
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var sel = document.getElementById('product-picker');
    if (!sel || sel.disabled) return;
    var base = @json(url('/test/product-edit'));
    sel.addEventListener('change', function () {
        var v = this.value;
        if (!v) return;
        window.location.href = base + '?product_id=' + encodeURIComponent(v);
    });
})();
</script>
</body>
</html>
