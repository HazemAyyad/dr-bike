<?php

namespace App\Services;

use Carbon\Carbon;

class SalesAccountingReportBuilder
{
    /** Sales amounts are persisted AFTER invoice discounts; return amounts are actual refunds. */
    public function build(iterable $sales, iterable $returns, string $groupBy, bool $includeReturns, string $sort = 'sales_desc', array $filters = []): array
    {
        $sales = $this->select($sales, $filters);
        $returns = $this->select($returns, $filters);
        $groups = [];
        foreach ($sales as $sale) {
            $key = $this->key($sale, $groupBy);
            $groups[$key] ??= $this->emptyRow($sale, $groupBy, $key);
            $groups[$key]['invoice_count']++;
            $groups[$key]['sales_total'] += (float) $sale['total'];
            $groups[$key]['sales_discount'] += (float) ($sale['discount'] ?? 0);
        }
        if ($includeReturns) {
            foreach ($returns as $return) {
                $key = $this->key($return, $groupBy);
                $groups[$key] ??= $this->emptyRow($return, $groupBy, $key);
                $groups[$key]['return_count']++;
                $groups[$key]['returns_total'] += (float) $return['total'];
                if (($return['discount'] ?? null) === null) {
                    $groups[$key]['return_discount_complete'] = false;
                } else {
                    $groups[$key]['return_discount'] += (float) $return['discount'];
                }
            }
        }

        $totalSales = array_sum(array_column($groups, 'sales_total'));
        $rows = [];
        foreach ($groups as $row) {
            $row['gross_sales'] = $row['sales_total'] + $row['sales_discount'];
            $row['net_sales'] = $row['sales_total'] - $row['returns_total'];
            $row['sales_share'] = $this->percentage($row['sales_total'], $totalSales);
            $row['return_rate'] = $this->percentage($row['returns_total'], $row['sales_total']);
            if (! $row['return_discount_complete']) {
                $row['return_discount'] = null;
            }
            $rows[] = $this->roundAmounts($row);
        }
        usort($rows, fn (array $a, array $b) => match ($sort) {
            'label' => strcmp($a['label'], $b['label']) ?: strcmp($a['key'], $b['key']),
            'sales_asc' => ($a['sales_total'] <=> $b['sales_total']) ?: strcmp($a['key'], $b['key']),
            default => ($b['sales_total'] <=> $a['sales_total']) ?: strcmp($a['key'], $b['key']),
        });

        $summary = ['row_count' => count($rows)];
        foreach (['invoice_count', 'return_count', 'gross_sales', 'sales_discount', 'sales_total', 'returns_total', 'net_sales'] as $field) {
            $summary[$field] = round(array_sum(array_column($rows, $field)), 2);
        }
        $summary['return_discount'] = in_array(false, array_column($rows, 'return_discount_complete'), true)
            ? null : round(array_sum(array_column($rows, 'return_discount')), 2);
        $summary['sales_share'] = $totalSales > 0 ? 100.0 : null;
        $summary['return_rate'] = $this->percentage($summary['returns_total'], $totalSales);

        return ['rows' => $rows, 'summary' => $summary];
    }

    private function key(array $row, string $groupBy): string
    {
        return match ($groupBy) {
            'day' => Carbon::parse($row['date'])->format('Y-m-d'),
            'month' => Carbon::parse($row['date'])->format('Y-m'),
            default => $row['person_key'],
        };
    }

    private function emptyRow(array $source, string $groupBy, string $key): array
    {
        return [
            'key' => $key,
            'label' => $groupBy === 'customer' ? $source['person_name'] : $key,
            'year' => $groupBy === 'month' ? (int) substr($key, 0, 4) : null,
            'month' => $groupBy === 'month' ? (int) substr($key, 5, 2) : null,
            'person_type' => $groupBy === 'customer' ? ($source['person_type'] ?? 'unknown') : null,
            'invoice_count' => 0,
            'return_count' => 0,
            'sales_total' => 0.0,
            'sales_discount' => 0.0,
            'returns_total' => 0.0,
            'return_discount' => 0.0,
            'return_discount_complete' => true,
        ];
    }

    public function normalizeCurrency(?string $currency): string
    {
        return match (mb_strtolower(trim((string) $currency))) {
            'usd', 'dollar', '$', 'دولار' => 'دولار',
            'jod', 'dinar', 'دينار' => 'دينار',
            'ils', 'nis', 'shekel', 'شيكل', '' => 'شيكل',
            default => (string) $currency,
        };
    }

    /** Each currency owns its totals and percentage denominators. No conversion. */
    public function byCurrency(array $sales, array $returns, array $filters): array
    {
        $currencies = [$filters['currency'] ?? 'شيكل'];
        if (! empty($filters['include_other_currencies'])) {
            foreach (array_merge($sales, $returns) as $row) {
                $currencies[] = $this->normalizeCurrency($row['currency'] ?? null);
            }
        }
        $reports = [];
        foreach (array_unique($currencies) as $currency) {
            $report = $this->build($sales, $returns, $filters['group_by'] ?? 'customer',
                (bool) ($filters['include_returns'] ?? false), $filters['sort'] ?? 'label',
                array_merge($filters, ['currency' => $currency]));
            if ($currency === $currencies[0] || $report['rows'] !== []) {
                $reports[] = array_merge($report, ['currency' => $currency]);
            }
        }

        return $reports;
    }

    /** Value limits apply to each saved invoice/refund before aggregation. */
    public function select(iterable $rows, array $filters): array
    {
        $selected = [];
        foreach ($rows as $row) {
            if (! empty($filters['representative_id']) && (string) ($row['representative_id'] ?? '') !== (string) $filters['representative_id']) {
                continue;
            }
            if (! empty($filters['cash_only']) && ! ($row['cash_only'] ?? false)) {
                continue;
            }
            if ($this->normalizeCurrency($row['currency'] ?? null) !== ($filters['currency'] ?? 'شيكل')) {
                continue;
            }
            if (isset($filters['min_amount']) && $row['total'] < $filters['min_amount']) {
                continue;
            }
            if (isset($filters['max_amount']) && $row['total'] > $filters['max_amount']) {
                continue;
            }
            $selected[] = $row;
        }

        return $selected;
    }

    private function percentage(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : null;
    }

    private function roundAmounts(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_float($value)) {
                $row[$key] = round($value, 2);
            }
        }

        return $row;
    }
}
