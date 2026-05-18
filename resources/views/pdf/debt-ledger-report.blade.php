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
    </style>
</head>
<body>
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
        <span>إجمالي أخذت: <span class="taken">{{ number_format($total_taken, 2) }} ₪</span></span>
        <span>إجمالي أعطيت: <span class="given">{{ number_format($total_given, 2) }} ₪</span></span>
        <span>الرصيد النهائي:
            <span class="{{ $balance >= 0 ? 'taken' : 'given' }}">{{ number_format($balance, 2) }} ₪</span>
        </span>
    </div>

    <table class="data">
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
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td class="num">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                    <td>{{ $transaction->note ?? '—' }}</td>
                    <td class="num given">
                        {{ $transaction->type === 'given' ? number_format($transaction->amount, 2) . ' ₪' : '—' }}
                    </td>
                    <td class="num taken">
                        {{ $transaction->type === 'taken' ? number_format($transaction->amount, 2) . ' ₪' : '—' }}
                    </td>
                    @php
                        $before = $transaction->type === 'taken'
                            ? $transaction->balance_after - $transaction->amount
                            : $transaction->balance_after + $transaction->amount;
                    @endphp
                    <td class="num">{{ number_format($before, 2) }} ₪</td>
                    <td class="num {{ $transaction->balance_after >= 0 ? 'taken' : 'given' }}">
                        {{ number_format($transaction->balance_after, 2) }} ₪
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
