<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyCashAuditService
{
    public const LINKED_AND_ACCOUNTED = 'LINKED_AND_ACCOUNTED';

    public const LINKED_NOT_ACCOUNTED = 'LINKED_NOT_ACCOUNTED';

    public const DUPLICATE_ACCOUNTING_RISK = 'DUPLICATE_ACCOUNTING_RISK';

    public const UNLINKED_CASH = 'UNLINKED_CASH';

    public const AMBIGUOUS = 'AMBIGUOUS';

    /**
     * Inspect historical cash movements. This service deliberately contains no
     * insert, update, delete, projection, repair, or model save operation.
     */
    public function audit(): array
    {
        $summary = [
            'total_rows' => 0,
            'linked_and_accounted' => 0,
            'linked_not_accounted' => 0,
            'duplicate_accounting_risk' => 0,
            'unlinked_cash' => 0,
            'ambiguous' => 0,
        ];

        if (! Schema::hasTable('box_logs')) {
            return ['rows' => [], 'summary' => $summary];
        }

        $columns = array_flip(Schema::getColumnListing('box_logs'));
        $boxes = Schema::hasTable('boxes')
            ? DB::table('boxes')
                ->get(Schema::hasColumn('boxes', 'currency') ? ['id', 'currency'] : ['id'])
                ->each(fn (object $box) => $box->currency ??= null)
                ->keyBy('id')
            : collect();
        $journals = $this->cashJournalIndex();
        $sources = $this->operationalSourceIndex();
        $directLinks = $this->directLinkIndex();
        $rows = [];

        DB::table('box_logs')->orderBy('id')->chunkById(500, function ($logs) use (
            &$rows,
            &$summary,
            $columns,
            $boxes,
            $journals,
            $sources,
            $directLinks,
        ) {
            foreach ($logs as $log) {
                if ($this->logAmount($log, $columns) <= 0.0001) {
                    continue;
                }
                $row = $this->inspectLog($log, $columns, $boxes, $journals, $sources, $directLinks);
                $rows[] = $row;
                $summary['total_rows']++;
                $summary[$this->summaryKey($row['classification'])]++;
            }
        });

        return ['rows' => $rows, 'summary' => $summary];
    }

    private function inspectLog(
        object $log,
        array $columns,
        $boxes,
        array $journals,
        array $sources,
        array $directLinks,
    ): array {
        $description = trim((string) ($log->description ?? ''));
        $note = isset($columns['note']) ? trim((string) ($log->note ?? '')) : '';
        $type = isset($columns['type']) ? trim((string) ($log->type ?? '')) : '';
        $reasonCode = isset($columns['reason_code']) ? trim((string) ($log->reason_code ?? '')) : '';
        $amount = $this->logAmount($log, $columns);
        $date = $this->date($log->created_at ?? null);
        $isTransfer = $type === 'transfer' || (($log->from_box_id ?? null) && ($log->to_box_id ?? null));

        if ($isTransfer) {
            return $this->inspectTransfer($log, $boxes, $journals, $description, $note, $type, $reasonCode, $amount, $date);
        }

        $boxId = (int) ($log->box_id ?? 0);
        $direction = $this->logDirection($type, (float) ($log->value ?? 0), $description);
        $currency = (string) ($boxes->get($boxId)->currency ?? '');
        $base = $this->baseRow($log, $date, $boxId ?: null, $currency, $type, $amount, $description, $reasonCode);

        if ($amount <= 0.0001 || ! $boxId || ! $date || ! $direction) {
            return array_merge($base, [
                'matched_source_type' => null,
                'matched_source_id' => null,
                'journal_entry_id' => null,
                'classification' => self::AMBIGUOUS,
                'reason' => 'تعذر تحديد مبلغ أو صندوق أو اتجاه أو تاريخ الحركة بصورة موثوقة.',
            ]);
        }

        $effectKey = $this->effectKey($boxId, $date, $amount, $direction);
        $journalMatches = $journals['by_effect'][$effectKey] ?? [];
        $sourceMatches = $sources[$effectKey] ?? [];
        $direct = $directLinks[(int) $log->id] ?? [];

        if ($this->isDirectBoxAccounting($description, $reasonCode)) {
            $direct[] = ['source_type' => 'box_adjustment', 'source_id' => (int) $log->id, 'evidence' => 'box_log_identity'];
        }
        $direct = $this->uniqueSources($direct);
        if (count($direct) > 1) {
            return $this->ambiguous($base, 'يرتبط BoxLog بأكثر من مصدر مباشر: '.$this->sourceList($direct));
        }
        if (count($direct) === 1) {
            if ($direct[0]['source_type'] === 'salary_payment_batch') {
                return array_merge($base, [
                    'matched_source_type' => 'salary_payment_batch',
                    'matched_source_id' => $direct[0]['source_id'],
                    'journal_entry_id' => null,
                    'classification' => self::AMBIGUOUS,
                    'reason' => 'الربط بدفعة الرواتب مؤكد، لكن BoxLog إجمالي وقد تغطيه عدة قيود رواتب؛ يلزم فحص تجميعي قبل الحكم.',
                ]);
            }

            return $this->classifyConfirmedSource($base, $direct[0], $effectKey, $journals, 'ربط مباشر محفوظ في قاعدة البيانات.');
        }

        $hinted = $this->hintedSources($description.' '.$note, $sourceMatches);
        if (count($hinted) === 1) {
            return $this->classifyConfirmedSource(
                $base,
                $hinted[0],
                $effectKey,
                $journals,
                'تطابق نوع المصدر مع الوصف/الملاحظة، إضافة إلى الصندوق والمبلغ والاتجاه والتاريخ.'
            );
        }
        if (count($hinted) > 1) {
            return $this->ambiguous($base, 'أكثر من مصدر يطابق الأدلة التشغيلية: '.$this->sourceList($hinted));
        }

        $journalMatches = $this->uniqueJournals($journalMatches);
        if (count($journalMatches) === 1) {
            $match = $journalMatches[0];

            return array_merge($base, [
                'matched_source_type' => $match['source_type'],
                'matched_source_id' => $match['source_id'],
                'journal_entry_id' => $match['journal_entry_id'],
                'classification' => self::DUPLICATE_ACCOUNTING_RISK,
                'reason' => 'يوجد قيد Cash مطابق تمامًا من مصدر آخر، لكن لا يوجد ربط مباشر يثبت أن BoxLog له؛ إنشاء قيد مستقل سيكرر النقدية على الأرجح.',
            ]);
        }
        if (count($journalMatches) > 1) {
            return $this->ambiguous($base, 'توجد عدة قيود Cash مطابقة ولا يمكن تعيين مصدر واحد بأمان: '.$this->journalList($journalMatches));
        }

        $sourceMatches = $this->uniqueSources($sourceMatches);
        if ($sourceMatches !== []) {
            return $this->ambiguous($base, 'توجد مصادر تشغيلية محتملة بنفس الصندوق والمبلغ والاتجاه والتاريخ دون دليل ربط كافٍ: '.$this->sourceList($sourceMatches));
        }

        return array_merge($base, [
            'matched_source_type' => null,
            'matched_source_id' => null,
            'journal_entry_id' => null,
            'classification' => self::UNLINKED_CASH,
            'reason' => 'لا يوجد مصدر مالي أو قيد Cash معروف يفسر الحركة وفق الأدلة المتاحة.',
        ]);
    }

    private function inspectTransfer(object $log, $boxes, array $journals, string $description, string $note, string $type, string $reasonCode, float $amount, ?string $date): array
    {
        $from = (int) ($log->from_box_id ?? 0);
        $to = (int) ($log->to_box_id ?? 0);
        $currency = (string) ($boxes->get($from)->currency ?? $boxes->get($to)->currency ?? '');
        $base = $this->baseRow($log, $date, $from && $to ? $from.'->'.$to : null, $currency, $type ?: 'transfer', $amount, $description, $reasonCode);
        if (! $from || ! $to || ! $date || $amount <= 0.0001) {
            return $this->ambiguous($base, 'حركة التحويل لا تحتوي صندوقي المصدر والوجهة أو المبلغ أو التاريخ بصورة كاملة.');
        }

        $source = ['source_type' => 'box_transfer', 'source_id' => (int) $log->id];
        $sourceJournals = $journals['by_source'][$this->sourceKey('box_transfer', (int) $log->id)] ?? [];
        $fromKey = $this->effectKey($from, $date, $amount, -1);
        $toKey = $this->effectKey($to, $date, $amount, 1);
        $matchingIds = array_values(array_intersect(
            array_column($journals['by_effect'][$fromKey] ?? [], 'journal_entry_id'),
            array_column($journals['by_effect'][$toKey] ?? [], 'journal_entry_id'),
            array_column($sourceJournals, 'journal_entry_id'),
        ));

        return array_merge($base, [
            'matched_source_type' => $source['source_type'],
            'matched_source_id' => $source['source_id'],
            'journal_entry_id' => $matchingIds[0] ?? null,
            'classification' => $matchingIds ? self::LINKED_AND_ACCOUNTED : self::LINKED_NOT_ACCOUNTED,
            'reason' => $matchingIds
                ? 'التحويل مرتبط مباشرة بقيد يغطي خروج النقدية ودخولها بين الصندوقين.'
                : 'التحويل مصدر مؤكد، لكن لا يوجد قيد واحد يغطي طرفي Cash بالمبلغ والتاريخ نفسيهما.',
        ]);
    }

    private function classifyConfirmedSource(array $base, array $source, string $effectKey, array $journals, string $evidence): array
    {
        $sourceKey = $this->sourceKey($source['source_type'], (int) $source['source_id']);
        $sourceJournals = $journals['by_source'][$sourceKey] ?? [];
        $effectJournalIds = array_column($journals['by_effect'][$effectKey] ?? [], 'journal_entry_id');
        $matching = array_values(array_filter($sourceJournals, fn (array $journal) => in_array($journal['journal_entry_id'], $effectJournalIds, true)));

        return array_merge($base, [
            'matched_source_type' => $source['source_type'],
            'matched_source_id' => $source['source_id'],
            'journal_entry_id' => $matching[0]['journal_entry_id'] ?? ($sourceJournals[0]['journal_entry_id'] ?? null),
            'classification' => $matching ? self::LINKED_AND_ACCOUNTED : self::LINKED_NOT_ACCOUNTED,
            'reason' => $matching
                ? $evidence.' يوجد قيد Cash مطابق.'
                : $evidence.' لا يوجد قيد Cash مطابق للصندوق والمبلغ والاتجاه والتاريخ.',
        ]);
    }

    private function cashJournalIndex(): array
    {
        $index = ['by_effect' => [], 'by_source' => []];
        if (! $this->hasColumns('accounting_accounts', ['id', 'system_key'])
            || ! $this->hasColumns('accounting_journal_entries', ['id', 'source_type', 'source_id', 'entry_date', 'currency', 'status'])
            || ! $this->hasColumns('accounting_journal_lines', ['journal_entry_id', 'account_id', 'box_id', 'debit', 'credit'])) {
            return $index;
        }

        $rows = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('accounts.system_key', 'cash')
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->whereNotNull('lines.box_id')
            ->groupBy('entries.id', 'entries.source_type', 'entries.source_id', 'entries.entry_date', 'entries.currency', 'lines.box_id')
            ->get([
                'entries.id as journal_entry_id', 'entries.source_type', 'entries.source_id',
                'entries.entry_date', 'entries.currency', 'lines.box_id',
                DB::raw('SUM(lines.debit - lines.credit) as cash_effect'),
            ]);

        foreach ($rows as $row) {
            $effect = round((float) $row->cash_effect, 4);
            if (abs($effect) <= 0.0001) {
                continue;
            }
            $item = [
                'journal_entry_id' => (int) $row->journal_entry_id,
                'source_type' => (string) $row->source_type,
                'source_id' => (int) $row->source_id,
                'currency' => (string) $row->currency,
            ];
            $effectKey = $this->effectKey((int) $row->box_id, $this->date($row->entry_date), abs($effect), $effect > 0 ? 1 : -1);
            $index['by_effect'][$effectKey][] = $item;
            $index['by_source'][$this->sourceKey($item['source_type'], $item['source_id'])][] = $item;
        }

        return $index;
    }

    private function operationalSourceIndex(): array
    {
        $index = [];
        $specs = [
            ['instant_sales', 'instant_sale', 'payment_box_id', 'payment_box_value', ['created_at'], 1],
            ['profit_sales', 'profit_sale', 'payment_box_id', 'payment_box_value', ['created_at'], 1],
            ['purchase_payments', 'purchase_payment', 'box_id', 'amount', ['paid_at', 'created_at'], -1],
            ['maintenance_payments', 'maintenance_payment', 'box_id', 'amount', ['created_at'], 1],
            ['expenses', 'expense', 'box_id', 'price', ['expense_date', 'created_at'], -1],
            ['sales_order_settlements', 'sales_order_settlement', 'box_id', 'cash_amount', ['created_at'], 1],
            ['sales_orders', 'sales_order', 'payment_box_id', 'payment_amount', ['financial_posted_at', 'created_at'], 1],
            ['outgoing_checks', 'outgoing_check', 'box_id', 'total', ['updated_at', 'created_at'], -1],
            ['project_expenses', 'project_expense', 'box_id', 'expenses', ['expense_date', 'created_at'], -1],
            ['assets', 'asset', 'box_id', 'price', ['acquired_at', 'created_at'], -1],
        ];

        foreach ($specs as [$table, $sourceType, $boxColumn, $amountColumn, $dateColumns, $direction]) {
            if (! $this->hasColumns($table, ['id', $boxColumn, $amountColumn])) {
                continue;
            }
            $availableDates = array_values(array_filter($dateColumns, fn (string $column) => Schema::hasColumn($table, $column)));
            if ($availableDates === []) {
                continue;
            }
            $select = array_values(array_unique(array_merge(['id', $boxColumn, $amountColumn], $availableDates)));
            DB::table($table)->select($select)->whereNotNull($boxColumn)->orderBy('id')->chunkById(1000, function ($rows) use (&$index, $sourceType, $boxColumn, $amountColumn, $availableDates, $direction) {
                foreach ($rows as $row) {
                    foreach ($availableDates as $dateColumn) {
                        $this->indexSource($index, $row, $sourceType, (int) $row->{$boxColumn}, (float) $row->{$amountColumn}, $row->{$dateColumn} ?? null, $direction);
                    }
                }
            });
        }

        $this->indexDebtTransactions($index);
        $this->indexIncomingChecks($index);

        return $index;
    }

    private function indexDebtTransactions(array &$index): void
    {
        if (! $this->hasColumns('debt_transactions', ['id', 'box_id', 'amount', 'type', 'transaction_date'])) {
            return;
        }
        $select = array_values(array_filter(
            ['id', 'box_id', 'amount', 'type', 'transaction_date', 'created_at', 'source', 'source_id'],
            fn (string $column) => Schema::hasColumn('debt_transactions', $column)
        ));
        DB::table('debt_transactions')->select($select)->whereNotNull('box_id')->orderBy('id')->chunkById(1000, function ($rows) use (&$index) {
            foreach ($rows as $row) {
                $sourceType = (string) ($row->source ?? '');
                $sourceId = (int) ($row->source_id ?? 0);
                if (in_array($sourceType, ['purchase_payment', 'purchase_initial_payment', 'purchase_account_payment'], true) && $sourceId) {
                    $sourceType = 'purchase_payment';
                } elseif (! $sourceId || in_array($sourceType, ['', 'manual'], true)) {
                    $sourceType = 'debt_transaction';
                    $sourceId = (int) $row->id;
                }
                $direction = $row->type === 'taken' ? 1 : -1;
                $this->indexSource($index, $row, $sourceType, (int) $row->box_id, (float) $row->amount, $row->transaction_date, $direction, $sourceId);
                if (isset($row->created_at)) {
                    $this->indexSource($index, $row, $sourceType, (int) $row->box_id, (float) $row->amount, $row->created_at, $direction, $sourceId);
                }
            }
        });
    }

    private function indexIncomingChecks(array &$index): void
    {
        if (! $this->hasColumns('incoming_checks', ['id', 'total']) || ! $this->hasColumns('incoming_check_boxes', ['incoming_check_id', 'box_id'])) {
            return;
        }
        $dateColumns = array_values(array_filter(['updated_at', 'created_at'], fn (string $column) => Schema::hasColumn('incoming_check_boxes', $column)));
        if ($dateColumns === []) {
            return;
        }
        $select = ['checks.id', 'checks.total', 'links.box_id'];
        foreach ($dateColumns as $column) {
            $select[] = 'links.'.$column.' as link_'.$column;
        }
        DB::table('incoming_check_boxes as links')
            ->join('incoming_checks as checks', 'checks.id', '=', 'links.incoming_check_id')
            ->whereNotNull('links.box_id')
            ->orderBy('links.id')
            ->get($select)
            ->each(function ($row) use (&$index, $dateColumns) {
                foreach ($dateColumns as $column) {
                    $this->indexSource($index, $row, 'incoming_check', (int) $row->box_id, (float) $row->total, $row->{'link_'.$column} ?? null, 1);
                }
            });
    }

    private function directLinkIndex(): array
    {
        $index = [];
        $specs = [
            ['purchase_payments', 'box_log_id', 'purchase_payment'],
            ['employee_orders', 'box_log_id', 'employee_advance'],
            ['salary_payment_batches', 'box_log_id', 'salary_payment_batch'],
        ];
        foreach ($specs as [$table, $column, $sourceType]) {
            if (! $this->hasColumns($table, ['id', $column])) {
                continue;
            }
            DB::table($table)->whereNotNull($column)->get(['id', $column])->each(function ($row) use (&$index, $column, $sourceType) {
                $index[(int) $row->{$column}][] = [
                    'source_type' => $sourceType,
                    'source_id' => (int) $row->id,
                    'evidence' => 'direct_foreign_key',
                ];
            });
        }

        return $index;
    }

    private function indexSource(array &$index, object $row, string $sourceType, int $boxId, float $amount, mixed $date, int $direction, ?int $sourceId = null): void
    {
        $date = $this->date($date);
        if (! $boxId || abs($amount) <= 0.0001 || ! $date) {
            return;
        }
        $key = $this->effectKey($boxId, $date, abs($amount), $direction);
        $index[$key][] = [
            'source_type' => $sourceType,
            'source_id' => $sourceId ?: (int) $row->id,
            'evidence' => 'operational_fields',
        ];
    }

    private function hintedSources(string $text, array $sources): array
    {
        $hints = match (true) {
            str_contains($text, 'بيع فوري') => ['instant_sale'],
            str_contains($text, 'بيع ربحي') => ['profit_sale'],
            str_contains($text, 'شيك وارد') => ['incoming_check'],
            str_contains($text, 'شيك صادر') => ['outgoing_check'],
            str_contains($text, 'مصروف مشروع') => ['project_expense'],
            str_contains($text, 'شراء أصل') => ['asset'],
            str_contains($text, 'سلفة موظف') => ['employee_advance'],
            str_contains($text, 'دفتر الديون'), str_contains($text, 'دين من الشخص'), str_contains($text, 'اعطاء دين') => ['debt_transaction', 'purchase_payment'],
            str_contains($text, 'مصروف') => ['expense'],
            str_contains($text, 'طلبية') => ['sales_order', 'sales_order_settlement'],
            str_contains($text, 'صيانة') => ['maintenance_payment', 'instant_sale'],
            default => [],
        };
        if ($hints === []) {
            return [];
        }

        $matches = array_values(array_filter($sources, fn (array $source) => in_array($source['source_type'], $hints, true)));
        if (preg_match('/#\s*(\d+)/u', $text, $idMatch)) {
            $id = (int) $idMatch[1];
            $byId = array_values(array_filter($matches, fn (array $source) => (int) $source['source_id'] === $id));
            if ($byId !== []) {
                $matches = $byId;
            }
        }

        return $this->uniqueSources($matches);
    }

    private function isDirectBoxAccounting(string $description, string $reasonCode): bool
    {
        return in_array($reasonCode, ['owner_contribution', 'owner_withdrawal', 'cash_overage', 'cash_shortage', 'accounting_correction'], true)
            || ($reasonCode === '' && in_array($description, ['تم اضافة رصيد للصندوق', 'تم سحب رصيد من الصندوق'], true));
    }

    private function logAmount(object $log, array $columns): float
    {
        if (isset($columns['value']) && abs((float) ($log->value ?? 0)) > 0.0001) {
            return round(abs((float) $log->value), 4);
        }

        return isset($columns['transfered_balance']) ? round(abs((float) ($log->transfered_balance ?? 0)), 4) : 0.0;
    }

    private function logDirection(string $type, float $value, string $description): ?int
    {
        if (in_array($type, ['add', 'plus', 'income', 'receive'], true)) {
            return 1;
        }
        if (in_array($type, ['minus', 'payment', 'deduct', 'withdraw'], true)) {
            return -1;
        }
        if ($value < -0.0001) {
            return -1;
        }
        if (str_starts_with($description, 'قبض') || str_starts_with($description, 'إضافة') || str_starts_with($description, 'تم اضافة') || str_starts_with($description, 'تم اخذ') || str_starts_with($description, 'إلغاء سلفة') || str_starts_with($description, 'عكس شراء')) {
            return 1;
        }
        if (str_starts_with($description, 'سحب') || str_starts_with($description, 'دفع') || str_starts_with($description, 'صرف') || str_starts_with($description, 'شراء أصل') || str_starts_with($description, 'مصروف') || str_starts_with($description, 'تم سحب') || str_starts_with($description, 'تم اعطاء')) {
            return -1;
        }
        if ($value > 0.0001) {
            return 1;
        }

        return null;
    }

    private function baseRow(object $log, ?string $date, mixed $boxId, string $currency, string $type, float $amount, string $description, string $reasonCode): array
    {
        return [
            'box_log_id' => (int) $log->id,
            'date' => $date,
            'box_id' => $boxId,
            'currency' => $currency ?: null,
            'type' => $type ?: null,
            'amount' => $amount,
            'description' => $description ?: null,
            'reason_code' => $reasonCode ?: null,
        ];
    }

    private function ambiguous(array $base, string $reason): array
    {
        return array_merge($base, [
            'matched_source_type' => null,
            'matched_source_id' => null,
            'journal_entry_id' => null,
            'classification' => self::AMBIGUOUS,
            'reason' => $reason,
        ]);
    }

    private function uniqueSources(array $sources): array
    {
        $unique = [];
        foreach ($sources as $source) {
            $unique[$this->sourceKey((string) $source['source_type'], (int) $source['source_id'])] = $source;
        }

        return array_values($unique);
    }

    private function uniqueJournals(array $journals): array
    {
        $unique = [];
        foreach ($journals as $journal) {
            $unique[(int) $journal['journal_entry_id']] = $journal;
        }

        return array_values($unique);
    }

    private function effectKey(int $boxId, ?string $date, float $amount, int $direction): string
    {
        return implode('|', [$boxId, $date ?: '?', number_format(round(abs($amount), 4), 4, '.', ''), $direction]);
    }

    private function sourceKey(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function sourceList(array $sources): string
    {
        return implode(', ', array_map(fn (array $source) => $source['source_type'].'#'.$source['source_id'], $this->uniqueSources($sources)));
    }

    private function journalList(array $journals): string
    {
        return implode(', ', array_map(fn (array $journal) => '#'.$journal['journal_entry_id'].' '.$journal['source_type'].'#'.$journal['source_id'], $this->uniqueJournals($journals)));
    }

    private function summaryKey(string $classification): string
    {
        return match ($classification) {
            self::LINKED_AND_ACCOUNTED => 'linked_and_accounted',
            self::LINKED_NOT_ACCOUNTED => 'linked_not_accounted',
            self::DUPLICATE_ACCOUNTING_RISK => 'duplicate_accounting_risk',
            self::UNLINKED_CASH => 'unlinked_cash',
            default => 'ambiguous',
        };
    }
}
