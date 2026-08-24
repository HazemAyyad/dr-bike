<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>فاتورة شراء #{{ $bill->id }}</title>
    <style>
        @page { margin: 26px 28px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111827;
            direction: rtl;
            text-align: right;
        }
        .header {
            width: 100%;
            border-bottom: 1.3px solid #6B65BD;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .header-table,
        .header-table td {
            border: none;
            margin: 0;
            padding: 0;
            width: 100%;
        }
        .header-table {
            direction: ltr;
        }
        .header-table td {
            direction: rtl;
        }
        .brand-title {
            margin: 0;
            color: #6B65BD;
            font-size: 21px;
            font-weight: bold;
        }
        .logo {
            height: 88px;
            width: auto;
        }
        .invoice-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 10px 0 8px;
        }
        .meta-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 12px;
        }
        .meta-table,
        .items-table,
        .totals-table,
        .payments-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table {
            direction: rtl;
        }
        .meta-table td {
            border: none;
            padding: 3px 0;
            vertical-align: top;
            width: 50%;
            direction: rtl;
            text-align: right;
        }
        .meta-table td.meta-right {
            padding-left: 16px;
        }
        .meta-table td.meta-left {
            padding-right: 16px;
        }
        .label {
            font-weight: bold;
            color: #111827;
        }
        .value {
            color: #374151;
        }
        .meta-line {
            width: auto;
            border-collapse: collapse;
            direction: ltr;
            display: inline-table;
            margin-left: auto;
            margin-right: 0;
        }
        .meta-line td {
            border: none;
            padding: 0;
            width: auto;
            vertical-align: baseline;
        }
        .meta-line .meta-label-cell {
            width: auto;
            white-space: nowrap;
            text-align: right;
            padding-left: 8px;
        }
        .meta-line .meta-value-cell {
            text-align: right;
            white-space: nowrap;
            padding-right: 2px;
        }
        .ltr {
            direction: ltr;
            unicode-bidi: embed;
            display: inline-block;
        }
        .items-table {
            margin-top: 4px;
            border: 1px solid #d1d5db;
            direction: ltr;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 5px;
            vertical-align: middle;
            direction: rtl;
        }
        .items-table th {
            background: #6B65BD;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
        }
        .items-table tr:nth-child(even) td {
            background: #f9fafb;
        }
        .num {
            direction: ltr;
            unicode-bidi: embed;
            text-align: center;
            white-space: nowrap;
        }
        .money {
            direction: ltr;
            unicode-bidi: isolate;
            display: inline-block;
            white-space: nowrap;
        }
        .money-amount {
            direction: ltr;
            unicode-bidi: embed;
        }
        .money-currency {
            direction: rtl;
            unicode-bidi: embed;
        }
        .product-name {
            font-weight: bold;
        }
        .muted {
            color: #6b7280;
            font-size: 10px;
        }
        .status-pill {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            background: #eef2ff;
            color: #4f46e5;
            font-size: 10px;
            font-weight: bold;
        }
        .totals-wrap {
            width: 235px;
            margin-top: 12px;
            margin-right: 0;
            margin-left: auto;
        }
        .totals-table {
            border: 1px solid #d1d5db;
            direction: ltr;
        }
        .totals-table td {
            border: 1px solid #d1d5db;
            padding: 5px;
            direction: rtl;
        }
        .totals-table .total-row td {
            background: #e5e7eb;
            font-weight: bold;
        }
        .paid {
            color: #15803d;
            font-weight: bold;
        }
        .remaining {
            color: #b45309;
            font-weight: bold;
        }
        .notes,
        .payments {
            margin-top: 10px;
        }
        .section-title {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .payments-table th,
        .payments-table td {
            border: 1px solid #d1d5db;
            padding: 5px;
            text-align: right;
            direction: rtl;
        }
        .payments-table {
            direction: ltr;
        }
        .payments-table th {
            background: #6B65BD;
            color: #ffffff;
            text-align: center;
        }
        .footer {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 1px solid #d1d5db;
            color: #6b7280;
            font-size: 10px;
        }
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            direction: ltr;
        }
        .footer-table td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }
        .footer-text {
            direction: rtl;
            unicode-bidi: embed;
            text-align: right;
            white-space: nowrap;
        }
        .footer-date {
            direction: ltr;
            unicode-bidi: isolate;
            text-align: left;
            white-space: nowrap;
            padding-right: 8px !important;
        }
    </style>
</head>
<body>
@php
    $currency = $bill->currency ?: 'شيكل';
    $subtotal = (float) ($bill->total ?? 0);
    $discount = (float) ($bill->discount ?? 0);
    $finalTotal = (float) (($bill->final_total ?? 0) > 0 ? $bill->final_total : max(0, $subtotal - $discount));
    $paidAmount = (float) ($bill->paid_amount ?? 0);
    $remainingAmount = max(0, $finalTotal - $paidAmount);
    $party = $bill->seller ?: $bill->customer;
    $partyType = $bill->seller ? 'مورد' : ($bill->customer ? 'زبون' : 'غير محدد');
    $partyName = $party?->name ?: 'غير محدد';
    $partyPhone = $party?->phone ?: '—';
    $partyAddress = $party?->address ?: ($party?->work_address ?: '—');
    $invoiceNumber = 'PUR-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT);
    $money = fn ($value, $activeCurrency = null) => '<span class="money"><span class="money-amount">'.number_format((float) $value, 2).'</span> <span class="money-currency">'.e($activeCurrency ?: $currency).'</span></span>';
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $statusLabel = function (?string $status): string {
        return match ($status) {
            'unfinished' => 'قيد الاستلام',
            'finished' => 'مكتملة',
            'extra' => 'زيادة',
            'not_compatible' => 'غير مطابق',
            'cancelled', 'canceled' => 'ملغاة',
            default => $status ?: 'غير محدد',
        };
    };
    $workflowLabel = match ($bill->workflow_status) {
        'awaiting_receiving' => 'بانتظار الاستلام',
        'receiving' => 'جاري الاستلام',
        'received' => 'تم الاستلام',
        'ready_for_finalization' => 'جاهزة للاعتماد',
        'finalized' => 'معتمدة',
        'pending' => 'بانتظار الاستلام',
        'cancelled', 'canceled' => 'ملغاة',
        default => $bill->workflow_status ?: 'غير محدد',
    };
    $paymentLabel = match ($bill->payment_status) {
        'paid' => 'مدفوعة',
        'partial', 'partially_paid' => 'مدفوعة جزئياً',
        'unpaid' => 'غير مكتملة الدفع',
        default => $bill->payment_status ?: 'غير مكتملة الدفع',
    };
@endphp

<div class="header">
    <table class="header-table">
        <tr>
            <td style="width: 30%; text-align: left; vertical-align: middle;">
                <img class="logo" src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike">
            </td>
            <td style="width: 70%; text-align: right; vertical-align: middle;">
                <h1 class="brand-title">دكتور بايك - فاتورة مشتريات</h1>
            </td>
        </tr>
    </table>
</div>

<div class="invoice-title">فاتورة شراء {{ $invoiceNumber }}</div>

<div class="meta-box">
    <table class="meta-table">
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ $invoiceNumber }}</span></td><td class="meta-label-cell"><span class="label">: رقم الفاتورة</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ optional($bill->created_at)->format('Y-m-d H:i') ?: '—' }}</span></td><td class="meta-label-cell"><span class="label">: التاريخ</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyName }}</span></td><td class="meta-label-cell"><span class="label">: الطرف</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyType }}</span></td><td class="meta-label-cell"><span class="label">: نوع الطرف</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value ltr">{{ $partyPhone }}</span></td><td class="meta-label-cell"><span class="label">: الهاتف</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $partyAddress }}</span></td><td class="meta-label-cell"><span class="label">: العنوان</span></td></tr></table></td>
        </tr>
        <tr>
            <td class="meta-right"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $workflowLabel }}</span></td><td class="meta-label-cell"><span class="label">: حالة الفاتورة</span></td></tr></table></td>
            <td class="meta-left"><table class="meta-line"><tr><td class="meta-value-cell"><span class="value">{{ $paymentLabel }}</span></td><td class="meta-label-cell"><span class="label">: حالة الدفع</span></td></tr></table></td>
        </tr>
    </table>
</div>

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 14%;">الحالة</th>
            <th style="width: 16%;">الإجمالي</th>
            <th style="width: 16%;">السعر</th>
            <th style="width: 12%;">الكمية</th>
            <th>اسم المنتج</th>
            <th style="width: 13%;">الكود</th>
            <th style="width: 7%;">#</th>
        </tr>
    </thead>
    <tbody>
        @forelse($bill->items as $index => $item)
            @php
                $unitPrice = (float) (($item->final_unit_price ?? 0) > 0 ? $item->final_unit_price : $item->price);
                $orderedQuantity = (float) ($item->ordered_quantity ?? $item->quantity ?? 0);
                $lineTotal = $orderedQuantity * $unitPrice;
                $issueParts = [];
                if ((float) ($item->missing_amount ?? 0) > 0) $issueParts[] = 'نقص '.$qty($item->missing_amount);
                if ((float) ($item->custody_quantity ?? 0) > 0) $issueParts[] = 'زيادة '.$qty($item->custody_quantity);
                if ((float) ($item->damaged_quantity ?? 0) > 0) $issueParts[] = 'تالف '.$qty($item->damaged_quantity);
                if ((float) ($item->mismatched_quantity ?? 0) > 0) $issueParts[] = 'غير مطابق '.$qty($item->mismatched_quantity);
                if ((float) ($item->not_compatible_amount ?? 0) > 0) $issueParts[] = 'غير متوافق '.$qty($item->not_compatible_amount);
            @endphp
            <tr>
                <td class="num"><span class="status-pill">{{ $statusLabel($item->status) }}</span></td>
                <td class="num">{!! $money($lineTotal) !!}</td>
                <td class="num">{!! $money($unitPrice) !!}</td>
                <td class="num">{{ $qty($orderedQuantity) }}</td>
                <td>
                    <div class="product-name">{{ $item->product?->nameAr ?: 'لا يوجد اسم للمنتج' }}</div>
                    @if(!empty($issueParts))
                        <div class="muted">{{ implode(' • ', $issueParts) }}</div>
                    @endif
                </td>
                <td class="num">{{ $item->product?->product_code ?: $item->product_id }}</td>
                <td class="num">{{ $index + 1 }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="num">لا توجد منتجات في الفاتورة</td>
            </tr>
        @endforelse
    </tbody>
</table>

@if($bill->payments->isNotEmpty())
    <div class="payments">
        <div class="section-title">تفاصيل الدفعات</div>
        <table class="payments-table">
            <thead>
                <tr>
                    <th>ملاحظة</th>
                    <th>المبلغ</th>
                    <th>النوع</th>
                    <th>التاريخ</th>
                    <th>#</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bill->payments as $index => $payment)
                    <tr>
                        <td>{{ $payment->note ?: '—' }}</td>
                        <td class="num">{!! $money($payment->amount, $payment->currency ?: $currency) !!}</td>
                        <td>{{ $payment->type === 'initial_payment' ? 'دفعة أولية' : 'دفعة' }}</td>
                        <td class="num">{{ optional($payment->paid_at)->format('Y-m-d') ?: optional($payment->created_at)->format('Y-m-d') }}</td>
                        <td class="num">{{ $index + 1 }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="totals-wrap">
    <table class="totals-table">
        <tr>
            <td class="num">{!! $money($subtotal) !!}</td>
            <td>الإجمالي الفرعي</td>
        </tr>
        <tr>
            <td class="num">{!! $money($discount) !!}</td>
            <td>الخصم</td>
        </tr>
        <tr>
            <td class="num">{!! $money(0) !!}</td>
            <td>الضريبة</td>
        </tr>
        <tr class="total-row">
            <td class="num">{!! $money($finalTotal) !!}</td>
            <td>إجمالي الفاتورة</td>
        </tr>
        <tr>
            <td class="num paid">{!! $money($paidAmount) !!}</td>
            <td>المبلغ المدفوع</td>
        </tr>
        <tr>
            <td class="num {{ $remainingAmount > 0 ? 'remaining' : 'paid' }}">{!! $money($remainingAmount) !!}</td>
            <td>المتبقي</td>
        </tr>
    </table>
</div>

@if(trim((string) $bill->notes) !== '')
    <div class="notes">
        <div class="section-title">ملاحظات</div>
        <div>{{ $bill->notes }}</div>
    </div>
@endif

<div class="footer">
    <table class="footer-table">
        <tr>
            <td class="footer-date"><span dir="ltr">{{ now()->format('Y-m-d H:i') }}</span></td>
            <td class="footer-text"><span dir="rtl">هذه نسخة مطبوعة من فاتورة مشتريات من نظام دكتور بايك تم انشاؤها بتاريخ</span></td>
        </tr>
    </table>
</div>
</body>
</html>
