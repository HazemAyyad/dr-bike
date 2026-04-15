{{-- $title, $kind (normal|view|three_d), $inputName, $inputId, $collection, $resolveMediaUrl --}}
@php
    $collection = $collection ?? collect();
@endphp
<div class="mb-4 pb-3 border-bottom product-media-block">
    <h6 class="fw-bold mb-2">{{ $title }}</h6>
    <div class="row g-2 mb-3 existing-images-grid">
        @foreach ($collection as $img)
            @php $u = $resolveMediaUrl($img->imageUrl); @endphp
            @if ($u)
                <div class="col-6 col-md-3 col-lg-2 existing-img-tile" data-image-id="{{ $img->id }}">
                    <div class="position-relative border rounded overflow-hidden bg-white shadow-sm">
                        <img src="{{ $u }}" alt="" class="w-100 d-block" style="height: 110px; object-fit: cover;" loading="lazy">
                        <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 py-0 px-2 lh-1 btn-delete-stored-img"
                            data-kind="{{ $kind }}" data-id="{{ $img->id }}" title="حذف">×</button>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    <div class="drop-zone rounded-3 border border-2 border-dashed p-3 text-center bg-white position-relative" data-target="{{ $inputId }}" style="min-height: 96px; cursor: pointer;">
        <p class="mb-1 small fw-semibold mb-0">اسحب الصور هنا أو انقر للاختيار</p>
        <p class="mb-0 text-muted small drop-zone-hint"></p>
        <input type="file" class="d-none file-input-staged" id="{{ $inputId }}" name="{{ $inputName }}" multiple accept="image/*">
    </div>
</div>
