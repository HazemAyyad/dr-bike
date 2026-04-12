<!DOCTYPE html>
<html>


<head>
    <meta charset="utf-8">
    <title>Bill Details</title>
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
    <h2>Bill</h2>

    <table>
        <thead>
            <tr>
                <th>Product Name</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Sub Toatal</th>
                <th>Status</th>
                <th></th>

            </tr>
        </thead>
        <tbody>
            @foreach($bill->items as $item)
                <tr>
                    <td>{{ $item->product->nameAr? $item->product->nameAr : 'لا يوجد اسم للمنتج' }}</td>
                    <td>{{ $item->quantity? $item->quantity: 0 }}</td>
                    <td>{{ $item->price? $item->price: 0 }}</td>
                    <td>{{ $item->price * $item->quantity }}</td>
                    <td>
                        {{
                            match($item->status) {
                                'unfinished'      => 'غير معالج',
                                'finished'        => 'مكتمل',
                                'extra'           => 'مرتجع زيادة',
                                'not_compatible'  => 'غير متوافق',
                                default           => $item->status,
                            }
                        }}
                    </td>
                    <td>
                        {{
                            match(true) {
                                $item->status === 'finished' && !is_null($item->missing_amount) => ($item->missing_amount ?? 0) . ' نقص',
                                $item->status === 'extra' => $item->extra_amount ?? 0,
                                $item->status === 'not_compatible' => $item->not_compatible_amount ?? 0,
                                default => '-',
                            }
                        }}
                    </td>


                </tr>
            @endforeach


        </tbody>
    </table>
    <div class="summary">
        <p>Total Bill: {{$bill->total}}</p>
    </div>

</body>
</html>
