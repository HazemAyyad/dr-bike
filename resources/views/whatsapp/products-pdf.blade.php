<!doctype html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #24233a; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        .page-break { page-break-after: always; }
        .header { border-bottom: 3px solid #6b65bd; padding-bottom: 8px; margin-bottom: 12px; }
        .header-table { direction: ltr; }
        .logo-cell { width: 29%; text-align: left; vertical-align: middle; }
        .number-cell { width: 31%; text-align: center; vertical-align: middle; color: #625cae; }
        .title-cell { width: 40%; text-align: right; vertical-align: middle; direction: rtl; }
        .logo { width: 43mm; height: auto; }
        .title { margin: 0; color: #6b65bd; font-size: 21px; font-weight: bold; }
        .subtitle { margin: 4px 0 0; color: #6d6b7c; font-size: 10px; }
        .offer-number { font-size: 10px; margin-top: 3px; }
        .offer-label { font-size: 12px; font-weight: bold; }
        .qr { width: 18mm; height: 18mm; display: block; margin: 0 auto 2px; }
        .info { direction: rtl; background: #f4f2ff; border: 1px solid #d9d5fa; border-radius: 9px; margin-bottom: 13px; }
        .info td { width: 50%; padding: 7px 10px; text-align: right; vertical-align: top; }
        .info .label { color: #706c86; font-size: 9px; }
        .info .value { color: #292744; font-size: 11px; font-weight: bold; margin-top: 2px; }
        .section-title { direction: rtl; text-align: right; color: #6b65bd; font-size: 15px; font-weight: bold; margin: 0 0 7px; }
        .cards { direction: rtl; table-layout: fixed; border-collapse: separate; border-spacing: 3mm 2.5mm; width: 100%; }
        .card-cell { width: 50%; height: 48mm; padding: 0; vertical-align: top; }
        .empty-card { border: 0; }
        .product-card { height: 48mm; border: 1px solid #d8d5ea; border-radius: 9px; background: #ffffff; overflow: hidden; page-break-inside: avoid; }
        .product-card-table { direction: ltr; height: 100%; table-layout: fixed; }
        .product-image-cell { width: 39%; background: #f8f7fc; text-align: center; vertical-align: middle; border-right: 1px solid #e5e2f1; }
        .product-details { width: 61%; padding: 6px 7px; direction: rtl; text-align: right; vertical-align: top; }
        .thumb { width: 29mm; height: 35mm; object-fit: contain; }
        .placeholder { color: #9793aa; font-size: 9px; }
        .product-index { color: #6b65bd; font-size: 9px; font-weight: bold; margin-bottom: 2px; }
        .product-name { color: #24233a; font-size: 12px; font-weight: bold; line-height: 1.45; margin-bottom: 4px; }
        .variant-label { color: #706d82; font-size: 9px; margin: -1px 0 4px; }
        .product-price { color: #6b65bd; font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        .quantity { display: inline-block; margin-top: 5px; padding: 3px 6px; color: #35324c; background: #eeecfb; border-radius: 5px; font-size: 9px; font-weight: bold; }
        .line-total { margin-top: 4px; color: #24233a; font-size: 9px; font-weight: bold; }
        .total-box { direction: rtl; width: 45%; margin: 10px 0 0 auto; border: 2px solid #6b65bd; border-radius: 8px; }
        .total-box td { padding: 8px 10px; font-size: 13px; font-weight: bold; }
        .total-value { color: #6b65bd; text-align: left; }
        .notice { direction: rtl; text-align: right; margin-top: 13px; padding: 9px 11px; background: #fff7df; border: 1px solid #efc75e; border-radius: 8px; color: #77550a; line-height: 1.7; }
        .notice strong { color: #9a6900; }
        .footer { border-top: 1px solid #dedbea; text-align: center; color: #777489; font-size: 9px; margin-top: 14px; padding-top: 7px; }
    </style>
</head>
<body>
@php($pages = array_chunk($products, 6))
@foreach($pages as $pageIndex => $pageProducts)
    <div class="offer-page {{ $loop->last ? '' : 'page-break' }}">
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="logo-cell"><img class="logo" src="{{ $logo }}" alt="Doctor Bike"></td>
                    <td class="number-cell">
                        <img class="qr" src="{{ $qr }}" alt="">
                        <div class="offer-label">رقم العرض</div>
                        <div class="offer-number">{{ $offerNumber }}</div>
                    </td>
                    <td class="title-cell">
                        <div class="title">دكتور بايك - عرض منتجات</div>
                        <div class="subtitle">قطع وخدمات الدراجات</div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="info">
            <tr>
                <td>
                    <div class="label">اسم الزبون</div>
                    <div class="value">{{ $customerName }}</div>
                </td>
                <td>
                    <div class="label">تاريخ العرض</div>
                    <div class="value">{{ $generatedAt }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">رقم الهاتف</div>
                    <div class="value">{{ $customerPhone }}</div>
                </td>
                <td>
                    <div class="label">الصفحة</div>
                    <div class="value">{{ $pageIndex + 1 }} / {{ count($pages) }}</div>
                </td>
            </tr>
        </table>

        <div class="section-title">تفاصيل العرض</div>
        <table class="cards">
            @foreach(array_chunk($pageProducts, 2) as $rowIndex => $rowProducts)
                <tr>
                    @foreach($rowProducts as $columnIndex => $product)
                        @php($productIndex = ($pageIndex * 6) + ($rowIndex * 2) + $columnIndex + 1)
                        <td class="card-cell">
                            <div class="product-card">
                                <table class="product-card-table">
                                    <tr>
                                        <td class="product-image-cell">
                                            @if($product['image'])
                                                <img class="thumb" src="{{ $product['image'] }}" alt="">
                                            @else
                                                <div class="placeholder">لا توجد صورة</div>
                                            @endif
                                        </td>
                                        <td class="product-details">
                                            <div class="product-index">منتج #{{ $productIndex }}</div>
                                            <div class="product-name">{{ $product['name'] }}</div>
                                            @if($product['variant_label'])
                                                <div class="variant-label">{{ $product['variant_label'] }}</div>
                                            @endif
                                            <div class="product-price">السعر: {{ number_format($product['unit_price'], 2) }} ₪</div>
                                            <span class="quantity">الكمية: {{ $product['quantity'] }}</span>
                                            <div class="line-total">الإجمالي: {{ number_format($product['total'], 2) }} ₪</div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach
                    @if(count($rowProducts) === 1)
                        <td class="card-cell empty-card"></td>
                    @endif
                </tr>
            @endforeach
        </table>

        @if($loop->last)
            <table class="total-box">
                <tr>
                    <td>الإجمالي الكلي</td>
                    <td class="total-value">{{ number_format($grandTotal, 2) }} ₪</td>
                </tr>
            </table>

            <div class="notice">
                <strong>ملاحظة مهمة:</strong>
                تم إعداد هذا العرض بمساعدة الذكاء الاصطناعي، وقد توجد أخطاء في الأسعار أو الكميات. يرجى اعتماد التأكيد النهائي من موظف دكتور بايك.
            </div>
        @endif

        <div class="footer">دكتور بايك لخدمات وقطع الدراجات — 6 منتجات كحد أقصى في كل صفحة</div>
    </div>
@endforeach
</body>
</html>
