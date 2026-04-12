<!DOCTYPE html>
<html>


<head>
    <meta charset="utf-8">
    <title>Debt Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 16px; }
        h2 { text-align: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table, th, td { border: 1px solid #000; }
        th, td { padding: 8px; text-align: left; }
        .summary { margin-top: 20px; }


    </style>
</head>
<body>
    <div style="margin-bottom: 25px;">
        <table style="border: none; width: auto;">
            <tr>
                <td style="border: none; vertical-align: middle; padding-right: 15px;">
                    <img src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike Logo" style="height:60px; width:auto;">
                </td>
                <td style="border: none; vertical-align: middle;">
                    <h1 style="margin: 0; font-size: 15px; font-weight: bold;">DoctorBike</h1>
                </td>
            </tr>
        </table>
    </div>
    <h2>Debt Report</h2>

    <p>
        <strong>{{ $type == 'customer' ? 'Customer' : 'Seller' }} Name:</strong>
        <span class="rtl">{{ $person->name }}</span>
        <br>
    </p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Due Date</th>
                <th>Total</th>
                <th>Status</th>
                <th>Type</th>
                <th>Created At</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($debts as $debt)
                <tr>
                    <td>{{ $debt->id }}</td>
                    <td>{{ $debt->due_date ?? 'N/A' }}</td>
                    <td>{{ number_format($debt->total, 2) }}</td>
                    <td>

                        {{ $debt->status==='paid'? 'مدفوع' : 'غير مدفوع' }}
                    
                    </td>
                    <td>{{ $debt->type==='owed to us'? 'دين لنا' : 'دين علينا' }}</td>
                    <td>{{ $debt->created_at->format('Y-m-d') }}</td>

                    <td>{{ $debt->notes ?? 'No notes' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <p><strong>Balance:</strong> {{ number_format($balance, 2) }}</p>
    </div>
</body>
</html>
