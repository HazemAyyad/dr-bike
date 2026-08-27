<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>فاتورة مرتجع شراء {{ $return->number }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; direction: rtl; text-align: right; }
        .header { width: 100%; border-bottom: 1.3px solid #6B65BD; padding-bottom: 10px; margin-bottom: 10px; }
        .header-table, .header-table td { border: none; margin: 0; padding: 0; width: 100%; }
        .header-table { direction: ltr; }
        .header-table td { direction: rtl; }
        .brand-title { margin: 0; color: #6B65BD; font-size: 21px; font-weight: bold; }
        .logo { height: 88px; width: auto; }
        .invoice-title { text-align: center; font-size: 16px; font-weight: bold; margin: 10px 0 8px; }
        .meta-box { border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; }
        .meta-table, .items-table, .totals-table, .settlements-table { width: 100%; border-collapse: collapse; }
        .meta-table { direction: rtl; }
        .meta-table td { border: none; padding: 3px 0; vertical-align: top; width: 50%; direction: rtl; text-align: right; }
        .meta-table td.meta-right { padding-left: 16px; }
        .meta-table td.meta-left { padding-right: 16px; }
        .label { font-weight: bold; color: #111827; }
        .value { color: #374151; }
        .meta-line { width: auto; border-collapse: collapse; direction: ltr; display: inline-table; margin-left: auto; margin-right: 0; }
        .meta-line td { border: none; padding: 0; width: auto; vertical-align: baseline; }
        .meta-line .meta-label-cell { width: auto; white-space: nowrap; text-align: right; padding-left: 8px; }
        .meta-line .meta-value-cell { text-align: right; white-space: nowrap; padding-right: 2px; }
        .ltr { direction: ltr; unicode-bidi: embed; display: inline-block; }
        .items-table { margin-top: 4px; border: 1px solid #d1d5db; direction: ltr; }
        .items-table th, .items-table td { border: 1px solid #d1d5db; padding: 5px; vertical-align: middle; direction: rtl; }
        .items-table th { background: #6B65BD; color: #fff; font-weight: bold; text-align: center; }
        .items-table tr:nth-child(even) td { background: #f9fafb; }
        .num { direction: ltr; unicode-bidi: embed; text-align: center; white-space: nowrap; }
        .money { direction: ltr; unicode-bidi: isolate; display: inline-block; white-space: nowrap; }
        .money-amount, .money-currency { direction: ltr; unicode-bidi: embed; display: inline-block; }
        .money-currency { margin-left: 3px; }
        .product-name { font-weight: bold; }
        .muted { color: #6b7280; font-size: 10px; }
        .status-pill { display: inline-block; padding: 2px 7px; border-radius: 10px; background: #eef2ff; color: #4f46e5; font-size: 10px; font-weight: bold; }
        .totals-wrap { width: 235px; margin-top: 12px; margin-right: 0; margin-left: auto; }
        .totals-table { border: 1px solid #d1d5db; direction: ltr; }
        .totals-table td { border: 1px solid #d1d5db; padding: 5px; }
        .totals-value { direction: ltr; text-align: left; white-space: nowrap; }
        .totals-label { direction: rtl; text-align: right; white-space: nowrap; }
        .total-row td { background: #e5e7eb; font-weight: bold; }
        .settled { color: #15803d; font-weight: bold; }
        .remaining { color: #b45309; font-weight: bold; }
        .notes, .settlements { margin-top: 10px; }
        .section-title { font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .settlements-table { direction: ltr; }
        .settlements-table th, .settlements-table td { border: 1px solid #d1d5db; padding: 5px; direction: rtl; text-align: right; }
        .settlements-table th { background: #6B65BD; color: #fff; text-align: center; }
        .footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #d1d5db; color: #6b7280; font-size: 10px; }
        .footer-table { width: 100%; border-collapse: collapse; direction: ltr; }
        .footer-table td { border: none; padding: 0; vertical-align: middle; }
        .footer-text { direction: ltr; unicode-bidi: embed; text-align: right; white-space: nowrap; }
        .footer-date { direction: ltr; unicode-bidi: isolate; text-align: left; white-space: nowrap; padding-right: 8px !important; }
    </style>
</head>
<body>
@php
    $currency = $return->currency ?: 'شيكل';
    $currencyLabel = $currency === 'شيكل' ? '₪' : $currency;
    $party = $return->seller ?: $return->customer;
    $partyType = $return->seller ? 'مورد' : ($return->customer ? 'زبون' : 'غير محدد');
    $partyName = $party?->name ?: 'غير محدد';
    $partyPhone = $party?->phone ?: '—';
    $partyAddress = $party?->address ?: ($party?->work_address ?: '—');
    $total = (float) ($return->total ?? 0);
    $settled = (float) ($return->settled_amount ?? 0);
    $remaining = max(0, $total - $settled);
    $money = fn ($value) => '<span class="money" dir="ltr"><span class="money-amount" dir="ltr">'.number_format((float) $value, 2).'</span><span class="money-currency" dir="ltr">'.e($currencyLabel).'</span></span>';
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $statusLabel = match ($return->status) {
        'draft' => 'مسودة',
        'confirmed', 'pending' => 'بانتظار التسليم',
        'delivered' => 'بانتظار التسوية',
        'settled' => 'مكتملة',
        'cancelled' => 'ملغاة',
        default => $return->status ?: 'غير محدد',
    };
@endphp

<div class="header">
    <table class="header-table"><tr>
        <td style="width:30%; text-align:left; vertical-align:middle;"><img class="logo" src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike"></td>
        <td style="width:70%; text-align:right; vertical-align:middle;"><h1 class="brand-title">دكتور بايك - فاتورة مرتجع مشتريات</h1></td>
    </tr></table>
</div>
<div class="invoice-title">فاتورة مرتجع مشتريات</div>

<div class="meta-box">
    <table class="meta-table">
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ $return->number }}</span></td><td class="meta-label-cell"><span class="label">: رقم المرتجع</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ optional($return->created_at)->format('Y-m-d H:i') ?: '—' }}</span></td><td class="meta-label-cell"><span class="label">: التاريخ</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ $return->bill_id ? 'PUR-'.str_pad((string) $return->bill_id, 7, '0', STR_PAD_LEFT) : 'مرتجع مباشر' }}</span></td><td class="meta-label-cell"><span class="label">: مصدر المرتجع</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="status-pill">{{ $statusLabel }}</span></td><td class="meta-label-cell"><span class="label">: الحالة</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyName }}</span></td><td class="meta-label-cell"><span class="label">: الطرف</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyType }}</span></td><td class="meta-label-cell"><span class="label">: نوع الطرف</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ $partyPhone }}</span></td><td class="meta-label-cell"><span class="label">: الهاتف</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyAddress }}</span></td><td class="meta-label-cell"><span class="label">: العنوان</span></td></tr></table></td>
        </tr>
    </table>
</div>

<table class="items-table">
    <thead><tr><th style="width:16%">الإجمالي</th><th style="width:16%">السعر</th><th style="width:12%">الكمية</th><th>اسم المنتج</th><th style="width:13%">الكود</th><th style="width:7%">#</th></tr></thead>
    <tbody>
    @forelse($return->items as $index => $item)
        @php $lineTotal = (float) ($item->line_total ?: $item->price * $item->quantity); @endphp
        <tr>
            <td class="num">{!! $money($lineTotal) !!}</td>
            <td class="num">{!! $money($item->price) !!}</td>
            <td class="num">{{ $qty($item->quantity) }}</td>
            <td><div class="product-name">{{ $item->product?->nameAr ?: 'لا يوجد اسم للمنتج' }}</div>@if($item->size?->size || $item->sizeColor?->colorAr)<div class="muted">{{ collect([$item->size?->size, $item->sizeColor?->colorAr])->filter()->implode(' / ') }}</div>@endif</td>
            <td class="num">{{ $item->product?->product_code ?: $item->product_id }}</td>
            <td class="num">{{ $index + 1 }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="num">لا توجد منتجات في المرتجع</td></tr>
    @endforelse
    </tbody>
</table>

@if($return->settlements->isNotEmpty())
<div class="settlements"><div class="section-title">تفاصيل التسويات</div>
    <table class="settlements-table"><thead><tr><th>ملاحظة</th><th>المبلغ</th><th>النوع</th><th>التاريخ</th><th>#</th></tr></thead><tbody>
    @foreach($return->settlements as $index => $settlement)
        <tr><td>{{ $settlement->notes ?: '—' }}</td><td class="num">{!! $money($settlement->amount) !!}</td><td>{{ $settlement->type === 'cash_refund' ? 'استرداد نقدي' : ($settlement->type === 'bill_allocation' ? 'خصم من فاتورة' : 'دين على المورد') }}</td><td class="num">{{ optional($settlement->created_at)->format('Y-m-d') }}</td><td class="num">{{ $index + 1 }}</td></tr>
    @endforeach
    </tbody></table>
</div>
@endif

<div class="totals-wrap"><table class="totals-table">
    <tr class="total-row"><td class="num totals-value">{!! $money($total) !!}</td><td class="totals-label">إجمالي المرتجع</td></tr>
    <tr><td class="num totals-value settled">{!! $money($settled) !!}</td><td class="totals-label">المبلغ المسوّى</td></tr>
    <tr><td class="num totals-value {{ $remaining > 0 ? 'remaining' : 'settled' }}">{!! $money($remaining) !!}</td><td class="totals-label">المتبقي</td></tr>
</table></div>

@if(trim((string) $return->reason) !== '' || trim((string) ($return->notes ?? $return->note)) !== '')
<div class="notes"><div class="section-title">سبب المرتجع والملاحظات</div>
    @if(trim((string) $return->reason) !== '')<div><span class="label">السبب:</span> {{ $return->reason }}</div>@endif
    @if(trim((string) ($return->notes ?? $return->note)) !== '')<div><span class="label">الملاحظات:</span> {{ $return->notes ?? $return->note }}</div>@endif
</div>
@endif

<div class="footer"><table class="footer-table"><tr>
    <td class="footer-date"><span dir="ltr">{{ now()->format('Y-m-d H:i') }}</span></td>
    <td class="footer-text"><span>هذه نسخة مطبوعة من فاتورة مرتجع مشتريات من نظام دكتور بايك تم انشاؤها بتاريخ</span></td>
</tr></table></div>
</body>
</html>
