<?php

namespace App\Services;

class SalesProfitReportBuilder
{
    /** Allocate the saved invoice total, including its discount, across all lines. */
    public function document(array $document, array $lines): array
    {
        $gross = round($document['sales_total'] + $document['discount'], 6);
        $lineGross = array_sum(array_column($lines, 'gross'));
        if (($gross >= 0 && $gross > $lineGross + 0.00001) || $lines === []) {
            $lines[] = ['product_code' => '', 'product' => 'خدمات وإضافات', 'quantity' => 1.0,
                'bonus' => 0.0, 'unit' => 'خدمة', 'gross' => max(0, $gross - $lineGross), 'cost_total' => 0.0];
            $lineGross = array_sum(array_column($lines, 'gross'));
        }
        $remainingGross = $gross;
        $remainingNet = $document['sales_total'];
        // The rounding remainder belongs to a priced line, never to a bonus line.
        $pricedLines = array_filter($lines, fn ($line) => abs($line['gross']) > 0.0000001);
        $allocationIndex = array_key_last($pricedLines);
        foreach ($lines as $index => &$line) {
            $last = $index === $allocationIndex;
            $share = abs($lineGross) > 0.0000001 ? $line['gross'] / $lineGross : 0;
            $allocatedGross = $last ? $remainingGross : round($gross * $share, 6);
            $net = $last ? $remainingNet : round($document['sales_total'] * $share, 6);
            $remainingGross -= $allocatedGross;
            $remainingNet -= $net;
            $line['gross'] = $allocatedGross;
            $line['sales_total'] = $net;
            $line['discount'] = round($allocatedGross - $net, 6);
            $line['price'] = $line['quantity'] > 0 ? round($allocatedGross / $line['quantity'], 6) : 0.0;
            $line['unit_cost'] = $line['cost_total'] !== null && $line['quantity'] > 0
                ? round(abs($line['cost_total']) / $line['quantity'], 6) : null;
            $line['profit'] = $line['cost_total'] === null ? null : round($net - $line['cost_total'], 6);
            $line['profit_percent'] = $this->percentage($line['profit'], $line['cost_total']);
        }
        unset($line);
        $complete = ! in_array(null, array_column($lines, 'cost_total'), true);
        $cost = $complete ? round(array_sum(array_column($lines, 'cost_total')), 6) : null;
        $profit = $cost === null ? null : round($document['sales_total'] - $cost, 6);

        return $document + ['cost_total' => $cost, 'profit' => $profit,
            'profit_percent' => $this->percentage($profit, $cost), 'lines' => $lines];
    }

    public function summary(array $documents): array
    {
        $complete = ! in_array(null, array_column($documents, 'cost_total'), true);
        $sales = round(array_sum(array_column($documents, 'sales_total')), 6);
        $cost = $complete ? round(array_sum(array_column($documents, 'cost_total')), 6) : null;
        $profit = $cost === null ? null : round($sales - $cost, 6);

        return ['document_count' => count($documents), 'sales_total' => $sales,
            'discount' => round(array_sum(array_column($documents, 'discount')), 6),
            'cost_total' => $cost, 'profit' => $profit, 'profit_percent' => $this->percentage($profit, $cost),
            'missing_cost_count' => count(array_filter($documents, fn ($row) => $row['cost_total'] === null))];
    }

    private function percentage(?float $profit, ?float $cost): ?float
    {
        return $profit !== null && $cost !== null && abs($cost) > 0.000001
            ? round($profit / abs($cost) * 100, 2) : null;
    }
}
