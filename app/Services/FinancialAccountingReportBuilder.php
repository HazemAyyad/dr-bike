<?php

namespace App\Services;

class FinancialAccountingReportBuilder
{
    public function sectionForAccount(string $type, int $id, array $accounts): string
    {
        if ($type === 'revenue') {
            return 'revenue';
        }
        if ($type === 'contra_revenue') {
            return 'sales_returns';
        }
        $seen = [];
        while ($id && isset($accounts[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            if (($accounts[$id]['system_key'] ?? null) === 'cost_of_goods_sold') {
                return 'cost';
            }
            $id = (int) ($accounts[$id]['parent_id'] ?? 0);
        }

        return 'expense';
    }

    /** Ledger projections own their source; reversals remain separate dated movements. */
    public function merge(iterable $ledger, iterable $legacy, array $coveredSources): array
    {
        $rows = [];
        foreach ($ledger as $row) {
            $rows[$row['key']] = $row;
        }
        foreach ($legacy as $row) {
            $covered = false;
            foreach ($row['covered_by'] ?? [] as $source) {
                $covered = $covered || isset($coveredSources[$source]);
            }
            if (! $covered) {
                $rows[$row['key']] ??= $row;
            }
        }

        return array_values($rows);
    }

    public function expenses(array $events, array $filters): array
    {
        $events = array_filter($events, fn ($row) => $row['section'] === 'expense'
            && $row['currency'] === $filters['currency']
            && (empty($filters['category_key']) || $row['category_key'] === $filters['category_key'])
            && (empty($filters['box_id']) || (int) ($row['box_id'] ?? 0) === (int) $filters['box_id']));
        usort($events, fn ($a, $b) => strcmp($a['date'], $b['date'])
            ?: (($a['order'] ?? 0) <=> ($b['order'] ?? 0)) ?: strcmp($a['key'], $b['key']));
        $opening = 0.0;
        $debit = 0.0;
        $credit = 0.0;
        $rows = [];
        foreach ($events as $row) {
            if ($row['date'] < $filters['from_date']) {
                if ($filters['include_opening']) {
                    $opening += $row['debit'] - $row['credit'];
                }
            } elseif ($row['date'] <= $filters['to_date']) {
                $debit += $row['debit'];
                $credit += $row['credit'];
                $row['balance'] = round($opening + $debit - $credit, 2);
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'summary' => [
            'opening_balance' => round($opening, 2), 'debit' => round($debit, 2),
            'credit' => round($credit, 2), 'period_total' => round($debit - $credit, 2),
            'closing_balance' => round($opening + $debit - $credit, 2), 'movement_count' => count($rows),
        ]];
    }

    public function income(array $events, array $filters): array
    {
        $groups = [];
        $totals = array_fill_keys(['revenue', 'sales_returns', 'cost', 'expense'], 0.0);
        $missingCost = 0;
        foreach ($events as $row) {
            if ($row['currency'] !== $filters['currency'] || $row['date'] < $filters['from_date'] || $row['date'] > $filters['to_date']) {
                continue;
            }
            $section = $row['section'];
            $key = $section.':'.$row['category_key'];
            $groups[$key] ??= [
                'key' => $row['category_key'], 'section' => $section, 'label' => $row['category_name'],
                'debit' => 0.0, 'credit' => 0.0, 'complete' => true,
            ];
            $groups[$key]['debit'] += $row['debit'];
            $groups[$key]['credit'] += $row['credit'];
            $totals[$section] += $section === 'revenue' ? $row['credit'] - $row['debit'] : $row['debit'] - $row['credit'];
            if (($row['cost_complete'] ?? true) === false) {
                $missingCost++;
                $groups[$key]['complete'] = false;
            }
        }
        $rows = [];
        foreach (['revenue', 'sales_returns', 'cost', 'expense'] as $section) {
            $sectionRows = array_filter($groups, fn ($row) => $row['section'] === $section);
            uasort($sectionRows, fn ($a, $b) => strcmp($a['label'], $b['label']));
            foreach ($sectionRows as $row) {
                $row['debit'] = round($row['debit'], 2);
                $row['credit'] = round($row['credit'], 2);
                $row['balance'] = $row['complete'] ? round($section === 'revenue' ? $row['credit'] - $row['debit'] : $row['debit'] - $row['credit'], 2) : null;
                $rows[] = $row;
            }
        }
        $netRevenue = $totals['revenue'] - $totals['sales_returns'];

        return ['rows' => $rows, 'summary' => [
            'revenue' => round($totals['revenue'], 2), 'sales_returns' => round($totals['sales_returns'], 2),
            'net_revenue' => round($netRevenue, 2), 'cost_known' => round($totals['cost'], 2),
            'cost' => $missingCost ? null : round($totals['cost'], 2),
            'gross_profit' => $missingCost ? null : round($netRevenue - $totals['cost'], 2),
            'expenses' => round($totals['expense'], 2),
            'net_profit' => $missingCost ? null : round($netRevenue - $totals['cost'] - $totals['expense'], 2),
            'missing_cost_count' => $missingCost,
        ]];
    }
}
