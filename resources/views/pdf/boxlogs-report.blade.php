<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير حركات الصندوق</title>
    <style>
        @page { margin: 22px 26px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; direction: rtl; color: #111827; font-size: 10px; }
        .header { width: 100%; border-collapse: collapse; }
        .header td { border: 0; vertical-align: middle; }
        .logo { width: 120px; height: 78px; object-fit: contain; }
        .brand { color: #6B65BD; font-weight: bold; font-size: 20px; text-align: right; }
        .report-code { text-align: center; color: #6B7280; font-size: 8px; }
        .divider { height: 2px; background: #6B65BD; margin: 8px 0; }
        h1 { margin: 4px 0 8px; text-align: center; font-size: 16px; }
        .meta, .summary, .logs { width: 100%; border-collapse: collapse; }
        .meta { border: 1px solid #D1D5DB; margin-bottom: 10px; }
        .meta td { padding: 6px 9px; width: 33.33%; }
        .label { font-weight: bold; }
        .summary { margin: 6px 0 12px; border-spacing: 5px; border-collapse: separate; }
        .summary td { border: 1px solid #D1D5DB; border-radius: 4px; padding: 7px; text-align: center; background: #F9FAFB; }
        .summary .value { font-size: 13px; font-weight: bold; margin-top: 3px; }
        .incoming { color: #15803D; }
        .outgoing { color: #B91C1C; }
        .logs th { color: white; background: #6B65BD; padding: 6px 4px; border: 1px solid #D1D5DB; }
        .logs td { padding: 6px 4px; border: 1px solid #D1D5DB; vertical-align: top; }
        .logs tr:nth-child(even) td { background: #F9FAFB; }
        .amount { direction: ltr; text-align: center; white-space: nowrap; font-weight: bold; }
        .muted { color: #6B7280; }
        .empty { border: 1px solid #D1D5DB; background: #F9FAFB; padding: 24px; text-align: center; }
        .footer { position: fixed; bottom: -18px; left: 0; right: 0; color: #6B7280; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 34%; text-align: left;">
                <img class="logo" src="{{ public_path('appImages/logo.jpg') }}">
            </td>
            <td style="width: 32%;" class="report-code">
                BOX-{{ $box->id }}<br>{{ now()->format('Y-m-d H:i') }}
            </td>
            <td style="width: 34%;" class="brand">دكتور بايك - تقرير صندوق</td>
        </tr>
    </table>
    <div class="divider"></div>
    <h1>تقرير حركات الصندوق</h1>

    <table class="meta">
        <tr>
            <td><span class="label">اسم الصندوق:</span> {{ $box->name }}</td>
            <td><span class="label">نوع الصندوق:</span> {{ $box->type ?: 'صندوق عادي' }}</td>
            <td><span class="label">العملة:</span> {{ $box->currency }}</td>
        </tr>
        <tr>
            <td><span class="label">من تاريخ:</span> {{ $filters['from_date'] }}</td>
            <td><span class="label">إلى تاريخ:</span> {{ $filters['to_date'] }}</td>
            <td><span class="label">عدد الحركات:</span> {{ $summary['movements_count'] }}</td>
        </tr>
        @if(!empty($filters['search']) || !empty($filters['direction']) || !empty($filters['types']))
        <tr>
            <td colspan="3"><span class="label">الفلاتر:</span>
                {{ $filters['search'] ?? '' }}
                {{ $filters['direction'] ?? '' }}
                {{ !empty($filters['types']) ? implode('، ', $filters['types']) : '' }}
            </td>
        </tr>
        @endif
    </table>

    <table class="summary"><tr>
        <td><div>الرصيد الافتتاحي</div><div class="value">{{ number_format($summary['opening_balance'], 2) }} {{ $box->currency }}</div></td>
        <td><div>إجمالي الوارد</div><div class="value incoming">{{ number_format($summary['incoming'], 2) }} {{ $box->currency }}</div></td>
        <td><div>إجمالي الصادر</div><div class="value outgoing">{{ number_format($summary['outgoing'], 2) }} {{ $box->currency }}</div></td>
        <td><div>صافي الحركة</div><div class="value">{{ number_format($summary['net'], 2) }} {{ $box->currency }}</div></td>
        <td><div>الرصيد الختامي</div><div class="value">{{ number_format($summary['closing_balance'], 2) }} {{ $box->currency }}</div></td>
    </tr></table>

    @if($logs->isEmpty())
        <div class="empty">لا توجد حركات مطابقة للفلاتر المحددة.</div>
    @else
        <table class="logs">
            <thead><tr>
                <th style="width: 4%;">#</th>
                <th style="width: 13%;">التاريخ والوقت</th>
                <th style="width: 10%;">النوع</th>
                <th style="width: 25%;">البيان</th>
                <th style="width: 18%;">ملاحظة</th>
                <th style="width: 11%;">وارد</th>
                <th style="width: 11%;">صادر</th>
                <th style="width: 8%;">مرجع</th>
            </tr></thead>
            <tbody>
            @foreach($logs as $index => $log)
                @php($signed = (float) $log->signed_amount)
                <tr>
                    <td style="text-align:center">{{ $index + 1 }}</td>
                    <td style="direction:ltr;text-align:center">{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                    <td style="text-align:center">{{ $log->type ?: 'حركة' }}</td>
                    <td>{{ $log->description ?: '-' }}</td>
                    <td class="muted">{{ $log->note ?: '-' }}</td>
                    <td class="amount incoming">{{ $signed > 0 ? number_format($signed, 2) : '-' }}</td>
                    <td class="amount outgoing">{{ $signed < 0 ? number_format(abs($signed), 2) : '-' }}</td>
                    <td style="text-align:center">{{ $log->reference ?? $log->invoice_number ?? $log->id }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">تم إنشاء التقرير من نظام Doctor Bike — القيم محسوبة من منظور الصندوق المحدد</div>
</body>
</html>
