<!DOCTYPE html>
<html>


<head>
    <meta charset="utf-8">
    <title>سجل دوام</title>
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
    <h2>كشف حساب الموظف وسجل دوام</h2>

    <table>
        <thead>
            <tr>
                <th>
                    الموظف اسم
                </th>              
               <th>السلفة قيمة</th>
                <th>الراتب</th>
                <th> النقاط عدد </th>
             
                <th>
                العمل سعر ساعة
                </th>             
                <th>العمل اليومي عدد ساعات</th>
                <th>الاجمالي</th>


            </tr>
        </thead>
        <tbody>
                <tr>
                    <td>{{ $financialData['employee_name'] }}</td>
                    <td>{{ $financialData['debts'] }}</td>
                    <td>{{ $financialData['salary'] }}</td>
                    <td>{{ $financialData['points'] }}</td>
                    <td>{{ $financialData['hour_work_price'] }}</td>
                    <td>{{ $financialData['number_of_work_hours'] }}</td>
                    <td>{{ $financialData['total'] }}</td>


    
                </tr>
        </tbody>
    </table>

    <br>

@if(count($attendances)===0)
    <div class="summary">
        <p>لا يوجد سجل دوام</p>
    </div>
@else
    <table>
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>وقت الوصول</th>
                <th>وقت المغادرة</th>
                <th>عدد ساعات العمل</th>

            </tr>
        </thead>
        <tbody>
            @foreach($attendances as $attendance)
                <tr>
                    <td>{{ $attendance->date? $attendance->date : $attendance->created_at->format('Y-m-d') }}</td>
                <td>
                {{ $attendance->arrived_at 
                    ? \Carbon\Carbon::createFromFormat('H:i:s', $attendance->arrived_at)->format('h:i A') 
                    : 'لا يوجد وقت حضور' }}
            </td>

            <td>
                {{ $attendance->left_at 
                    ? \Carbon\Carbon::createFromFormat('H:i:s', $attendance->left_at)->format('h:i A') 
                    : 'لا يوجد وقت انصراف' }}
            </td>
                    <td>{{ $attendance->worked_minutes? ($attendance->worked_minutes/60):'لا يوجد عدد ساعات عمل' }}</td>

                </tr>
            @endforeach
        </tbody>
    </table>

    @endif

@if(count($rewards)===0)
    <div class="summary">
        <p>لا يوجد سجل لخصم او اضافة نقاط</p>
    </div>
@else
    <table>
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>النوع</th>
                <th>النقاط</th>
                <th>ملاحظات</th>

            </tr>
        </thead>
        <tbody>
            @foreach($rewards as $reward)
                <tr>
                    <td>{{ $reward->created_at->format('Y-m-d') }}</td>
                <td>
                {{ $reward->type }}
            </td>

            <td>
              {{ $reward->points }}
            </td>
                    <td>{{ $reward->notes??'لا يوجد ملاحظات' }}</td>

                </tr>
            @endforeach
        </tbody>
    </table>

    @endif


</body>
</html>
