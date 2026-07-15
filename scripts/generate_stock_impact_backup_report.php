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

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function labelVariant(array $row): string
{
    $parts = [];
    if (! empty($row['size_name'])) {
        $parts[] = 'المقاس: ' . $row['size_name'];
    }
    if (! empty($row['color_name'])) {
        $parts[] = 'اللون: ' . $row['color_name'];
    }

    return $parts ? implode(' / ', $parts) : '-';
}

function fetchImpact(PDO $pdo, string $referenceType): array
{
    $stmt = $pdo->prepare("
        SELECT
            p.id AS product_id,
            p.nameAr AS product_name,
            z.size AS size_name,
            c.colorAr AS color_name,
            COALESCE(c.stock, p.stock) AS stock_in_backup_13,
            SUM(CASE WHEN m.type = 'sale' THEN ABS(m.quantity) ELSE 0 END) AS qty_sold,
            SUM(CASE WHEN m.type = 'sale_cancel' THEN ABS(m.quantity) ELSE 0 END) AS qty_returned_by_cancel,
            ABS(SUM(m.quantity)) AS net_withdrawn,
            SUM(m.quantity) AS net_change,
            COUNT(*) AS movements
        FROM product_stock_movements m
        LEFT JOIN products p ON p.id = m.product_id
        LEFT JOIN sizes z ON z.id = m.size_id
        LEFT JOIN size_colors c ON c.id = m.size_color_id
        WHERE m.reference_type = :reference_type
        GROUP BY p.id, p.nameAr, z.size, c.colorAr, p.stock, c.stock
        HAVING net_change <> 0
        ORDER BY net_withdrawn DESC, product_name
    ");
    $stmt->execute(['reference_type' => $referenceType]);

    return $stmt->fetchAll();
}

function fetchSummary(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            reference_type,
            type,
            COUNT(*) AS movements,
            SUM(quantity) AS total_quantity
        FROM product_stock_movements
        WHERE reference_type IN ('instant_sale', 'sales_order', 'maintenance')
        GROUP BY reference_type, type
        ORDER BY reference_type, type
    ")->fetchAll();
}

function renderTable(array $rows): string
{
    if (! $rows) {
        return '<p class="muted">لا توجد منتجات مؤثرة في هذا القسم.</p>';
    }

    $body = '';
    foreach ($rows as $row) {
        $expectedWithoutImpact = (int) $row['stock_in_backup_13'] + (int) $row['net_withdrawn'];
        $body .= '<tr>'
            . '<td class="num">' . h($row['product_id']) . '</td>'
            . '<td>' . h($row['product_name'] ?: 'بدون اسم') . '</td>'
            . '<td>' . h(labelVariant($row)) . '</td>'
            . '<td class="num">' . h($row['stock_in_backup_13']) . '</td>'
            . '<td class="num">' . h($row['qty_sold']) . '</td>'
            . '<td class="num">' . h($row['qty_returned_by_cancel']) . '</td>'
            . '<td class="num strong">' . h($row['net_withdrawn']) . '</td>'
            . '<td class="num">' . h($expectedWithoutImpact) . '</td>'
            . '</tr>';
    }

    return '
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>المنتج</th>
                    <th>المقاس/اللون</th>
                    <th>مخزون نسخة 13</th>
                    <th>انسحب</th>
                    <th>رجع بالإلغاء</th>
                    <th>الصافي</th>
                    <th>قبل الأثر</th>
                </tr>
            </thead>
            <tbody>' . $body . '</tbody>
        </table>';
}

$sections = [
    'instant_sale' => [
        'title' => 'البيع الفوري',
        'rows' => fetchImpact($pdo, 'instant_sale'),
    ],
    'sales_order' => [
        'title' => 'الطلبيات',
        'rows' => fetchImpact($pdo, 'sales_order'),
    ],
    'maintenance' => [
        'title' => 'الصيانة',
        'rows' => fetchImpact($pdo, 'maintenance'),
    ],
];

$summaryRows = '';
foreach (fetchSummary($pdo) as $row) {
    $summaryRows .= '<tr>'
        . '<td>' . h($row['reference_type']) . '</td>'
        . '<td>' . h($row['type']) . '</td>'
        . '<td class="num">' . h($row['movements']) . '</td>'
        . '<td class="num">' . h($row['total_quantity']) . '</td>'
        . '</tr>';
}

$sectionHtml = '';
foreach ($sections as $section) {
    $net = array_sum(array_map(fn ($row) => (int) $row['net_withdrawn'], $section['rows']));
    $sectionHtml .= '<section>'
        . '<h2>' . h($section['title']) . '</h2>'
        . '<p class="section-note">عدد المنتجات/الألوان المتأثرة: ' . count($section['rows']) . ' | صافي القطع المسحوبة: ' . h($net) . '</p>'
        . renderTable($section['rows'])
        . '</section>';
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
            font-size: 11px;
            line-height: 1.45;
        }
        h1, h2, p { margin: 0; }
        h1 { font-size: 21px; margin-bottom: 5px; }
        h2 {
            font-size: 16px;
            color: #0f4c5c;
            margin: 18px 0 5px;
            border-bottom: 1px solid #bcccdc;
            padding-bottom: 4px;
        }
        .subtitle, .muted, .section-note { color: #627d98; }
        .summary {
            background: #f8fafc;
            border: 1px solid #d9e2ec;
            padding: 8px;
            margin: 12px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 7px;
        }
        th, td {
            border: 1px solid #d9e2ec;
            padding: 5px;
            vertical-align: top;
        }
        th {
            background: #eef2f7;
            color: #243b53;
            font-weight: bold;
        }
        .num { text-align: center; direction: ltr; }
        .strong { font-weight: bold; color: #9f1239; }
        section { page-break-inside: auto; }
    </style>
</head>
<body>
    <h1>تقرير أثر المخزون - نسخة 13 يوليو 2026</h1>
    <p class="subtitle">يشمل البيع الفوري والطلبيات والصيانة من قاعدة drbike_restore_20260713_readonly</p>

    <div class="summary">
        <strong>ملخص الحركات حسب المصدر</strong>
        <table>
            <thead>
                <tr>
                    <th>المصدر</th>
                    <th>نوع الحركة</th>
                    <th>عدد الحركات</th>
                    <th>إجمالي الكمية</th>
                </tr>
            </thead>
            <tbody>' . $summaryRows . '</tbody>
        </table>
    </div>

    <p class="muted">عمود "قبل الأثر" = مخزون نسخة 13 + الصافي المسحوب، وهو تقدير للمخزون قبل تأثير هذه الحركات داخل نفس النسخة.</p>
    ' . $sectionHtml . '
</body>
</html>';

$reportDir = __DIR__ . '/../storage/app/public/reports';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$htmlPath = $reportDir . '/stock-impact-backup-2026-07-13.html';
$pdfPath = $reportDir . '/stock-impact-backup-2026-07-13.pdf';
file_put_contents($htmlPath, $html);

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-L',
    'tempDir' => __DIR__ . '/../storage/framework/cache/mpdf',
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
    'margin_top' => 9,
    'margin_right' => 8,
    'margin_bottom' => 9,
    'margin_left' => 8,
]);

$mpdf->SetDirectionality('rtl');
$mpdf->WriteHTML($html);
$mpdf->Output($pdfPath, 'F');

echo $pdfPath . PHP_EOL;
