<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>قائمة المنتجات — Doctor Bike</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">كل المنتجات</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('test.store-sync') }}">اختبار المخزون</a>
            @php $firstId = \App\Models\Product::query()->orderBy('id')->value('id'); @endphp
            @if ($firstId)
                <a class="btn btn-primary btn-sm" href="{{ route('test.product-edit', ['product_id' => $firstId]) }}">تعديل أول منتج</a>
            @endif
        </div>
    </div>

    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="products-table" class="table table-striped table-hover w-100">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الاسم</th>
                            <th>المخزون</th>
                            <th>السعر القطاعي</th>
                            <th>معروض</th>
                            <th></th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
(function () {
    var url = @json(route('test.products-list.data'));
    $('#products-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: url,
        order: [[0, 'desc']],
        columns: [
            { data: 'id' },
            { data: 'nameAr' },
            { data: 'stock' },
            { data: 'normailPrice' },
            {
                data: 'isShow',
                render: function (d) {
                    return d ? 'نعم' : 'لا';
                }
            },
            {
                data: 'edit_url',
                orderable: false,
                searchable: false,
                render: function (u) {
                    return '<a class="btn btn-sm btn-primary" href="' + u + '">تعديل</a>';
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/ar.json'
        }
    });
})();
</script>
</body>
</html>
