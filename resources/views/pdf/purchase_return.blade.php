<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>سند مرتجع شراء {{ $return->number }}</title>
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; direction: rtl; text-align: right; color: #111827; font-size: 12px; }
        .header { border-bottom: 2px solid #6B65BD; padding-bottom: 10px; margin-bottom: 15px; }
        h1 { color: #6B65BD; font-size: 21px; margin: 0; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .meta td { width: 50%; padding: 5px; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th { background: #6B65BD; color: white; padding: 7px; }
        .items td { border: 1px solid #d1d5db; padding: 7px; }
        .total { margin-top: 12px; text-align: left; font-size: 15px; font-weight: bold; }
        .signatures { width: 100%; margin-top: 50px; }
        .signatures td { width: 50%; text-align: center; }
    </style>
</head>
<body>
<div class="header"><h1>Doctor Bike</h1><div>سند مرتجع شراء</div></div>
<table class="meta">
    <tr><td><b>رقم المرتجع:</b> {{ $return->number }}</td><td><b>فاتورة الشراء:</b> #{{ $return->bill_id }}</td></tr>
    <tr><td><b>المورد:</b> {{ optional($return->seller)->name ?? optional($return->customer)->name }}</td><td><b>التاريخ:</b> {{ optional($return->created_at)->format('Y-m-d') }}</td></tr>
    <tr><td><b>الحالة:</b> {{ $return->status }}</td><td><b>العملة:</b> {{ $return->currency }}</td></tr>
</table>
<table class="items">
    <thead><tr><th>#</th><th>الصنف</th><th>المقاس/اللون</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
    <tbody>
    @foreach($return->items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td><td>{{ optional($item->product)->nameAr }}</td>
            <td>{{ optional($item->size)->size }} {{ optional($item->sizeColor)->colorAr }}</td>
            <td>{{ $item->quantity }}</td><td>{{ number_format($item->price, 2) }}</td><td>{{ number_format($item->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="total">الإجمالي: {{ number_format($return->total, 2) }} {{ $return->currency }}</div>
@if($return->reason || $return->notes || $return->note)<p><b>السبب والملاحظات:</b> {{ $return->reason }} {{ $return->notes ?? $return->note }}</p>@endif
<table class="signatures"><tr><td>توقيع المسلّم<br><br>________________</td><td>توقيع المستلم<br><br>________________</td></tr></table>
</body>
</html>
