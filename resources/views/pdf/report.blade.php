<!DOCTYPE html>
<html>


<head>
    <meta charset="utf-8">
    <title>Report</title>
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
    <h2>Report</h2>


@if(count($logs)===0)
    <div class="summary">
        <p>لا يوجد سجل نشاط في هذه الفترة</p>
    </div>
@else
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Description</th>

            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
                <tr>
                    <td>{{ $log->name? $log->name : 'لا يوجد عنوان' }}</td>
                    <td>{{ $log->description? $log->description:'لا يوجد وصف' }}</td>

                </tr>
            @endforeach
        </tbody>
    </table>

    @endif

</body>
</html>
