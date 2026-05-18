<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $person['name'] ?? 'دفتر الديون' }} - دكتور بايك</title>
    <style>
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif;
            margin: 0;
            padding: 16px;
            background: #f3f4f6;
            color: #1a1a1a;
            direction: rtl;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 1.25rem;
            color: #4a7fd4;
        }
        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.95rem;
            margin-top: 12px;
        }
        .balance {
            font-size: 1.5rem;
            font-weight: bold;
            margin-top: 8px;
        }
        .taken { color: #1b8a4a; }
        .given { color: #c62828; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 8px 6px;
            text-align: center;
        }
        th {
            background: #4a7fd4;
            color: #fff;
        }
        tr:nth-child(even) { background: #f8faff; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $person['name'] ?? '-' }}</h1>
        @if(!empty($person['phone']))
            <p style="margin:0;color:#666">{{ $person['phone'] }}</p>
        @endif
        <div class="summary">
            <span>أخذت: <strong class="taken">{{ number_format($total_taken, 2) }} ₪</strong></span>
            <span>أعطيت: <strong class="given">{{ number_format($total_given, 2) }} ₪</strong></span>
        </div>
        <div class="balance {{ $balance >= 0 ? 'taken' : 'given' }}">
            الرصيد: {{ number_format($balance, 2) }} ₪
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:1rem;margin:0 0 12px;color:#4a7fd4">المعاملات ({{ $transactions->count() }})</h2>
        @if($transactions->isEmpty())
            <p>لا توجد معاملات</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                        <th>الرصيد بعد</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $tx)
                        <tr>
                            <td>{{ $tx->transaction_date?->format('Y-m-d') }}</td>
                            <td class="{{ $tx->type === 'taken' ? 'taken' : 'given' }}">
                                {{ $tx->type === 'taken' ? 'أخذت' : 'أعطيت' }}
                            </td>
                            <td>{{ number_format($tx->amount, 2) }} ₪</td>
                            <td>{{ number_format($tx->balance_after, 2) }} ₪</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
