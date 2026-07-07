<?php

namespace App\Services;

use App\Models\SalesOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use ArPHP\I18N\Arabic;

class SalesOrderStatementService
{
    public function generatePdfUrl(SalesOrder $order): array
    {
        $order->loadMissing([
            'customer:id,name,phone,address',
            'city:id,name_ar',
            'items',
            'statusLogs.user:id,name',
            'childOrders:id,parent_order_id,serial_number,status,total',
        ]);

        $reportHtml = view('pdf.sales-order-statement', [
            'order' => $order,
            'statementItems' => $this->statementItems($order),
            'generated_at' => now()->format('Y-m-d H:i'),
        ])->render();

        $arabic = new Arabic();
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(
                substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
            );
            $reportHtml = substr_replace(
                $reportHtml,
                $utf8ar,
                $positions[$i - 1],
                $positions[$i] - $positions[$i - 1]
            );
        }

        $pdf = Pdf::loadHTML($reportHtml);
        $fileName = 'sales_order_'.$order->id.'_'.time().'.pdf';
        $path = 'sales-order-reports/'.$fileName;
        $fullPath = public_path($path);

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        $pdf->save($fullPath);

        return [
            'pdf_url' => url($path),
            'file_name' => $fileName,
            'serial_number' => $order->serial_number,
            'total' => (float) $order->total,
            'status' => $order->status,
        ];
    }

    private function statementItems(SalesOrder $order): array
    {
        return $order->items
            ->where('is_hidden', false)
            ->groupBy(fn ($item) => implode('|', [
                $item->product_id,
                $item->size_id,
                $item->size_color_id,
                $item->product_name,
                (float) $item->unit_price,
            ]))
            ->map(function ($items) {
                $first = $items->first();
                $quantity = (int) $items->sum('quantity');
                $delivered = (int) $items->sum('delivered_qty');
                $lineTotal = (float) $items->sum('line_total');

                return [
                    'product_name' => $first->product_name ?? $first->product_id,
                    'quantity' => $quantity,
                    'delivered_qty' => $delivered,
                    'unit_price' => (float) $first->unit_price,
                    'line_total' => $lineTotal,
                ];
            })
            ->values()
            ->all();
    }
}
