<!DOCTYPE html>
<html lang="ar">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>كشف طلبية</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; text-align: right; color: #111827; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        h2 { font-size: 15px; margin: 16px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #E5E7EB; padding: 6px 8px; }
        th { background: #F5F6F8; }
        .meta p { margin: 3px 0; }
    </style>
</head>
<body>
    <h1>كشف طلبية — {{ $order->serial_number ?? '#'.$order->id }}</h1>
    <div class="meta">
        <p><strong>الزبون:</strong> {{ $order->customer_name ?? '—' }}</p>
        <p><strong>الهاتف:</strong> {{ $order->customer_phone ?? '—' }}</p>
        <p><strong>المدينة:</strong> {{ $order->city?->name_ar ?? '—' }}</p>
        <p><strong>الحالة:</strong> {{ $order->status }}</p>
        <p><strong>تاريخ الإنشاء:</strong> {{ $order->created_at?->format('Y-m-d H:i') }}</p>
        <p><strong>تاريخ التقرير:</strong> {{ $generated_at }}</p>
    </div>

    <h2>الأصناف</h2>
    <table>
        <thead>
            <tr>
                <th>الصنف</th>
                <th>الكمية</th>
                <th>موصّل</th>
                <th>السعر</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                @if(!$item->is_hidden)
                <tr>
                    <td>{{ $item->product_name ?? $item->product_id }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $item->delivered_qty ?? 0 }}</td>
                    <td>{{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ number_format($item->line_total, 2) }}</td>
                </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <h2>الملخص</h2>
    <table>
        <tr><td>المجموع الفرعي</td><td>{{ number_format($order->subtotal, 2) }} ₪</td></tr>
        <tr><td>التوصيل</td><td>{{ number_format($order->customer_delivery_fee, 2) }} ₪</td></tr>
        <tr><td>الخصم</td><td>{{ number_format($order->discount, 2) }} ₪</td></tr>
        <tr><td><strong>الإجمالي</strong></td><td><strong>{{ number_format($order->total, 2) }} ₪</strong></td></tr>
    </table>

    @if($order->childOrders->isNotEmpty())
        <h2>طلبيات متابعة</h2>
        <table>
            <thead>
                <tr><th>الرقم</th><th>الحالة</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
                @foreach($order->childOrders as $child)
                    <tr>
                        <td>{{ $child->serial_number ?? '#'.$child->id }}</td>
                        <td>{{ $child->status }}</td>
                        <td>{{ number_format($child->total, 2) }} ₪</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
