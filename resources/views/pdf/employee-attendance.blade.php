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
    <h2>سجل دوام</h2>


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



                <td>
                    @if($attendance->worked_minutes)
                        {{ \Carbon\CarbonInterval::minutes($attendance->worked_minutes)->cascade()->format('%H:%I:%S') }}
                    @else
                        لا يوجد عدد ساعات عمل
                    @endif
                </td>

              
              
              
                </tr>
            @endforeach
        </tbody>
    </table>

    @endif

</body>
</html>
