<!DOCTYPE html>
<html>


<head>
    <meta charset="utf-8">
    <title>تقرير حركات الصندوق</title>
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
<h2>حركات الصندوق - {{ $box->name }}</h2>


@if(count($logs)===0)
    <div class="summary">
        <p>لا يوجد سجل حركات للصندوق</p>
    </div>
@else
    <table>
        <thead>
            <tr>
                <th>الحركة</th>
                <th>الوصف</th>

                <th>القيمة</th>
                <th></th>
                <th>التاريخ</th>
            </tr>
        </thead>
<tbody>
    @foreach($logs as $log)
        <tr>
            <td>
                @if($log->type === 'add')
                    اضافة رصيد
                @elseif($log->type === 'minus')
                    سحب رصيد
                @elseif($log->type === 'transfer')
                    نقل الرصيد
                @else
                    غير معروف
                @endif
            </td>
            <td>{{ $log->description?? 'لا يوجد وصف' }}</td>

            <td>{{ number_format($log->value, 2) }}</td>
            <td>
                @if($log->type === 'transfer')
                    الى الرصيد {{ $log->toBox ? $log->toBox->name : '---' }}
                    من الرصيد {{ $log->fromBox ? $log->fromBox->name : '---' }}  

                    @endif
            </td>
            <td>{{$log->created_at->format('Y-m-d')}}</td>
        </tr>
    @endforeach
</tbody>

    </table>

    @endif

</body>
</html>
