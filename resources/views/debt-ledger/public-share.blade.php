<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $person['name'] ?? 'دفتر الديون' }} - دكتور بايك</title>
    <style>
        :root {
            --primary: #6B65BD;
            --primary-soft: #f1efff;
            --border: #d0d7e2;
            --surface: #ffffff;
            --background: #f5f6f8;
            --text: #1a1a1a;
            --muted: #667085;
            --taken: #1b8a4a;
            --given: #c62828;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 20px 12px;
            background: var(--background);
            color: var(--text);
            direction: rtl;
            font-family: Tahoma, Arial, sans-serif;
        }
        .report {
            width: min(1050px, 100%);
            margin: 0 auto;
            padding: 24px 28px;
            background: var(--surface);
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(16, 24, 40, .08);
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--primary);
        }
        .header h1 {
            margin: 0;
            color: var(--primary);
            font-size: 22px;
        }
        .header img {
            width: auto;
            height: 55px;
            object-fit: contain;
            border-radius: 6px;
        }
        h2 {
            margin: 10px 0 18px;
            text-align: center;
            font-size: 18px;
        }
        .meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px 20px;
        }
        .meta p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }
        .meta strong { color: var(--text); }
        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 18px 0;
            padding: 14px;
            background: var(--primary-soft);
            border: 1px solid rgba(107, 101, 189, .16);
            border-radius: 9px;
        }
        .summary-item {
            padding: 8px;
            text-align: center;
        }
        .summary-label {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
        }
        .summary-value {
            font-size: 17px;
            font-weight: 700;
        }
        .taken { color: var(--taken); font-weight: 700; }
        .given { color: var(--given); font-weight: 700; }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 9px;
        }
        table {
            width: 100%;
            min-width: 820px;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 9px 7px;
            border-bottom: 1px solid var(--border);
            border-left: 1px solid var(--border);
            text-align: right;
            white-space: nowrap;
        }
        th:last-child, td:last-child { border-left: 0; }
        tr:last-child td { border-bottom: 0; }
        th {
            background: var(--primary);
            color: #fff;
            text-align: center;
        }
        tbody tr:nth-child(even) { background: #f8faff; }
        .num {
            direction: ltr;
            text-align: center;
        }
        .note {
            min-width: 150px;
            white-space: normal;
        }
        .empty {
            padding: 28px;
            color: var(--muted);
            text-align: center;
        }
        .source-row td {
            padding: 0;
            background: #fff;
            white-space: normal;
        }
        .source-box {
            margin: 10px;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fbfdff;
        }
        .source-title {
            margin-bottom: 8px;
            color: #334155;
            font-weight: 700;
        }
        .source-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin-bottom: 10px;
            color: #475569;
            font-size: 12px;
        }
        .items-table {
            min-width: 620px;
            font-size: 12px;
        }
        .items-table th {
            background: #e8ecff;
            color: #334155;
        }
        .product-img {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 5px;
        }
        @media (max-width: 640px) {
            body { padding: 0; }
            .report {
                min-height: 100vh;
                padding: 18px 12px;
                border-radius: 0;
                box-shadow: none;
            }
            .header h1 { font-size: 17px; }
            .header img { height: 42px; }
            .meta { grid-template-columns: 1fr; }
            .summary {
                grid-template-columns: 1fr;
                gap: 2px;
            }
            .summary-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 6px;
                text-align: right;
            }
            .summary-label { margin: 0; }
            .summary-value { font-size: 15px; }
        }
    </style>
</head>
<body>
    @php
        $showSourceDetails = ($detail_level ?? 'summary') !== 'summary';
        $showProductImages = ($detail_level ?? 'summary') === 'detailed_with_images';
    @endphp
    <main class="report">
        <header class="header">
            <h1>دكتور بايك - دفتر الديون</h1>
            <img src="{{ asset('appImages/logo.jpg') }}" alt="Doctor Bike">
        </header>

        <h2>كشف حساب</h2>

        <section class="meta">
            <p><strong>الاسم:</strong> {{ $person['name'] ?? '—' }}</p>
            <p><strong>الهاتف:</strong> {{ $person['phone'] ?? '—' }}</p>
            <p><strong>الفترة:</strong> جميع المعاملات</p>
            <p><strong>تاريخ الإنشاء:</strong> {{ now()->format('Y-m-d H:i') }}</p>
            <p><strong>عدد المعاملات:</strong> {{ $transactions->count() }}</p>
        </section>

        <section class="summary">
            <div class="summary-item">
                <span class="summary-label">إجمالي أخذت</span>
                <span class="summary-value taken">{{ number_format($total_taken, 2) }} ₪</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">إجمالي أعطيت</span>
                <span class="summary-value given">{{ number_format($total_given, 2) }} ₪</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">الرصيد النهائي</span>
                <span class="summary-value {{ $balance >= 0 ? 'taken' : 'given' }}">
                    {{ number_format($balance, 2) }} ₪
                </span>
            </div>
        </section>

        @if($transactions->isEmpty())
            <div class="empty">لا توجد معاملات</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>التاريخ</th>
                            <th>ملاحظة</th>
                            <th class="num">أعطيت</th>
                            <th class="num">أخذت</th>
                            <th class="num">الرصيد السابق</th>
                            <th class="num">الرصيد بعد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $index => $transaction)
                            @php
                                $before = $transaction->type === 'taken'
                                    ? $transaction->balance_after - $transaction->amount
                                    : $transaction->balance_after + $transaction->amount;
                            @endphp
                            <tr>
                                <td class="num">{{ $index + 1 }}</td>
                                <td class="num">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                <td class="note">{{ $transaction->note ?? '—' }}</td>
                                <td class="num given">
                                    {{ $transaction->type === 'given' ? number_format($transaction->amount, 2) . ' ₪' : '—' }}
                                </td>
                                <td class="num taken">
                                    {{ $transaction->type === 'taken' ? number_format($transaction->amount, 2) . ' ₪' : '—' }}
                                </td>
                                <td class="num">{{ number_format($before, 2) }} ₪</td>
                                <td class="num {{ $transaction->balance_after >= 0 ? 'taken' : 'given' }}">
                                    {{ number_format($transaction->balance_after, 2) }} ₪
                                </td>
                            </tr>
                            @php
                                $sourceDetail = $source_details[$transaction->id] ?? null;
                            @endphp
                            @if($showSourceDetails && $sourceDetail)
                                <tr class="source-row">
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
                                                <div class="table-wrap">
                                                    <table class="items-table">
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
                                                                            @if(!empty($item['image_url']))
                                                                                <img class="product-img" src="{{ $item['image_url'] }}" alt="">
                                                                            @else
                                                                                —
                                                                            @endif
                                                                        </td>
                                                                    @endif
                                                                    <td>{{ $item['name'] }}</td>
                                                                    <td class="num">{{ number_format($item['quantity'], 0) }}</td>
                                                                    <td class="num">{{ number_format($item['unit_price'], 2) }} ₪</td>
                                                                    <td class="num">{{ number_format($item['line_total'], 2) }} ₪</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>
