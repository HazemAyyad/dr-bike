<!DOCTYPE html>
<html lang="ar">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>فاتورة صيانة</title>
    <style>
        @page { margin: 24px 28px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 13px;
            text-align: right;
            color: #1a1a1a;
        }
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #6B65BD;
            padding-bottom: 12px;
        }
        .logo-row {
            width: 100%;
            border: none;
        }
        .logo-row td {
            border: none;
            vertical-align: middle;
            text-align: right;
        }
        h1 {
            margin: 0;
            font-size: 20px;
            color: #6B65BD;
        }
        h2 {
            text-align: center;
            margin: 10px 0 18px;
            font-size: 16px;
        }
        .meta p {
            margin: 4px 0;
            text-align: right;
        }
        .summary {
            background: #eef4ff;
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
            text-align: right;
        }
        .summary span {
            display: block;
            margin-bottom: 6px;
        }
        .paid { color: #1b8a4a; font-weight: bold; }
        .partial { color: #b26a00; font-weight: bold; }
        .unpaid { color: #c62828; font-weight: bold; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.data th,
        table.data td {
            border: 1px solid #d0d7e2;
            padding: 7px 6px;
            text-align: right;
        }
        table.data th {
            background: #6B65BD;
            color: #fff;
            text-align: center;
        }
        table.data tr:nth-child(even) {
            background: #f8faff;
        }
        .num { text-align: center; direction: ltr; unicode-bidi: embed; }
        .totals {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }
        .totals td {
            border: 1px solid #d0d7e2;
            padding: 7px 6px;
        }
        .totals .label {
            background: #f8faff;
            font-weight: bold;
        }
        .grand {
            color: #6B65BD;
            font-weight: bold;
        }
        .footer {
            margin-top: 18px;
            color: #666;
            font-size: 11px;
            border-top: 1px solid #d0d7e2;
            padding-top: 8px;
        }
        .ltr { direction: ltr; unicode-bidi: embed; }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            background: #eef4ff;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="logo-row">
            <tr>
                <td style="width: 70%;">
                    <h1>دكتور بايك - قسم الصيانة</h1>
                </td>
                <td style="width: 30%; text-align: left;">
                    <img src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike" style="height:55px;">
                </td>
            </tr>
        </table>
    </div>

    <h2>فاتورة صيانة رسمية</h2>

    <div class="meta">
        <p><strong>رقم الفاتورة:</strong> <span class="ltr">{{ $invoice['invoice_number'] }}</span></p>
        <p><strong>رقم الصيانة:</strong> #{{ $invoice['maintenance_id'] }}</p>
        <p><strong>تاريخ الفاتورة:</strong> <span class="ltr">{{ $invoice['invoice_date'] ?? '—' }}</span></p>
        <p><strong>الاسم:</strong> {{ $invoice['customer_name'] }}</p>
        <p><strong>الهاتف:</strong> {{ $invoice['customer_phone'] ?? '—' }}</p>
        <p><strong>الجهة:</strong> {{ $invoice['customer_type_label'] }}</p>
        <p><strong>موعد الاستلام:</strong> <span class="ltr">{{ $invoice['receipt_date'] }} {{ $invoice['receipt_time'] }}</span></p>
        @if(!empty($invoice['description']))
            <p><strong>وصف الصيانة:</strong> {{ $invoice['description'] }}</p>
        @endif
    </div>

    <div class="summary">
        <span>حالة الصيانة: <strong>{{ $invoice['maintenance_status_label'] }}</strong></span>
        <span>حالة الفاتورة:
            <span class="{{ $invoice['payment_status'] }}">{{ $invoice['payment_status_label'] }}</span>
        </span>
        <span>الإجمالي النهائي:
            <span class="grand">{{ number_format((float) $invoice['invoice_total'], 2) }} ₪</span>
        </span>
        <span>المدفوع:
            <span class="paid">{{ number_format((float) $invoice['paid_amount'], 2) }} ₪</span>
        </span>
        <span>المتبقي:
            <span class="{{ (float) $invoice['remaining_amount'] > 0 ? 'unpaid' : 'paid' }}">
                {{ number_format((float) $invoice['remaining_amount'], 2) }} ₪
            </span>
        </span>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>البيان</th>
                <th class="num">الكمية</th>
                <th class="num">سعر الوحدة</th>
                <th class="num">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice['items'] as $index => $item)
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td>{{ $item['product_name'] }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ number_format((float) $item['unit_price'], 2) }} ₪</td>
                    <td class="num">{{ number_format((float) $item['line_total'], 2) }} ₪</td>
                </tr>
            @empty
                <tr>
                    <td class="num">1</td>
                    <td>أجرة صيانة</td>
                    <td class="num">1</td>
                    <td class="num">{{ number_format((float) $invoice['labor_cost'], 2) }} ₪</td>
                    <td class="num">{{ number_format((float) $invoice['labor_cost'], 2) }} ₪</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">إجمالي القطع</td>
            <td class="num">{{ number_format((float) $invoice['parts_total'], 2) }} ₪</td>
        </tr>
        <tr>
            <td class="label">أجرة الصيانة</td>
            <td class="num">{{ number_format((float) $invoice['labor_cost'], 2) }} ₪</td>
        </tr>
        <tr>
            <td class="label">الخصم</td>
            <td class="num">{{ number_format((float) $invoice['discount'], 2) }} ₪</td>
        </tr>
        <tr>
            <td class="label grand">الإجمالي النهائي</td>
            <td class="num grand">{{ number_format((float) $invoice['invoice_total'], 2) }} ₪</td>
        </tr>
        <tr>
            <td class="label">المدفوع</td>
            <td class="num paid">{{ number_format((float) $invoice['paid_amount'], 2) }} ₪</td>
        </tr>
        <tr>
            <td class="label">المتبقي</td>
            <td class="num {{ (float) $invoice['remaining_amount'] > 0 ? 'unpaid' : 'paid' }}">
                {{ number_format((float) $invoice['remaining_amount'], 2) }} ₪
            </td>
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
