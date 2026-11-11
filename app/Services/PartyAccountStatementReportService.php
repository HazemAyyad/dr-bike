<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class PartyAccountStatementReportService
{
    public function __construct(private DebtAccountStatementService $statements, private DebtLedgerService $ledger) {}

    public function report(array $filters): array
    {
        $customerId = $filters['person_type'] === 'customer' ? (int) $filters['person_id'] : null;
        $sellerId = $filters['person_type'] === 'seller' ? (int) $filters['person_id'] : null;
        $filters['include_opening'] = filter_var($filters['include_opening'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $filters['include_returned_checks'] = filter_var($filters['include_returned_checks'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $transactions = $this->statements->rows($customerId, $sellerId, $filters['currency'], null, $filters['to_date']);
        $opening = 0.0;
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $rows = [];
        $references = $this->documentReferences($transactions);

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction['amount'];
            $debit = $transaction['type'] === 'given' ? $amount : 0.0;
            $credit = $transaction['type'] === 'taken' ? $amount : 0.0;
            if ($transaction['transaction_date'] < $filters['from_date']) {
                $opening += $debit - $credit;
                continue;
            }
            $debitTotal += $debit;
            $creditTotal += $credit;
            $source = $transaction['source'] ?? '';
            $reference = $references[$source.':'.($transaction['source_id'] ?? '')]
                ?? (($transaction['source_id'] ?? null) ?: $transaction['id']);
            $rows[] = [
                'number' => (string) $reference,
                'description' => $this->description($transaction),
                'date' => $transaction['transaction_date'],
                'debit' => round($debit, 2), 'credit' => round($credit, 2),
                'balance' => round(($filters['include_opening'] ? $opening : 0) + $debitTotal - $creditTotal, 2),
                'notes' => $transaction['note'] ?? '',
            ];
        }

        $closing = round(($filters['include_opening'] ? $opening : 0) + $debitTotal - $creditTotal, 2);
        $checks = $filters['include_returned_checks'] ? $this->returnedChecks($filters) : [];

        return [
            'filters' => $filters,
            'person' => $this->ledger->getPersonInfo($customerId, $sellerId),
            'rows' => $rows,
            'summary' => [
                'opening_balance' => $filters['include_opening'] ? round($opening, 2) : 0.0,
                'debit' => round($debitTotal, 2), 'credit' => round($creditTotal, 2),
                'closing_balance' => $closing, 'movement_count' => count($rows),
            ],
            'returned_checks' => $checks,
            'notes' => [
                'الرصيد = الرصيد المدور + المدين − الدائن. الرصيد الموجب لنا والسالب علينا.',
                'الحركات من كشف الحساب في دفتر الديون، وتشمل الفواتير والدفعات والمرتجعات والتسويات المسجلة للطرف.',
                'ملخص الشيكات المرتجعة حسب الحالة الحالية وتاريخ آخر تحديث ضمن الفترة. تأثير الشيكات على الرصيد مأخوذ من حركات دفتر الحساب.',
            ],
        ];
    }

    private function documentReferences(Collection $transactions): array
    {
        $references = [];
        foreach ([
            'instant_sale' => ['instant_sales', 'serial_number'],
            'sales_order' => ['sales_orders', 'serial_number'],
            'sales_return' => ['sales_returns', 'serial_number'],
            'purchase_return' => ['returns', 'number'],
        ] as $source => [$table, $column]) {
            $ids = $transactions->where('source', $source)->pluck('source_id')->filter()->unique();
            if ($ids->isEmpty()) {
                continue;
            }
            foreach (DB::table($table)->whereIn('id', $ids)->get(['id', $column]) as $document) {
                $references[$source.':'.$document->id] = $document->{$column} ?: $document->id;
            }
        }

        return $references;
    }

    private function description(array $transaction): string
    {
        return match ($transaction['source'] ?? '') {
            'sales_return' => 'مردودات المبيعات',
            'purchase_return' => 'مردودات المشتريات',
            'incoming_check' => 'شيك وارد',
            'outgoing_check' => 'شيك صادر',
            'incoming_check_disposal' => 'تظهير شيك وارد',
            default => match ($transaction['entry_kind'] ?? '') {
                'sales_invoice' => 'فاتورة مبيعات',
                'purchase_invoice' => 'فاتورة مشتريات',
                'receipt' => 'سند قبض',
                'payment' => 'سند دفع',
                default => ($transaction['source_label'] ?? null) ?: 'تسوية حساب',
            },
        };
    }

    private function returnedChecks(array $filters): array
    {
        $party = $filters['person_type'] === 'seller' ? 'seller' : 'customer';
        $incoming = DB::table('incoming_checks')->where('status', 'returned')
            ->where('from_'.$party, $filters['person_id']);
        $outgoing = DB::table('outgoing_checks')->where('status', 'returned')
            ->where($party.'_id', $filters['person_id']);
        $result = [];
        foreach (['incoming' => $incoming, 'outgoing' => $outgoing] as $kind => $query) {
            $checks = $query->whereBetween('updated_at', [$filters['from_date'].' 00:00:00', $filters['to_date'].' 23:59:59'])
                ->get(['total', 'currency']);
            $result[$kind] = round($checks->filter(fn ($check) => $this->ledger->normalizeCurrency($check->currency) === $filters['currency'])->sum('total'), 2);
        }

        return $result;
    }
}
