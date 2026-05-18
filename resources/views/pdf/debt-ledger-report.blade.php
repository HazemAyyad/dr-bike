<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>تقرير دفتر الديون</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 13px;
            direction: rtl;
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
        }
        .logo-row td {
            border: none;
            vertical-align: middle;
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
        }
        .summary {
            background: #eef4ff;
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
        }
        .summary span {
            display: inline-block;
            margin-left: 18px;
        }
        .taken { color: #1b8a4a; font-weight: bold; }
        .given { color: #c62828; font-weight: bold; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #d0d7e2;
            padding: 7px 6px;
            text-align: center;
        }
        th {
            background: #6B65BD;
            color: #fff;
        }
        tr:nth-child(even) {
            background: #f8faff;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="logo-row">
            <tr>
                <td style="width: 70px;">
                    <img src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike" style="height:55px;">
                </td>
                <td>
                    <h1>دكتور بايك - دفتر الديون</h1>
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

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <th>ملاحظة</th>
                <th>أعطيت</th>
                <th>أخذت</th>
                <th>الرصيد</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $index => $transaction)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                    <td>{{ $transaction->note ?? '—' }}</td>
                    <td class="given">
                        {{ $transaction->type === 'given' ? number_format($transaction->amount, 2) : '—' }}
                    </td>
                    <td class="taken">
                        {{ $transaction->type === 'taken' ? number_format($transaction->amount, 2) : '—' }}
                    </td>
                    <td class="{{ $transaction->balance_after >= 0 ? 'taken' : 'given' }}">
                        {{ number_format($transaction->balance_after, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
