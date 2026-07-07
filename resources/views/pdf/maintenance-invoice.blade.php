<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2933;
            font-size: 12px;
            line-height: 1.65;
            margin: 0;
            padding: 24px;
            direction: rtl;
        }
        .header {
            border-bottom: 3px solid #0f766e;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .brand {
            font-size: 25px;
            font-weight: 700;
            color: #0f766e;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            margin-top: 4px;
        }
        .muted { color: #607080; }
        .grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .grid td {
            border: 1px solid #d7dee8;
            padding: 7px 9px;
            vertical-align: top;
        }
        .grid .label {
            width: 18%;
            background: #f3f7f7;
            color: #47606b;
            font-weight: 700;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        table.items th {
            background: #0f766e;
            color: #fff;
            padding: 8px;
            border: 1px solid #0f766e;
        }
        table.items td {
            padding: 8px;
            border: 1px solid #d7dee8;
        }
        .totals {
            width: 42%;
            margin-right: auto;
            margin-top: 16px;
            border-collapse: collapse;
        }
        .totals td {
            border: 1px solid #d7dee8;
            padding: 7px 9px;
        }
        .totals .grand {
            background: #e6f5f3;
            color: #0f766e;
            font-weight: 700;
            font-size: 14px;
        }
        .footer {
            margin-top: 26px;
            border-top: 1px solid #d7dee8;
            padding-top: 10px;
            color: #607080;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Doctor Bike</div>
        <div class="title">فاتورة صيانة رسمية</div>
        <div class="muted">صادرة من قسم الصيانة</div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">رقم الفاتورة</td>
            <td>{{ $invoice['invoice_number'] }}</td>
            <td class="label">رقم الصيانة</td>
            <td>#{{ $invoice['maintenance_id'] }}</td>
        </tr>
        <tr>
            <td class="label">تاريخ الفاتورة</td>
            <td>{{ $invoice['invoice_date'] ?? '-' }}</td>
            <td class="label">حالة الصيانة</td>
            <td>{{ $invoice['status'] }}</td>
        </tr>
        <tr>
            <td class="label">الجهة</td>
            <td>{{ $invoice['customer_type_label'] }}</td>
            <td class="label">الاسم</td>
            <td>{{ $invoice['customer_name'] }}</td>
        </tr>
        <tr>
            <td class="label">الهاتف</td>
            <td>{{ $invoice['customer_phone'] ?? '-' }}</td>
            <td class="label">الاستلام</td>
            <td>{{ $invoice['receipt_date'] }} {{ $invoice['receipt_time'] }}</td>
        </tr>
        @if(!empty($invoice['description']))
            <tr>
                <td class="label">الوصف</td>
                <td colspan="3">{{ $invoice['description'] }}</td>
            </tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>البيان</th>
                <th>الكمية</th>
                <th>سعر الوحدة</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice['items'] as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item['product_name'] }}</td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>{{ number_format((float) $item['unit_price'], 2) }}</td>
                    <td>{{ number_format((float) $item['line_total'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td>1</td>
                    <td>أجرة صيانة</td>
                    <td>1</td>
                    <td>{{ number_format((float) $invoice['labor_cost'], 2) }}</td>
                    <td>{{ number_format((float) $invoice['labor_cost'], 2) }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>إجمالي القطع</td>
            <td>{{ number_format((float) $invoice['parts_total'], 2) }}</td>
        </tr>
        <tr>
            <td>أجرة الصيانة</td>
            <td>{{ number_format((float) $invoice['labor_cost'], 2) }}</td>
        </tr>
        <tr>
            <td>الخصم</td>
            <td>{{ number_format((float) $invoice['discount'], 2) }}</td>
        </tr>
        <tr class="grand">
            <td>الإجمالي النهائي</td>
            <td>{{ number_format((float) $invoice['invoice_total'], 2) }}</td>
        </tr>
        <tr>
            <td>المدفوع</td>
            <td>{{ number_format((float) $invoice['paid_amount'], 2) }}</td>
        </tr>
        <tr>
            <td>المتبقي</td>
            <td>{{ number_format((float) $invoice['remaining_amount'], 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        @if(!empty($invoice['instant_sale_id']))
            مرتبطة بفاتورة البيع الفوري رقم {{ $invoice['instant_sale_serial'] ?? $invoice['instant_sale_id'] }}.
        @else
            هذه الفاتورة قابلة للمعاينة قبل التسليم، ويتم ربطها تلقائياً عند التسليم.
        @endif
    </div>
</body>
</html>
