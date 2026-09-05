<!DOCTYPE html>
<html lang="ar">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>تقرير دفتر الديون</title>
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
        .taken { color: #1b8a4a; font-weight: bold; }
        .given { color: #c62828; font-weight: bold; }
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
        .source-detail td {
            background: #fff;
            padding: 0;
        }
        .source-box {
            border: 1px solid #e2e8f0;
            margin: 8px;
            padding: 10px;
            border-radius: 6px;
            background: #fbfdff;
        }
        .source-title {
            font-weight: bold;
            color: #334155;
            margin-bottom: 6px;
        }
        .source-meta {
            margin-bottom: 8px;
            color: #475569;
            font-size: 12px;
        }
        .source-meta span {
            display: inline-block;
            margin-left: 14px;
            margin-bottom: 4px;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            direction: rtl;
        }
        table.items th,
        table.items td {
            border: 1px solid #e2e8f0;
            padding: 5px;
            text-align: right;
        }
        table.items th {
            background: #e8ecff;
            color: #334155;
        }
        .product-img {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    @php
        $currencyLabel = $currency ?? 'شيكل';
        $showSourceDetails = ($detail_level ?? 'summary') !== 'summary';
        $showProductImages = ($detail_level ?? 'summary') === 'detailed_with_images';
    @endphp
    <div class="header">
        <table class="logo-row">
            <tr>
                <td style="width: 70%;">
                    <h1>دكتور بايك - دفتر الديون</h1>
                </td>
                <td style="width: 30%; text-align: left;">
                    <img src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike" style="height:55px;">
                </td>
            </tr>
        </table>
    </div>

    <h2>كشف حساب</h2>

    <div class="meta">
        <p><strong>الاسم:</strong> {{ $person['name'] }}</p>
        <p><strong>الهاتف:</strong> {{ $person['phone'] ?? '—' }}</p>
        <p><strong>الفترة:</strong> {{ $period_label }}</p>
        <p><strong>تاريخ الإنشاء:</strong> {{ $generated_at }}</p>
        <p><strong>عدد المعاملات:</strong> {{ $transactions_count }}</p>
    </div>

    <div class="summary">
        <span>إجمالي {{ $taken_label ?? 'أخذت' }}: <span class="taken">{{ number_format($total_taken, 2) }} {{ $currencyLabel }}</span></span>
        <span>إجمالي {{ $given_label ?? 'أعطيت' }}: <span class="given">{{ number_format($total_given, 2) }} {{ $currencyLabel }}</span></span>
        <span>الرصيد النهائي:
            <span class="{{ $balance >= 0 ? 'taken' : 'given' }}">{{ number_format($balance, 2) }} {{ $currencyLabel }}</span>
        </span>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>التاريخ</th>
                <th>ملاحظة</th>
                <th class="num">{{ $given_label ?? 'أعطيت' }}</th>
                <th class="num">{{ $taken_label ?? 'أخذت' }}</th>
                <th class="num">الرصيد السابق</th>
                <th class="num">الرصيد بعد</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $index => $transaction)
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td class="num">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                    <td>{{ $transaction->note ?? '—' }}</td>
                    <td class="num given">
                        {{ $transaction->type === 'given' ? number_format($transaction->amount, 2) . ' '.$currencyLabel : '—' }}
                    </td>
                    <td class="num taken">
                        {{ $transaction->type === 'taken' ? number_format($transaction->amount, 2) . ' '.$currencyLabel : '—' }}
                    </td>
                    @php
                        $before = $transaction->type === 'taken'
                            ? $transaction->balance_after - $transaction->amount
                            : $transaction->balance_after + $transaction->amount;
                    @endphp
                    <td class="num">{{ number_format($before, 2) }} {{ $currencyLabel }}</td>
                    <td class="num {{ $transaction->balance_after >= 0 ? 'taken' : 'given' }}">
                        {{ number_format($transaction->balance_after, 2) }} {{ $currencyLabel }}
                    </td>
                </tr>
                @php
                    $sourceDetail = $source_details[$transaction->id] ?? null;
                @endphp
                @if($showSourceDetails && $sourceDetail)
                    <tr class="source-detail">
                        <td colspan="7">
                            <div class="source-box">
                                <div class="source-title">{{ $sourceDetail['title'] }}</div>
                                @if(!empty($sourceDetail['meta']))
                                    <div class="source-meta">
                                        @foreach($sourceDetail['meta'] as $label => $value)
                                            <span><strong>{{ $label }}:</strong> {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if(!empty($sourceDetail['items']))
                                    <table class="items" dir="rtl">
                                        <thead>
                                            <tr>
                                                @if($showProductImages)
                                                    <th>الصورة</th>
                                                @endif
                                                <th>المنتج</th>
                                                <th class="num">الكمية</th>
                                                <th class="num">السعر</th>
                                                <th class="num">المجموع</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($sourceDetail['items'] as $item)
                                                <tr>
                                                    @if($showProductImages)
                                                        <td class="num">
                                                            @if(!empty($item['image_path']))
                                                                <img class="product-img" src="{{ $item['image_path'] }}" alt="">
                                                            @else
                                                                —
                                                            @endif
                                                        </td>
                                                    @endif
                                                    <td>{{ $item['name'] }}</td>
                                                    <td class="num">{{ number_format($item['quantity'], 0) }}</td>
                                                    <td class="num">{{ number_format($item['unit_price'], 2) }} {{ $currencyLabel }}</td>
                                                    <td class="num">{{ number_format($item['line_total'], 2) }} {{ $currencyLabel }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</body>
</html>
