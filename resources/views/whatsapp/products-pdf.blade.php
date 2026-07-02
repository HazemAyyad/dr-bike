<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #172b26; direction: rtl; }
        .header { border-bottom: 3px solid #075e54; padding-bottom: 9px; margin-bottom: 12px; }
        .header h1 { color: #075e54; font-size: 25px; margin: 0; }
        .header p { color: #55706a; margin: 4px 0 0; font-size: 12px; }
        .product { border: 1px solid #cfe0dc; border-radius: 12px; margin-bottom: 10px; padding: 10px; page-break-inside: avoid; }
        .image-cell { width: 37%; vertical-align: top; text-align: center; }
        .details-cell { width: 63%; vertical-align: top; padding-right: 12px; }
        .image { width: 62mm; height: 49mm; object-fit: contain; border: 1px solid #e3ece9; border-radius: 8px; }
        .placeholder { width: 62mm; height: 49mm; background: #eef4f2; color: #78908a; text-align: center; line-height: 49mm; }
        h2 { margin: 0 0 8px; font-size: 19px; color: #102a25; }
        .price { color: #008069; font-size: 18px; font-weight: bold; margin-bottom: 7px; }
        .line { font-size: 12px; margin: 4px 0; }
        .description { font-size: 11px; color: #49615b; margin-top: 7px; line-height: 1.6; }
        .footer { text-align: center; color: #6d817c; font-size: 10px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>
<div class="header">
    <h1>منتجات دكتور بايك</h1>
    <p>تفاصيل المنتجات المختارة — {{ $generatedAt }}</p>
</div>

@foreach($products as $product)
    <div class="product">
        <table>
            <tr>
                <td class="image-cell">
                    @if($product['image'])
                        <img class="image" src="{{ $product['image'] }}" alt="">
                    @else
                        <div class="placeholder">لا توجد صورة</div>
                    @endif
                </td>
                <td class="details-cell">
                    <h2>{{ $product['name'] }}</h2>
                    <div class="price">السعر: {{ $product['price'] }} ₪</div>
                    <div class="line">رمز المنتج: {{ $product['code'] ?: '—' }}</div>
                    <div class="line">الموديل: {{ $product['model'] ?: '—' }}</div>
                    <div class="line">التصنيف: {{ $product['category'] ?: '—' }}</div>
                    <div class="line">المتوفر: {{ $product['stock'] }}</div>
                    @if($product['description'])
                        <div class="description">{{ $product['description'] }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endforeach

<div class="footer">د. بايك لخدمات وقطع الدراجات</div>
</body>
</html>
