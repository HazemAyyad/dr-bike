@extends('pdf.common')
@section('title_1')
    تقرير اهلاك الأصول
@endsection
@section('title_2')
    تفاصيل اهلاك الأصول 

@endsection

@section('section_1')

        @isset($summary)
            <div class="summary">
                <p>عدد الحركات: {{ $summary['count'] }} — مجموع إهلاك الفترة: {{ number_format($summary['depreciation_total'], 2) }}</p>
                @if(!empty($summary['period']))<p>الفترة: {{ $summary['period'] }}</p>@endif
            </div>
        @endisset

        @if(count($logs)===0)
            <div class="summary">
                <p>لا يوجد سجل حركات لاهلاك الأصول</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>الأصل</th>
                        <th>تاريخ الاهلاك</th>

                        <th>نسبة الاهلاك</th>
                        <th>قيمة الإهلاك</th>
                        <th>المبلغ الحالي</th>
                        <th>النوع</th>
                    </tr>
                </thead>
        <tbody>
            @foreach($logs as $log)
                <tr>
                    <td>{{ $log->asset?->name ?? 'أصل محذوف'}}</td>
                    <td>{{$log->created_at? $log->created_at->format('Y-m-d'):'غير معروف'}}</td>
                    <td>{{ $log->asset?->depreciation_rate??'غير معروف'}}</td>
                    <td>{{ $log->depreciation_amount ?? 0 }}</td>

                    <td>{{ $log->total??'غير معروف'}}</td>

                    <td>
                        @if($log->type === 'create')
                            اضافة 
                        @elseif($log->type === 'depreciate')
                            اهلاك 

                        @else
                            غير معروف
                        @endif
                    </td>
                 
                </tr>
            @endforeach
               </tbody>

    </table>

    @endif
@endsection
