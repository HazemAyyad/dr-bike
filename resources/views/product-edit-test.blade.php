<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Doctor Bike — تعديل منتج (اختبار)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.rtl.min.css" rel="stylesheet">
    <style>
        .size-block { border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 1rem; margin-bottom: 0.75rem; background: #f8f9fa; }
        .size-toolbar { display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 0.75rem; }
        .color-subhead { display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; font-weight: 700; margin: 0.75rem 0 0.5rem; padding-top: 0.5rem; border-top: 1px dashed #dee2e6; }
        .color-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem; margin-bottom: 0.65rem; padding-bottom: 0.65rem; border-bottom: 1px solid #e9ecef; }
        .color-fields:last-child { border-bottom: none; }
        .upload-zone { border: 2px dashed #ced4da; border-radius: 0.5rem; min-height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 0.75rem; background: #fafafa; }
        .drop-zone:hover { background: #f8f9fa; border-color: #86b7fe !important; }
        .drop-zone.border-primary { border-color: #0d6efd !important; background: #eef5ff; }
        .select2-container { z-index: 2055; }
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
            <select id="product-picker" class="form-select select2-field" @if($productsForPicker->isEmpty()) disabled @endif>
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
                    <select class="form-select select2-field" id="is_sold_with_paper" name="is_sold_with_paper" required>
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
            <label class="form-label" for="sub_categories">اختر فئة أو أكثر</label>
            <select class="form-select select2-field" id="sub_categories" name="sub_categories[]" multiple="multiple">
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
            <h2 class="h6 border-bottom pb-2 mb-3">الوسائط</h2>
            <p class="small text-muted mb-3">الصور المخزنة تظهر تحت كل قسم؛ الحذف فوري عبر المتجر. الرفع الجديد يُرسل مع حفظ النموذج.</p>

            @include('partials.product-edit-media-type', [
                'title' => 'صور عادية',
                'kind' => 'normal',
                'inputName' => 'normal_images[]',
                'inputId' => 'input-normal-images',
                'collection' => $p?->normalImages ?? collect(),
                'resolveMediaUrl' => $resolveMediaUrl,
            ])
            @include('partials.product-edit-media-type', [
                'title' => 'صور عرض',
                'kind' => 'view',
                'inputName' => 'view_images[]',
                'inputId' => 'input-view-images',
                'collection' => $p?->viewImages ?? collect(),
                'resolveMediaUrl' => $resolveMediaUrl,
            ])
            @include('partials.product-edit-media-type', [
                'title' => 'صور ثلاثية الأبعاد',
                'kind' => 'three_d',
                'inputName' => 'three_d_images[]',
                'inputId' => 'input-3d-images',
                'collection' => $p?->image3d ?? collect(),
                'resolveMediaUrl' => $resolveMediaUrl,
            ])

            <div class="mb-2">
                <h6 class="fw-bold">فيديو</h6>
                <p class="small text-muted mb-2">
                    @php $vUrl = $p?->videoUrl ? $resolveMediaUrl($p->videoUrl) : null; @endphp
                    الفيديو الحالي:
                    @if ($vUrl)
                        <a href="{{ $vUrl }}" target="_blank" rel="noopener">عرض الرابط</a>
                    @else
                        لا يوجد فيديو مخزن
                    @endif
                </p>
                <div class="drop-zone rounded-3 border border-2 border-dashed p-3 text-center bg-white" data-target="input-product-video" style="min-height: 88px; cursor: pointer;">
                    <p class="mb-1 small fw-semibold">اسحب ملف الفيديو هنا أو انقر</p>
                    <p class="mb-0 text-muted small drop-zone-hint"></p>
                    <input type="file" class="d-none file-input-staged" id="input-product-video" name="video" accept="video/mp4,video/quicktime,video/x-msvideo">
                </div>
            </div>
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

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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
            if (window.jQuery && window.initSelect2Size) {
                window.initSelect2Size(block);
            }
            idx++;
        }
    }

    document.getElementById('btn-add-size').addEventListener('click', appendNewSizeBlock);

    document.getElementById('btn-remove-last-size').addEventListener('click', function () {
        var blocks = container.querySelectorAll('.size-block');
        if (blocks.length === 0) return;
        var last = blocks[blocks.length - 1];
        if (window.jQuery) {
            jQuery(last).find('.select2-size').each(function () {
                if (jQuery(this).data('select2')) {
                    jQuery(this).select2('destroy');
                }
            });
        }
        last.remove();
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
<script>
(function ($) {
    var deleteImageUrl = @json(route('test.product-edit.delete-image'));
    var productEditBase = @json(url('/test/product-edit'));

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    window.initSelect2Size = function (root) {
        var $root = root ? $(root) : $(document);
        $root.find('.select2-size').each(function () {
            var $el = $(this);
            if ($el.data('select2')) {
                return;
            }
            $el.select2({
                theme: 'bootstrap-5',
                width: '100%',
                dir: 'rtl',
                placeholder: 'اختر الحجم',
                allowClear: true,
            });
        });
    };

    $(function () {
        $('.select2-field').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dir: 'rtl',
        });

        initSelect2Size(document);

        $('#product-picker').on('change', function () {
            var v = $(this).val();
            if (!v || $(this).prop('disabled')) {
                return;
            }
            window.location.href = productEditBase + '?product_id=' + encodeURIComponent(v);
        });

        function setInputFiles(input, fileList) {
            var dt = new DataTransfer();
            for (var i = 0; i < fileList.length; i++) {
                dt.items.add(fileList[i]);
            }
            input.files = dt.files;
        }

        function hintForInput(zone, input) {
            var hint = zone.querySelector('.drop-zone-hint');
            if (!hint) {
                return;
            }
            if (!input.files || input.files.length === 0) {
                hint.textContent = '';
                return;
            }
            var names = [];
            for (var i = 0; i < input.files.length; i++) {
                names.push(input.files[i].name);
            }
            hint.textContent = names.join('، ');
        }

        function wireDropZone(zone) {
            var targetId = zone.getAttribute('data-target');
            if (!targetId) {
                return;
            }
            var input = document.getElementById(targetId);
            if (!input) {
                return;
            }

            zone.addEventListener('click', function (e) {
                if (e.target.closest('.btn-delete-stored-img')) {
                    return;
                }
                input.click();
            });

            input.addEventListener('change', function () {
                hintForInput(zone, input);
            });

            ['dragenter', 'dragover'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    zone.classList.add('border-primary');
                });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    zone.classList.remove('border-primary');
                });
            });
            zone.addEventListener('drop', function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (!files || files.length === 0) {
                    return;
                }
                if (input.multiple) {
                    setInputFiles(input, files);
                } else {
                    setInputFiles(input, [files[0]]);
                }
                hintForInput(zone, input);
            });
        }

        document.querySelectorAll('.drop-zone').forEach(wireDropZone);

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-delete-stored-img');
            if (!btn) {
                return;
            }
            e.preventDefault();
            var kind = btn.getAttribute('data-kind');
            var imageId = btn.getAttribute('data-id');
            var pidEl = document.querySelector('input[name="product_id"]');
            var productId = pidEl ? pidEl.value : '';
            if (!kind || !imageId || !productId) {
                return;
            }

            Swal.fire({
                title: 'تأكيد الحذف',
                text: 'سيتم حذف الصورة من المتجر ومن قاعدة البيانات. لا يمكن التراجع.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'حذف',
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
                focusCancel: true,
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                fetch(deleteImageUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        product_id: parseInt(productId, 10),
                        image_id: imageId,
                        kind: kind,
                    }),
                })
                    .then(function (res) {
                        return res.json().then(function (data) {
                            return { ok: res.ok, status: res.status, data: data };
                        });
                    })
                    .then(function (out) {
                        if (out.ok && out.data && out.data.ok) {
                            var tile = btn.closest('.existing-img-tile');
                            if (tile) {
                                tile.remove();
                            }
                            Swal.fire({ icon: 'success', title: 'تم الحذف', toast: true, position: 'top-start', showConfirmButton: false, timer: 2500 });
                        } else {
                            var msg = (out.data && out.data.message) ? out.data.message : 'فشل الحذف';
                            Swal.fire({ icon: 'error', title: msg });
                        }
                    })
                    .catch(function () {
                        Swal.fire({ icon: 'error', title: 'خطأ في الاتصال' });
                    });
            });
        });
    });
})(jQuery);
</script>
</body>
</html>
