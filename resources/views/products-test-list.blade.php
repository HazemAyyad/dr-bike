<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>قائمة المنتجات — Doctor Bike</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">كل المنتجات</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-success btn-sm" href="{{ route('test.product-create') }}">منتج جديد</a>
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
                            <th>المتجر</th>
                            <th></th>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
(function () {
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    var deleteProductUrl = @json(route('test.product-edit.delete-product'));
    var productsListUrl = @json(route('test.products-list'));
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
                data: 'in_store',
                orderable: false,
                searchable: false,
                render: function (d) {
                    if (d === true) {
                        return '<span class="badge bg-success">موجود</span>';
                    }
                    if (d === false) {
                        return '<span class="badge bg-danger">غير موجود</span>';
                    }
                    return '<span class="badge bg-secondary">؟</span>';
                }
            },
            {
                data: 'edit_url',
                orderable: false,
                searchable: false,
                render: function (u) {
                    return '<a class="btn btn-sm btn-primary" href="' + u + '">تعديل</a>';
                }
            },
            {
                data: 'id',
                orderable: false,
                searchable: false,
                render: function (id) {
                    return '<button type="button" class="btn btn-sm btn-outline-danger btn-del-prod" data-id="' + id + '">حذف</button>';
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/ar.json'
        }
    });

    $('#products-table').on('click', '.btn-del-prod', function () {
        var pid = parseInt($(this).data('id'), 10);
        if (!pid) {
            return;
        }
        Swal.fire({
            title: 'حذف المنتج #' + pid,
            html: '<p class="small text-muted text-end">اختر نوع الحذف.</p>',
            input: 'select',
            inputOptions: {
                soft: 'أرشفة (soft delete)',
                laravel_hard: 'حذف نهائي من Laravel فقط',
                store_and_laravel: 'حذف من المتجر + Laravel نهائياً'
            },
            showCancelButton: true,
            confirmButtonText: 'تنفيذ',
            cancelButtonText: 'إلغاء',
            inputValidator: function (value) {
                if (!value) {
                    return 'اختر نوع الحذف';
                }
            }
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) {
                return;
            }
            fetch(deleteProductUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ product_id: pid, mode: result.value })
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { data: data };
                    });
                })
                .then(function (out) {
                    if (out.data && out.data.ok) {
                        Swal.fire({ icon: 'success', title: out.data.message || 'تم' }).then(function () {
                            window.location.href = productsListUrl;
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: (out.data && out.data.message) ? out.data.message : 'فشل' });
                    }
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', title: 'خطأ في الاتصال' });
                });
        });
    });
})();
</script>
</body>
</html>
