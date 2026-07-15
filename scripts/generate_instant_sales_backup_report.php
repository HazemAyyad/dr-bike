<?php

require __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=drbike_restore_20260713_readonly;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$invoices = $pdo->query("
    SELECT
        id,
        serial_number,
        buyer_type,
        buyer_id,
        buyer_name,
        buyer_phone,
        status,
        total_cost,
        discount,
        payment_box_value,
        created_at,
        sales_order_id,
        maintenance_id
    FROM instant_sales
    WHERE serial_number IS NOT NULL
    ORDER BY id
")->fetchAll();

$itemsStmt = $pdo->prepare("
    SELECT
        s.id,
        s.parent_id,
        p.nameAr AS product_name,
        z.size AS size_name,
        c.colorAr AS color_name,
        s.quantity,
        s.cost,
        s.total_cost,
        s.discount
    FROM instant_sales s
    LEFT JOIN products p ON p.id = s.product_id
    LEFT JOIN sizes z ON z.id = s.size_id
    LEFT JOIN size_colors c ON c.id = s.size_color_id
    WHERE s.id = :invoice_id OR s.parent_id = :invoice_id
    ORDER BY CASE WHEN s.id = :invoice_id THEN 0 ELSE 1 END, s.id
");

$movementStmt = $pdo->prepare("
    SELECT
        p.nameAr AS product_name,
        z.size AS size_name,
        c.colorAr AS color_name,
        SUM(CASE WHEN m.type = 'sale' THEN ABS(m.quantity) ELSE 0 END) AS qty_sold,
        SUM(CASE WHEN m.type = 'sale_cancel' THEN ABS(m.quantity) ELSE 0 END) AS qty_returned,
        SUM(m.quantity) AS net_change
    FROM product_stock_movements m
    LEFT JOIN products p ON p.id = m.product_id
    LEFT JOIN sizes z ON z.id = m.size_id
    LEFT JOIN size_colors c ON c.id = m.size_color_id
    WHERE m.reference_type = 'instant_sale' AND m.reference_id = :invoice_id
    GROUP BY p.nameAr, z.size, c.colorAr
    ORDER BY p.nameAr
");

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float) ($value ?? 0), 2);
}

function productLabel(array $row): string
{
    $parts = array_filter([
        $row['product_name'] ?: 'بدون اسم منتج',
        $row['size_name'] ? 'المقاس: ' . $row['size_name'] : null,
        $row['color_name'] ? 'اللون: ' . $row['color_name'] : null,
    ]);

    return implode(' - ', $parts);
}

$invoiceBlocks = '';
$totals = [
    'invoices' => count($invoices),
    'items' => 0,
    'amount' => 0,
    'paid' => 0,
];

foreach ($invoices as $invoice) {
    $itemsStmt->execute(['invoice_id' => $invoice['id']]);
    $items = $itemsStmt->fetchAll();

    $movementStmt->execute(['invoice_id' => $invoice['id']]);
    $movements = $movementStmt->fetchAll();

    $totals['items'] += count($items);
    $totals['amount'] += (float) $invoice['total_cost'];
    $totals['paid'] += (float) ($invoice['payment_box_value'] ?? 0);

    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>'
            . '<td>' . h(productLabel($item)) . '</td>'
            . '<td class="num">' . h($item['quantity']) . '</td>'
            . '<td class="num">' . money($item['cost']) . '</td>'
            . '<td class="num">' . money($item['total_cost']) . '</td>'
            . '<td class="num">' . money($item['discount']) . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="5" class="muted">لا توجد منتجات مفصلة لهذه الفاتورة</td></tr>';
    }

    $movementRows = '';
    foreach ($movements as $movement) {
        $movementRows .= '<tr>'
            . '<td>' . h(productLabel($movement)) . '</td>'
            . '<td class="num">' . h($movement['qty_sold']) . '</td>'
            . '<td class="num">' . h($movement['qty_returned']) . '</td>'
            . '<td class="num">' . h($movement['net_change']) . '</td>'
            . '</tr>';
    }

    if ($movementRows !== '') {
        $movementTable = '
            <h4>أثر المخزون المسجل على هذه الفاتورة</h4>
            <table>
                <thead>
                    <tr>
                        <th>المنتج</th>
                        <th>نزل من المخزون</th>
                        <th>رجع بالإلغاء</th>
                        <th>الصافي</th>
                    </tr>
                </thead>
                <tbody>' . $movementRows . '</tbody>
            </table>';
    } else {
        $movementTable = '<p class="muted">لا يوجد أثر مخزون مباشر مسجل على رقم هذه الفاتورة.</p>';
    }

    $invoiceBlocks .= '
        <section class="invoice">
            <div class="invoice-head">
                <div>
                    <h2>' . h($invoice['serial_number']) . '</h2>
                    <p>رقم داخلي: #' . h($invoice['id']) . ' | الحالة: ' . h($invoice['status']) . '</p>
                </div>
                <div class="amount">الإجمالي: ' . money($invoice['total_cost']) . '</div>
            </div>

            <div class="meta">
                <div><strong>الزبون/الجهة:</strong> ' . h($invoice['buyer_name']) . '</div>
                <div><strong>الهاتف:</strong> ' . h($invoice['buyer_phone']) . '</div>
                <div><strong>النوع:</strong> ' . h($invoice['buyer_type']) . '</div>
                <div><strong>المدفوع للصندوق:</strong> ' . money($invoice['payment_box_value']) . '</div>
                <div><strong>الخصم:</strong> ' . money($invoice['discount']) . '</div>
                <div><strong>التاريخ:</strong> ' . h($invoice['created_at']) . '</div>
            </div>

            <h4>منتجات الفاتورة</h4>
            <table>
                <thead>
                    <tr>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>سعر الوحدة</th>
                        <th>الإجمالي</th>
                        <th>الخصم</th>
                    </tr>
                </thead>
                <tbody>' . $rows . '</tbody>
            </table>
            ' . $movementTable . '
        </section>';
}

$html = '<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body {
            direction: rtl;
            font-family: dejavusanscondensed, sans-serif;
            color: #1f2933;
            font-size: 12px;
            line-height: 1.55;
        }
        h1, h2, h3, h4, p { margin: 0; }
        h1 { font-size: 22px; margin-bottom: 6px; }
        h2 { font-size: 17px; color: #0f4c5c; }
        h3 { font-size: 15px; margin: 18px 0 8px; }
        h4 { font-size: 13px; margin: 14px 0 6px; color: #334e68; }
        .subtitle { color: #627d98; margin-bottom: 14px; }
        .summary {
            border: 1px solid #d9e2ec;
            background: #f8fafc;
            padding: 10px;
            margin-bottom: 14px;
        }
        .summary table, .summary td { border: 0; }
        .invoice {
            border: 1px solid #d9e2ec;
            padding: 10px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .invoice-head {
            border-bottom: 1px solid #d9e2ec;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        .invoice-head table { border: 0; }
        .amount {
            text-align: left;
            font-size: 14px;
            font-weight: bold;
            color: #243b53;
        }
        .meta {
            display: block;
            margin-bottom: 8px;
        }
        .meta div {
            display: inline-block;
            width: 32%;
            vertical-align: top;
            margin-bottom: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        th, td {
            border: 1px solid #d9e2ec;
            padding: 5px 6px;
            vertical-align: top;
        }
        th {
            background: #eef2f7;
            color: #243b53;
            font-weight: bold;
        }
        .num { text-align: center; direction: ltr; }
        .muted { color: #829ab1; }
    </style>
</head>
<body>
    <h1>تقرير فواتير البيع الفوري - نسخة 13 يوليو 2026</h1>
    <p class="subtitle">مصدر التقرير: قاعدة مؤقتة من النسخة الاحتياطية u227071417_dr_bike.20260713180015</p>

    <div class="summary">
        <table>
            <tr>
                <td><strong>عدد الفواتير الرئيسية:</strong> ' . h($totals['invoices']) . '</td>
                <td><strong>عدد أسطر المنتجات:</strong> ' . h($totals['items']) . '</td>
                <td><strong>إجمالي الفواتير:</strong> ' . money($totals['amount']) . '</td>
                <td><strong>إجمالي المدفوع:</strong> ' . money($totals['paid']) . '</td>
            </tr>
        </table>
    </div>

    ' . $invoiceBlocks . '
</body>
</html>';

$reportDir = __DIR__ . '/../storage/app/public/reports';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$htmlPath = $reportDir . '/instant-sales-backup-2026-07-13.html';
$pdfPath = $reportDir . '/instant-sales-backup-2026-07-13.pdf';
file_put_contents($htmlPath, $html);

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'tempDir' => __DIR__ . '/../storage/framework/cache/mpdf',
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
    'margin_top' => 10,
    'margin_right' => 10,
    'margin_bottom' => 10,
    'margin_left' => 10,
]);

$mpdf->SetDirectionality('rtl');
$mpdf->WriteHTML($html);
$mpdf->Output($pdfPath, 'F');

echo $pdfPath . PHP_EOL;
