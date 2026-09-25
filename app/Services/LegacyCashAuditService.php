<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyCashAuditService
{
    private const STRONG_TIME_WINDOW_SECONDS = 300;

    public const LINKED_AND_ACCOUNTED = 'LINKED_AND_ACCOUNTED';

    public const LINKED_NOT_ACCOUNTED = 'LINKED_NOT_ACCOUNTED';

    public const LINKED_BUT_REVERSED = 'LINKED_BUT_REVERSED';

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
            'linked_but_reversed' => 0,
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
        $timestamp = $this->timestamp($log->created_at ?? null);
        $displayDate = $this->dateTime($log->created_at ?? null) ?: $date;
        $isTransfer = $type === 'transfer' || (($log->from_box_id ?? null) && ($log->to_box_id ?? null));

        if ($isTransfer) {
            return $this->inspectTransfer($log, $boxes, $journals, $description, $note, $type, $reasonCode, $amount, $date, $displayDate);
        }

        $boxId = (int) ($log->box_id ?? 0);
        $direction = $this->logDirection($type, (float) ($log->value ?? 0), $description);
        $currency = (string) ($boxes->get($boxId)->currency ?? '');
        $base = $this->baseRow($log, $displayDate, $boxId ?: null, $currency, $type, $amount, $description, $reasonCode);

        if ($amount <= 0.0001 || ! $boxId || ! $date || ! $direction) {
            return array_merge($base, [
                'matched_source_type' => null,
                'matched_source_id' => null,
                'journal_entry_id' => null,
                'classification' => self::AMBIGUOUS,
                'reason' => 'تعذر تحديد مبلغ أو صندوق أو اتجاه أو تاريخ الحركة بصورة موثوقة.',
            ]);
        }

        $effectDayKey = $this->effectDayKey($boxId, $date, $amount, $direction);
        $effectBaseKey = $this->effectBaseKey($boxId, $amount, $direction);
        $strongSources = $this->sourceTimeMatches($sources, $effectBaseKey, $timestamp, 'active');
        $inactiveStrongSources = $this->sourceTimeMatches($sources, $effectBaseKey, $timestamp, 'inactive');
        $weakSources = $sources['active']['by_day'][$effectDayKey] ?? [];
        $inactiveWeakSources = $sources['inactive']['by_day'][$effectDayKey] ?? [];
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

            return $this->classifyConfirmedSource($base, $direct[0], $effectDayKey, $journals, 'ربط مباشر محفوظ في قاعدة البيانات.');
        }

        $hint = $this->sourceHint($description.' '.$note);
        if ($hint && $hint['id']) {
            $hintIdentityMatches = [];
            foreach ($hint['types'] as $hintType) {
                $identityKey = $this->sourceKey($hintType, $hint['id']);
                foreach (['active', 'inactive'] as $state) {
                    if (isset($sources[$state]['by_identity'][$identityKey])) {
                        $hintIdentityMatches[] = $sources[$state]['by_identity'][$identityKey];
                    }
                }
            }
            $hintIdentityMatches = $this->uniqueSources($hintIdentityMatches);
            if ($hintIdentityMatches === []) {
                return $this->ambiguous($base, 'الوصف يذكر مصدرًا محددًا، لكن المصدر غير موجود ضمن البيانات المتاحة.');
            }
            $validHintMatches = array_values(array_filter($hintIdentityMatches, function (array $source) use ($effectBaseKey, $timestamp) {
                return $source['base_key'] === $effectBaseKey
                    && $timestamp !== null
                    && $this->sourceWithinWindow($source, $timestamp);
            }));
            if (count($validHintMatches) !== 1) {
                return $this->ambiguous($base, 'معرّف المصدر مذكور في الوصف، لكن الصندوق أو المبلغ أو الاتجاه أو التوقيت لا يطابقه بصورة موثوقة.');
            }
            if ($validHintMatches[0]['state'] === 'inactive') {
                return $this->inactiveSourceResult($base, $validHintMatches);
            }

            return $this->classifyConfirmedSource(
                $base,
                $validHintMatches[0],
                $effectDayKey,
                $journals,
                'معرّف المصدر مذكور صراحة وتأكد تطابق الصندوق والمبلغ والاتجاه ضمن نافذة الزمن.'
            );
        }

        $hinted = $hint
            ? $this->filterSourcesByTypes($strongSources, $hint['types'])
            : [];
        if (count($hinted) === 1) {
            return $this->classifyConfirmedSource(
                $base,
                $hinted[0],
                $effectDayKey,
                $journals,
                'تطابق نوع المصدر مع الوصف/الملاحظة، إضافة إلى الصندوق والمبلغ والاتجاه ونافذة ±5 دقائق.'
            );
        }
        if (count($hinted) > 1) {
            return $this->ambiguous($base, 'أكثر من مصدر يطابق الأدلة التشغيلية ضمن نافذة ±5 دقائق: '.$this->sourceList($hinted));
        }
        if ($hint) {
            $hintedInactive = $this->filterSourcesByTypes($inactiveStrongSources, $hint['types']);
            if ($hintedInactive !== []) {
                return $this->inactiveSourceResult($base, $hintedInactive);
            }
            $hintedWeak = $this->filterSourcesByTypes(array_merge($weakSources, $inactiveWeakSources), $hint['types']);
            if ($hintedWeak !== []) {
                return $this->ambiguous($base, 'يوجد مصدر من النوع المشار إليه في اليوم نفسه، لكن لا يوجد تطابق زمني موثوق ضمن ±5 دقائق.');
            }
        }

        $allStrongSources = $this->uniqueSources(array_merge($strongSources, $inactiveStrongSources));
        if (count($allStrongSources) > 1) {
            return $this->ambiguous($base, 'توجد عدة مصادر تشغيلية ضمن نافذة ±5 دقائق: '.$this->sourceList($allStrongSources));
        }
        if ($inactiveStrongSources !== []) {
            return $this->inactiveSourceResult($base, $inactiveStrongSources);
        }

        $journalMatches = $this->journalTimeMatches($journals, $effectBaseKey, $timestamp);
        $journalMatches = $this->uniqueJournals($journalMatches);
        if (count($journalMatches) === 1) {
            $match = $journalMatches[0];

            return array_merge($base, [
                'matched_source_type' => $match['source_type'],
                'matched_source_id' => $match['source_id'],
                'journal_entry_id' => $match['journal_entry_id'],
                'classification' => self::DUPLICATE_ACCOUNTING_RISK,
                'reason' => 'يوجد قيد Cash فعّال مطابق ضمن نافذة ±5 دقائق من مصدر آخر، لكن لا يوجد ربط مباشر يثبت أن BoxLog له؛ إنشاء قيد مستقل سيكرر النقدية على الأرجح.',
            ]);
        }
        if (count($journalMatches) > 1) {
            return $this->ambiguous($base, 'توجد عدة قيود Cash مطابقة ولا يمكن تعيين مصدر واحد بأمان: '.$this->journalList($journalMatches));
        }

        if (count($strongSources) === 1) {
            return $this->ambiguous($base, 'يوجد مصدر تشغيلي واحد ضمن نافذة ±5 دقائق، لكن لا يوجد ربط مباشر أو قيد Cash فعّال يكفي للحكم النهائي: '.$this->sourceList($strongSources));
        }

        $weakEvidence = $this->uniqueSources(array_merge($weakSources, $inactiveWeakSources));
        $weakJournals = $this->uniqueJournals(array_merge(
            $journals['active_by_effect_day'][$effectDayKey] ?? [],
            $journals['reversed_by_effect_day'][$effectDayKey] ?? [],
        ));
        if ($weakEvidence !== [] || $weakJournals !== []) {
            return $this->ambiguous($base, 'توجد مطابقة ضعيفة في اليوم نفسه فقط، ولا يجوز اعتمادها كمصدر مؤكد.');
        }

        return array_merge($base, [
            'matched_source_type' => null,
            'matched_source_id' => null,
            'journal_entry_id' => null,
            'classification' => self::UNLINKED_CASH,
            'reason' => 'لا يوجد مصدر مالي أو قيد Cash معروف يفسر الحركة وفق الأدلة المتاحة.',
        ]);
    }

    private function inspectTransfer(object $log, $boxes, array $journals, string $description, string $note, string $type, string $reasonCode, float $amount, ?string $date, ?string $displayDate): array
    {
        $from = (int) ($log->from_box_id ?? 0);
        $to = (int) ($log->to_box_id ?? 0);
        $currency = (string) ($boxes->get($from)->currency ?? $boxes->get($to)->currency ?? '');
        $base = $this->baseRow($log, $displayDate, $from && $to ? $from.'->'.$to : null, $currency, $type ?: 'transfer', $amount, $description, $reasonCode);
        if (! $from || ! $to || ! $date || $amount <= 0.0001) {
            return $this->ambiguous($base, 'حركة التحويل لا تحتوي صندوقي المصدر والوجهة أو المبلغ أو التاريخ بصورة كاملة.');
        }

        $source = ['source_type' => 'box_transfer', 'source_id' => (int) $log->id];
        $sourceKey = $this->sourceKey('box_transfer', (int) $log->id);
        $sourceJournals = $journals['active_by_source'][$sourceKey] ?? [];
        $fromKey = $this->effectDayKey($from, $date, $amount, -1);
        $toKey = $this->effectDayKey($to, $date, $amount, 1);
        $matchingIds = array_values(array_intersect(
            array_column($journals['active_by_effect_day'][$fromKey] ?? [], 'journal_entry_id'),
            array_column($journals['active_by_effect_day'][$toKey] ?? [], 'journal_entry_id'),
            array_column($sourceJournals, 'journal_entry_id'),
        ));
        $reversedSourceJournals = $journals['reversed_by_source'][$sourceKey] ?? [];
        $reversedIds = array_values(array_intersect(
            array_column($journals['reversed_by_effect_day'][$fromKey] ?? [], 'journal_entry_id'),
            array_column($journals['reversed_by_effect_day'][$toKey] ?? [], 'journal_entry_id'),
            array_column($reversedSourceJournals, 'journal_entry_id'),
        ));

        $classification = $matchingIds
            ? self::LINKED_AND_ACCOUNTED
            : ($reversedIds ? self::LINKED_BUT_REVERSED : self::LINKED_NOT_ACCOUNTED);

        return array_merge($base, [
            'matched_source_type' => $source['source_type'],
            'matched_source_id' => $source['source_id'],
            'journal_entry_id' => $matchingIds[0] ?? ($reversedIds[0] ?? null),
            'classification' => $classification,
            'reason' => match ($classification) {
                self::LINKED_AND_ACCOUNTED => 'التحويل مرتبط مباشرة بقيد فعّال يغطي خروج النقدية ودخولها بين الصندوقين.',
                self::LINKED_BUT_REVERSED => 'التحويل مرتبط مباشرة بقيد كان يغطي طرفي Cash ثم أصبح معكوسًا.',
                default => 'التحويل مصدر مؤكد، لكن لا يوجد قيد فعّال واحد يغطي طرفي Cash بالمبلغ والتاريخ نفسيهما.',
            },
        ]);
    }

    private function classifyConfirmedSource(array $base, array $source, string $effectDayKey, array $journals, string $evidence): array
    {
        $sourceKey = $this->sourceKey($source['source_type'], (int) $source['source_id']);
        $sourceJournals = $journals['active_by_source'][$sourceKey] ?? [];
        $effectJournalIds = array_column($journals['active_by_effect_day'][$effectDayKey] ?? [], 'journal_entry_id');
        $matching = array_values(array_filter($sourceJournals, fn (array $journal) => in_array($journal['journal_entry_id'], $effectJournalIds, true)));
        $reversedSourceJournals = $journals['reversed_by_source'][$sourceKey] ?? [];
        $reversedEffectIds = array_column($journals['reversed_by_effect_day'][$effectDayKey] ?? [], 'journal_entry_id');
        $reversedMatching = array_values(array_filter($reversedSourceJournals, fn (array $journal) => in_array($journal['journal_entry_id'], $reversedEffectIds, true)));

        $classification = $matching
            ? self::LINKED_AND_ACCOUNTED
            : ($reversedMatching
                ? self::LINKED_BUT_REVERSED
                : self::LINKED_NOT_ACCOUNTED);

        return array_merge($base, [
            'matched_source_type' => $source['source_type'],
            'matched_source_id' => $source['source_id'],
            'journal_entry_id' => $matching[0]['journal_entry_id']
                ?? ($reversedMatching[0]['journal_entry_id'] ?? ($sourceJournals[0]['journal_entry_id'] ?? ($reversedSourceJournals[0]['journal_entry_id'] ?? null))),
            'classification' => $classification,
            'reason' => match ($classification) {
                self::LINKED_AND_ACCOUNTED => $evidence.' يوجد قيد Cash فعّال مطابق.',
                self::LINKED_BUT_REVERSED => $evidence.' قيد المصدر الأصلي معكوس أو له قيد عكس منشور، لذلك لا يعد أثرًا محاسبيًا فعالًا.',
                default => $evidence.' لا يوجد قيد Cash فعّال مطابق للصندوق والمبلغ والاتجاه والتاريخ.',
            },
        ]);
    }

    private function cashJournalIndex(): array
    {
        $index = [
            'active_by_effect_day' => [],
            'active_by_effect_base' => [],
            'active_by_source' => [],
            'reversed_by_effect_day' => [],
            'reversed_by_source' => [],
        ];
        if (! $this->hasColumns('accounting_accounts', ['id', 'system_key'])
            || ! $this->hasColumns('accounting_journal_entries', ['id', 'source_type', 'source_id', 'entry_date', 'currency', 'status'])
            || ! $this->hasColumns('accounting_journal_lines', ['journal_entry_id', 'account_id', 'box_id', 'debit', 'credit'])) {
            return $index;
        }

        $hasReversalColumn = Schema::hasColumn('accounting_journal_entries', 'reverses_entry_id');
        $postedReversedIds = $hasReversalColumn
            ? DB::table('accounting_journal_entries')
                ->where('status', 'posted')
                ->whereNotNull('reverses_entry_id')
                ->pluck('reverses_entry_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all()
            : [];
        $optionalColumns = array_values(array_filter(
            ['reverses_entry_id', 'created_at', 'posted_at'],
            fn (string $column) => Schema::hasColumn('accounting_journal_entries', $column)
        ));
        $select = [
            'entries.id as journal_entry_id', 'entries.source_type', 'entries.source_id',
            'entries.entry_date', 'entries.currency', 'entries.status', 'lines.box_id',
            DB::raw('SUM(lines.debit - lines.credit) as cash_effect'),
        ];
        $groupBy = ['entries.id', 'entries.source_type', 'entries.source_id', 'entries.entry_date', 'entries.currency', 'entries.status', 'lines.box_id'];
        foreach ($optionalColumns as $column) {
            $select[] = 'entries.'.$column;
            $groupBy[] = 'entries.'.$column;
        }

        $rows = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('accounts.system_key', 'cash')
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->whereNotNull('lines.box_id')
            ->groupBy($groupBy)
            ->get($select);

        foreach ($rows as $row) {
            $effect = round((float) $row->cash_effect, 4);
            if (abs($effect) <= 0.0001) {
                continue;
            }
            if ($hasReversalColumn && ($row->reverses_entry_id ?? null)) {
                continue;
            }
            $isActive = $row->status === 'posted' && ! isset($postedReversedIds[(int) $row->journal_entry_id]);
            $item = [
                'journal_entry_id' => (int) $row->journal_entry_id,
                'source_type' => (string) $row->source_type,
                'source_id' => (int) $row->source_id,
                'currency' => (string) $row->currency,
                'timestamps' => array_values(array_filter([
                    $this->timestamp($row->created_at ?? null),
                    $this->timestamp($row->posted_at ?? null),
                ], fn ($value) => $value !== null)),
            ];
            $effectDayKey = $this->effectDayKey((int) $row->box_id, $this->date($row->entry_date), abs($effect), $effect > 0 ? 1 : -1);
            $sourceKey = $this->sourceKey($item['source_type'], $item['source_id']);
            if ($isActive) {
                $index['active_by_effect_day'][$effectDayKey][] = $item;
                $index['active_by_effect_base'][$this->effectBaseKey((int) $row->box_id, abs($effect), $effect > 0 ? 1 : -1)][] = $item;
                $index['active_by_source'][$sourceKey][] = $item;
            } else {
                $index['reversed_by_effect_day'][$effectDayKey][] = $item;
                $index['reversed_by_source'][$sourceKey][] = $item;
            }
        }

        return $index;
    }

    private function operationalSourceIndex(): array
    {
        $index = $this->emptySourceIndex();
        $specs = [
            ['table' => 'instant_sales', 'type' => 'instant_sale', 'box' => 'payment_box_id', 'amount' => 'payment_box_value', 'timestamps' => ['created_at'], 'days' => [], 'direction' => 1],
            ['table' => 'profit_sales', 'type' => 'profit_sale', 'box' => 'payment_box_id', 'amount' => 'payment_box_value', 'timestamps' => ['created_at'], 'days' => [], 'direction' => 1],
            ['table' => 'purchase_payments', 'type' => 'purchase_payment', 'box' => 'box_id', 'amount' => 'amount', 'timestamps' => ['created_at'], 'days' => ['paid_at'], 'direction' => -1],
            ['table' => 'maintenance_payments', 'type' => 'maintenance_payment', 'box' => 'box_id', 'amount' => 'amount', 'timestamps' => ['created_at'], 'days' => [], 'direction' => 1],
            ['table' => 'expenses', 'type' => 'expense', 'box' => 'box_id', 'amount' => 'price', 'timestamps' => ['created_at'], 'days' => ['expense_date'], 'direction' => -1],
            ['table' => 'sales_order_settlements', 'type' => 'sales_order_settlement', 'box' => 'box_id', 'amount' => 'cash_amount', 'timestamps' => ['created_at'], 'days' => [], 'direction' => 1],
            ['table' => 'sales_orders', 'type' => 'sales_order', 'box' => 'payment_box_id', 'amount' => 'payment_amount', 'timestamps' => ['financial_posted_at', 'created_at'], 'days' => [], 'direction' => 1],
            ['table' => 'outgoing_checks', 'type' => 'outgoing_check', 'box' => 'box_id', 'amount' => 'total', 'timestamps' => ['updated_at', 'created_at'], 'days' => [], 'direction' => -1],
            ['table' => 'project_expenses', 'type' => 'project_expense', 'box' => 'box_id', 'amount' => 'expenses', 'timestamps' => ['created_at'], 'days' => ['expense_date'], 'direction' => -1],
            ['table' => 'assets', 'type' => 'asset', 'box' => 'box_id', 'amount' => 'price', 'timestamps' => ['created_at'], 'days' => ['acquired_at'], 'direction' => -1],
        ];

        foreach ($specs as $spec) {
            $table = $spec['table'];
            $sourceType = $spec['type'];
            $boxColumn = $spec['box'];
            $amountColumn = $spec['amount'];
            if (! $this->hasColumns($table, ['id', $boxColumn, $amountColumn])) {
                continue;
            }
            $timestampColumns = array_values(array_filter($spec['timestamps'], fn (string $column) => Schema::hasColumn($table, $column)));
            $dayColumns = array_values(array_filter($spec['days'], fn (string $column) => Schema::hasColumn($table, $column)));
            if ($timestampColumns === [] && $dayColumns === []) {
                continue;
            }
            $stateColumns = array_values(array_filter(['status', 'cancelled_at'], fn (string $column) => Schema::hasColumn($table, $column)));
            $select = array_values(array_unique(array_merge(['id', $boxColumn, $amountColumn], $timestampColumns, $dayColumns, $stateColumns)));
            DB::table($table)->select($select)->whereNotNull($boxColumn)->orderBy('id')->chunkById(1000, function ($rows) use (&$index, $table, $sourceType, $boxColumn, $amountColumn, $timestampColumns, $dayColumns, $spec) {
                foreach ($rows as $row) {
                    [$active, $inactiveReason] = $this->sourceState($table, $row);
                    $this->indexSource(
                        $index,
                        $sourceType,
                        (int) $row->id,
                        (int) $row->{$boxColumn},
                        (float) $row->{$amountColumn},
                        (int) $spec['direction'],
                        array_map(fn (string $column) => $row->{$column} ?? null, $timestampColumns),
                        array_map(fn (string $column) => $row->{$column} ?? null, $dayColumns),
                        $active,
                        $inactiveReason,
                    );
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
            ['id', 'box_id', 'amount', 'type', 'transaction_date', 'created_at', 'source', 'source_id', 'archived_at', 'deleted_at'],
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
                $inactiveReasons = [];
                if ($row->archived_at ?? null) {
                    $inactiveReasons[] = 'مؤرشف';
                }
                if ($row->deleted_at ?? null) {
                    $inactiveReasons[] = 'محذوف';
                }
                $this->indexSource(
                    $index,
                    $sourceType,
                    $sourceId,
                    (int) $row->box_id,
                    (float) $row->amount,
                    $direction,
                    [$row->created_at ?? null],
                    [$row->transaction_date ?? null],
                    $inactiveReasons === [],
                    $inactiveReasons ? 'حركة دين '.implode(' و', $inactiveReasons) : null,
                );
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
                $this->indexSource(
                    $index,
                    'incoming_check',
                    (int) $row->id,
                    (int) $row->box_id,
                    (float) $row->total,
                    1,
                    array_map(fn (string $column) => $row->{'link_'.$column} ?? null, $dateColumns),
                    [],
                    true,
                    null,
                );
            });
    }

    private function emptySourceIndex(): array
    {
        return [
            'active' => ['by_base' => [], 'by_day' => [], 'by_identity' => []],
            'inactive' => ['by_base' => [], 'by_day' => [], 'by_identity' => []],
        ];
    }

    private function sourceState(string $table, object $row): array
    {
        if (in_array($table, ['instant_sales', 'profit_sales'], true)) {
            if (($row->cancelled_at ?? null) !== null || in_array(mb_strtolower((string) ($row->status ?? '')), ['cancelled', 'canceled'], true)) {
                return [false, 'المصدر ملغي'];
            }
        }
        if ($table === 'sales_orders' && in_array(mb_strtolower((string) ($row->status ?? '')), ['cancelled', 'canceled'], true)) {
            return [false, 'طلب البيع ملغي'];
        }

        return [true, null];
    }

    private function sourceTimeMatches(array $sources, string $baseKey, ?int $timestamp, string $state): array
    {
        if ($timestamp === null) {
            return [];
        }

        return $this->uniqueSources(array_values(array_filter(
            $sources[$state]['by_base'][$baseKey] ?? [],
            fn (array $source) => $this->sourceWithinWindow($source, $timestamp)
        )));
    }

    private function sourceWithinWindow(array $source, int $timestamp): bool
    {
        foreach ($source['timestamps'] ?? [] as $sourceTimestamp) {
            if (abs((int) $sourceTimestamp - $timestamp) <= self::STRONG_TIME_WINDOW_SECONDS) {
                return true;
            }
        }

        return false;
    }

    private function journalTimeMatches(array $journals, string $baseKey, ?int $timestamp): array
    {
        if ($timestamp === null) {
            return [];
        }

        return array_values(array_filter(
            $journals['active_by_effect_base'][$baseKey] ?? [],
            function (array $journal) use ($timestamp) {
                foreach ($journal['timestamps'] ?? [] as $journalTimestamp) {
                    if (abs((int) $journalTimestamp - $timestamp) <= self::STRONG_TIME_WINDOW_SECONDS) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    private function inactiveSourceResult(array $base, array $sources): array
    {
        $sources = $this->uniqueSources($sources);
        $reason = collect($sources)
            ->pluck('inactive_reason')
            ->filter()
            ->unique()
            ->implode('، ');

        return array_merge($base, [
            'matched_source_type' => count($sources) === 1 ? $sources[0]['source_type'] : null,
            'matched_source_id' => count($sources) === 1 ? $sources[0]['source_id'] : null,
            'journal_entry_id' => null,
            'classification' => self::AMBIGUOUS,
            'reason' => 'الحركة تطابق مصدرًا غير فعال'.($reason ? ' ('.$reason.')' : '').' ولا يمكن اعتباره مصدرًا ماليًا فعالًا.',
        ]);
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

    private function indexSource(
        array &$index,
        string $sourceType,
        int $sourceId,
        int $boxId,
        float $amount,
        int $direction,
        array $timestampValues,
        array $dayValues,
        bool $active,
        ?string $inactiveReason,
    ): void {
        if (! $boxId || ! $sourceId || abs($amount) <= 0.0001) {
            return;
        }

        $timestamps = array_values(array_unique(array_filter(
            array_map(fn ($value) => $this->timestamp($value), $timestampValues),
            fn ($value) => $value !== null
        )));
        $days = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($value) => $this->date($value), $timestampValues),
            array_map(fn ($value) => $this->date($value), $dayValues),
        ))));
        if ($timestamps === [] && $days === []) {
            return;
        }

        $state = $active ? 'active' : 'inactive';
        $baseKey = $this->effectBaseKey($boxId, abs($amount), $direction);
        $item = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'base_key' => $baseKey,
            'timestamps' => $timestamps,
            'days' => $days,
            'state' => $state,
            'inactive_reason' => $inactiveReason,
        ];
        $index[$state]['by_base'][$baseKey][] = $item;
        $index[$state]['by_identity'][$this->sourceKey($sourceType, $sourceId)] = $item;
        foreach ($days as $day) {
            $index[$state]['by_day'][$this->effectDayKey($boxId, $day, abs($amount), $direction)][] = $item;
        }
    }

    private function sourceHint(string $text): ?array
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
            return null;
        }

        preg_match('/#\s*(\d+)/u', $text, $idMatch);

        return ['types' => $hints, 'id' => isset($idMatch[1]) ? (int) $idMatch[1] : null];
    }

    private function filterSourcesByTypes(array $sources, array $types): array
    {
        return $this->uniqueSources(array_values(array_filter(
            $sources,
            fn (array $source) => in_array($source['source_type'], $types, true)
        )));
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

    private function effectBaseKey(int $boxId, float $amount, int $direction): string
    {
        return implode('|', [$boxId, number_format(round(abs($amount), 4), 4, '.', ''), $direction]);
    }

    private function effectDayKey(int $boxId, ?string $date, float $amount, int $direction): string
    {
        return $this->effectBaseKey($boxId, $amount, $direction).'|'.($date ?: '?');
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

    private function timestamp(mixed $value): ?int
    {
        if (! $value) {
            return null;
        }
        if (is_string($value) && ! preg_match('/[T\s]\d{2}:\d{2}/', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
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
            self::LINKED_BUT_REVERSED => 'linked_but_reversed',
            self::DUPLICATE_ACCOUNTING_RISK => 'duplicate_accounting_risk',
            self::UNLINKED_CASH => 'unlinked_cash',
            default => 'ambiguous',
        };
    }
}
