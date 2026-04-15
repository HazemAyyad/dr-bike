@php
    $si = $si ?? 0;
@endphp
<div class="size-block" data-si="{{ $si }}">
    <input type="hidden" name="sizes[{{ $si }}][id]" value="{{ old('sizes.'.$si.'.id', $size->id ?? '') }}">
    <div style="margin-bottom:0.65rem">
        <label>المقاس (الحجم)</label>
        <input type="text" name="sizes[{{ $si }}][size]" value="{{ old('sizes.'.$si.'.size', $size->size ?? '') }}" placeholder="مثال: 18×9.5×8">
    </div>
    <p style="font-size:0.8rem;color:#64748b;margin:0 0 0.5rem">ألوان هذا المقاس</p>
    <div class="colors-wrap">
        @if($size && $size->colorSizes->isNotEmpty())
            @foreach($size->colorSizes as $cj => $color)
                <div class="color-row">
                    <input type="hidden" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][id]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.id', $color->id) }}">
                    <div>
                        <label>لون</label>
                        <input type="text" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][colorAr]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.colorAr', $color->colorAr ?? '') }}" placeholder="لون">
                    </div>
                    <div>
                        <label>سعر</label>
                        <input type="number" step="0.01" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][normailPrice]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.normailPrice', $color->normailPrice ?? 0) }}">
                    </div>
                    <div>
                        <label>مخزون</label>
                        <input type="number" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][stock]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.stock', $color->stock ?? 0) }}">
                    </div>
                </div>
            @endforeach
        @else
            <div class="color-row">
                <input type="hidden" name="sizes[{{ $si }}][color_sizes][0][id]" value="">
                <div>
                    <label>لون</label>
                    <input type="text" name="sizes[{{ $si }}][color_sizes][0][colorAr]" value="{{ old('sizes.'.$si.'.color_sizes.0.colorAr', '') }}" placeholder="لون">
                </div>
                <div>
                    <label>سعر</label>
                    <input type="number" step="0.01" name="sizes[{{ $si }}][color_sizes][0][normailPrice]" value="{{ old('sizes.'.$si.'.color_sizes.0.normailPrice', 0) }}">
                </div>
                <div>
                    <label>مخزون</label>
                    <input type="number" name="sizes[{{ $si }}][color_sizes][0][stock]" value="{{ old('sizes.'.$si.'.color_sizes.0.stock', 0) }}">
                </div>
            </div>
        @endif
    </div>
    <button type="button" class="btn btn-outline btn-sm btn-add-color" style="margin-top:0.5rem">+ لون</button>
</div>
