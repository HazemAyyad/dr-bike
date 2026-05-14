<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Employee Financial Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; direction: rtl; }
        .header { width: 100%; margin-bottom: 18px; border-bottom: 2px solid #111827; padding-bottom: 12px; }
        .logo { height: 58px; width: auto; }
        .brand { font-size: 18px; font-weight: bold; margin: 0; }
        .muted { color: #6b7280; }
        h2 { text-align: center; margin: 10px 0 16px; font-size: 18px; }
        h3 { margin: 18px 0 8px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 7px; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; }
        .summary td { width: 25%; }
        .total { background: #ecfdf5; font-weight: bold; font-size: 14px; }
        .empty { border: 1px solid #d1d5db; padding: 12px; color: #6b7280; text-align: center; margin-top: 8px; }
        .signatures { width: 100%; margin-top: 42px; }
        .signature-cell { width: 50%; border: none; padding-top: 28px; }
        .line { border-top: 1px solid #111827; padding-top: 8px; width: 80%; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="border: none; width: 72px;">
                <img src="{{ public_path('appImages/logo.jpg') }}" alt="DoctorBike Logo" class="logo">
            </td>
            <td style="border: none;">
                <p class="brand">DoctorBike</p>
                <span class="muted">Employee Financial Entitlements Report</span>
            </td>
            <td style="border: none; text-align: left;">
                <strong>الشهر:</strong> {{ $month ?? ($financialData['selected_month'] ?? '') }}<br>
                <span class="muted">{{ now()->format('Y-m-d h:i A') }}</span>
            </td>
        </tr>
    </table>

    <h2>تقرير الاستحقاقات المالية للموظف</h2>

    <h3>بيانات الموظف</h3>
    <table class="summary">
        <tr>
            <th>اسم الموظف</th>
            <td>{{ $financialData['employee_name'] }}</td>
            <th>الشهر المحدد</th>
            <td>{{ $financialData['selected_month'] ?? $month }}</td>
        </tr>
        <tr>
            <th>سعر ساعة العمل</th>
            <td>{{ $financialData['hour_work_price'] }}</td>
            <th>ساعات العمل اليومية</th>
            <td>{{ $financialData['number_of_work_hours'] }}</td>
        </tr>
    </table>

    <h3>الملخص المالي</h3>
    <table class="summary">
        <tr>
            <th>الراتب الأساسي</th>
            <td>{{ $financialData['base_salary'] ?? $financialData['salary'] }}</td>
            <th>راتب الساعات العادية</th>
            <td>{{ $financialData['normal_salary'] ?? '0.00' }}</td>
        </tr>
        <tr>
            <th>الأوفر تايم</th>
            <td>{{ $financialData['overtime_hours'] ?? '0.00' }} ساعة / {{ $financialData['overtime_salary'] ?? '0.00' }}</td>
            <th>الإضافات / المكافآت</th>
            <td>{{ $financialData['additions'] ?? '0.00' }}</td>
        </tr>
        <tr>
            <th>السلف</th>
            <td>{{ $financialData['advances'] ?? '0.00' }}</td>
            <th>الخصومات</th>
            <td>{{ $financialData['deductions'] ?? '0.00' }}</td>
        </tr>
        <tr class="total">
            <th>صافي الاستحقاق النهائي</th>
            <td colspan="3">{{ $financialData['final_net_entitlement'] ?? $financialData['total'] }}</td>
        </tr>
    </table>

    <h3>ملخص الدوام</h3>
    <table class="summary">
        <tr>
            <th>أيام الحضور</th>
            <td>{{ $financialData['attendance_days'] ?? 0 }}</td>
            <th>أيام الغياب</th>
            <td>{{ $financialData['absent_days'] ?? 0 }}</td>
        </tr>
        <tr>
            <th>أيام التأخير</th>
            <td>{{ $financialData['late_days'] ?? 0 }}</td>
            <th>إجمالي التأخير</th>
            <td>{{ $financialData['delay_hours'] ?? '0.00' }} ساعة</td>
        </tr>
    </table>

    <h3>تفاصيل الدوام</h3>
    @if(count($attendances)===0)
        <div class="empty">لا يوجد سجل دوام لهذا الشهر</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>وقت الوصول</th>
                    <th>وقت المغادرة</th>
                    <th>ساعات العمل</th>
                </tr>
            </thead>
            <tbody>
                @foreach($attendances as $attendance)
                    <tr>
                        <td>{{ $attendance->date ? $attendance->date : $attendance->created_at->format('Y-m-d') }}</td>
                        <td>{{ $attendance->arrived_at ? \Carbon\Carbon::createFromFormat('H:i:s', $attendance->arrived_at)->format('h:i A') : 'لا يوجد وقت حضور' }}</td>
                        <td>{{ $attendance->left_at ? \Carbon\Carbon::createFromFormat('H:i:s', $attendance->left_at)->format('h:i A') : 'لا يوجد وقت انصراف' }}</td>
                        <td>{{ $attendance->worked_minutes ? \Carbon\CarbonInterval::minutes($attendance->worked_minutes)->cascade()->format('%H:%I') : '0:00' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>السلف</h3>
    @if(empty($advancesData['advances']) || count($advancesData['advances']) === 0)
        <div class="empty">لا توجد سلف لهذا الشهر</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>الحالة</th>
                    <th>المبلغ</th>
                    <th>اليوم</th>
                    <th>التاريخ</th>
                    <th>الوقت</th>
                </tr>
            </thead>
            <tbody>
                @foreach($advancesData['advances'] as $advance)
                    <tr>
                        <td>{{ $advance['status'] }}</td>
                        <td>{{ $advance['amount'] }}</td>
                        <td>{{ $advance['day'] }}</td>
                        <td>{{ $advance['date'] }}</td>
                        <td>{{ $advance['time'] }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="4">إجمالي السلف</td>
                    <td>{{ $advancesData['total'] ?? 0 }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="signatures">
        <tr>
            <td class="signature-cell"><div class="line">توقيع الموظف</div></td>
            <td class="signature-cell"><div class="line">توقيع الإدارة</div></td>
        </tr>
    </table>
</body>
</html>
