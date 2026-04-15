@php
    $si = $si ?? 0;
    $opts = isset($sizeOptions) ? collect($sizeOptions) : collect();
    $curSize = old('sizes.'.$si.'.size', $size->size ?? '');
@endphp
<div class="size-block" data-si="{{ $si }}">
    <div class="size-toolbar">
        <div style="flex:1;min-width:200px">
            <label class="form-label">اختر الحجم</label>
            <select name="sizes[{{ $si }}][size]" class="form-select form-select-sm size-select">
                <option value="">اختر الحجم</option>
                @foreach ($opts as $opt)
                    <option value="{{ $opt }}" @selected((string) $curSize === (string) $opt)>{{ $opt }}</option>
                @endforeach
                @if ($curSize !== '' && $curSize !== null && ! $opts->contains($curSize))
                    <option value="{{ $curSize }}" selected>{{ $curSize }}</option>
                @endif
            </select>
        </div>
    </div>
    <input type="hidden" name="sizes[{{ $si }}][id]" value="{{ old('sizes.'.$si.'.id', $size->id ?? '') }}">

    <div class="color-subhead">
        <span>اللون والكمية</span>
        <button type="button" class="btn btn-sm btn-primary btn-add-color" title="إضافة لون">+</button>
    </div>
    <div class="colors-wrap">
        @if ($size && $size->colorSizes->isNotEmpty())
            @foreach ($size->colorSizes as $cj => $color)
                <div class="color-fields">
                    <input type="hidden" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][id]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.id', $color->id) }}">
                    <div>
                        <label class="form-label small">اللون بالعربية</label>
                        <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][colorAr]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.colorAr', $color->colorAr ?? '') }}" placeholder="اللون بالعربية">
                    </div>
                    <div>
                        <label class="form-label small">اللون بالإنجليزية</label>
                        <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][colorEn]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.colorEn', $color->colorEn ?? '') }}" placeholder="Color in English">
                    </div>
                    <div>
                        <label class="form-label small">اللون بالعبرية</label>
                        <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][colorAbbr]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.colorAbbr', $color->colorAbbr ?? '') }}" placeholder="עברית">
                    </div>
                    <div>
                        <label class="form-label small">الكمية</label>
                        <input type="number" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][stock]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.stock', $color->stock ?? 0) }}" min="0">
                    </div>
                    <div>
                        <label class="form-label small">السعر</label>
                        <input type="number" class="form-control form-control-sm" step="0.01" name="sizes[{{ $si }}][color_sizes][{{ $cj }}][normailPrice]" value="{{ old('sizes.'.$si.'.color_sizes.'.$cj.'.normailPrice', $color->normailPrice ?? 0) }}" min="0">
                    </div>
                </div>
            @endforeach
        @else
            <div class="color-fields">
                <input type="hidden" name="sizes[{{ $si }}][color_sizes][0][id]" value="">
                <div>
                    <label class="form-label small">اللون بالعربية</label>
                    <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][0][colorAr]" value="{{ old('sizes.'.$si.'.color_sizes.0.colorAr', '') }}" placeholder="اللون بالعربية">
                </div>
                <div>
                    <label class="form-label small">اللون بالإنجليزية</label>
                    <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][0][colorEn]" value="{{ old('sizes.'.$si.'.color_sizes.0.colorEn', '') }}" placeholder="Color in English">
                </div>
                <div>
                    <label class="form-label small">اللون بالعبرية</label>
                    <input type="text" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][0][colorAbbr]" value="{{ old('sizes.'.$si.'.color_sizes.0.colorAbbr', '') }}" placeholder="עברית">
                </div>
                <div>
                    <label class="form-label small">الكمية</label>
                    <input type="number" class="form-control form-control-sm" name="sizes[{{ $si }}][color_sizes][0][stock]" value="{{ old('sizes.'.$si.'.color_sizes.0.stock', 0) }}" min="0">
                </div>
                <div>
                    <label class="form-label small">السعر</label>
                    <input type="number" class="form-control form-control-sm" step="0.01" name="sizes[{{ $si }}][color_sizes][0][normailPrice]" value="{{ old('sizes.'.$si.'.color_sizes.0.normailPrice', 0) }}" min="0">
                </div>
            </div>
        @endif
    </div>
</div>
