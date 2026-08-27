<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; direction: rtl; font-size: 11px; }
        h1, p { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border: 1px solid #bbb; padding: 6px; text-align: right; }
        th { background: #f1f5f9; }
        .summary { font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
<h1>تقرير المصاريف</h1>
<p>تاريخ الإصدار: {{ now()->format('Y-m-d H:i') }}</p>
<p class="summary">عدد القيود: {{ $summary['count'] }} — الإجمالي: {{ number_format($summary['total'], 2) }}</p>
<table>
    <thead>
    <tr><th>#</th><th>التاريخ</th><th>النوع</th><th>المصروف</th><th>القيمة</th><th>الصندوق</th><th>ملاحظات</th></tr>
    </thead>
    <tbody>
    @php($labels = ['general' => 'عمومي', 'salary' => 'راتب', 'destruction' => 'إتلاف بضاعة'])
    @forelse($expenses as $expense)
        <tr>
            <td>{{ $expense->id }}</td>
            <td>{{ optional($expense->expense_date ?? $expense->created_at)->format('Y-m-d') }}</td>
            <td>{{ $labels[$expense->expense_type ?: 'general'] ?? $expense->expense_type }}</td>
            <td>{{ $expense->name }}</td>
            <td>{{ number_format($expense->price, 2) }} {{ $expense->box?->currency }}</td>
            <td>{{ $expense->box?->name }}</td>
            <td>{{ $expense->notes }}</td>
        </tr>
    @empty
        <tr><td colspan="7">لا توجد بيانات ضمن الفلاتر المحددة.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
