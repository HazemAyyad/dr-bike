<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    private const EPSILON = 0.0001;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function post(
        string $sourceKey,
        string $sourceType,
        int $sourceId,
        Carbon|string $entryDate,
        string $currency,
        string $description,
        array $lines,
        array $metadata = [],
        ?int $userId = null,
    ): AccountingJournalEntry {
        $date = $entryDate instanceof Carbon ? $entryDate->copy() : Carbon::parse($entryDate);
        $currency = $this->normalizeCurrency($currency);
        $normalizedLines = $this->validateAndNormalizeLines($lines);

        return DB::transaction(function () use (
            $sourceKey,
            $sourceType,
            $sourceId,
            $date,
            $currency,
            $description,
            $normalizedLines,
            $metadata,
            $userId,
        ) {
            $period = $this->periodForDate($date, lock: true);
            $this->assertPeriodOpen($period, $date);

            $entry = AccountingJournalEntry::query()
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();

            if ($entry?->status === AccountingJournalEntry::STATUS_REVERSED) {
                $version = AccountingJournalEntry::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->count() + 1;
                $sourceKey .= ':v'.$version;
                $entry = null;
            }

            $payload = [
                'source_key' => $sourceKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'accounting_period_id' => $period?->id,
                'entry_date' => $date->toDateString(),
                'currency' => $currency,
                'status' => AccountingJournalEntry::STATUS_POSTED,
                'description' => $description,
                'metadata' => $metadata,
                'posted_at' => now(),
                'reversed_at' => null,
                'created_by' => $userId,
            ];

            if ($entry) {
                $entryPeriod = $entry->period()->lockForUpdate()->first();
                $this->assertPeriodOpen($entryPeriod, $entry->entry_date);
                $entry->update($payload);
                $entry->lines()->delete();
            } else {
                $entry = AccountingJournalEntry::create(array_merge($payload, [
                    'entry_number' => 'JRN-'.strtoupper((string) Str::ulid()),
                ]));
            }

            $entry->lines()->createMany($normalizedLines);

            return $entry->fresh(['lines.account', 'period']);
        }, 3);
    }

    public function reverse(
        string $sourceType,
        int $sourceId,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس القيد المرتبط بالمصدر',
        ?int $userId = null,
    ): ?AccountingJournalEntry {
        $original = AccountingJournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first();

        return $original
            ? $this->reverseEntry($original, $entryDate, $reason, $userId)
            : null;
    }

    public function reverseBySourceKey(
        string $sourceKey,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس القيد المرتبط بالمصدر',
        ?int $userId = null,
    ): ?AccountingJournalEntry {
        $original = AccountingJournalEntry::query()
            ->where('source_key', $sourceKey)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->first();

        return $original
            ? $this->reverseEntry($original, $entryDate, $reason, $userId)
            : null;
    }

    public function reverseEntryById(
        int $entryId,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس القيد المحدد',
        ?int $userId = null,
    ): ?AccountingJournalEntry {
        $original = AccountingJournalEntry::query()
            ->whereKey($entryId)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->first();

        return $original
            ? $this->reverseEntry($original, $entryDate, $reason, $userId)
            : null;
    }

    public function reverseBySourceKeyPrefix(
        string $sourceKeyPrefix,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس القيد المرتبط بالمصدر',
        ?int $userId = null,
    ): ?AccountingJournalEntry {
        $original = AccountingJournalEntry::query()
            ->where('source_key', 'like', $sourceKeyPrefix.'%')
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first();

        return $original
            ? $this->reverseEntry($original, $entryDate, $reason, $userId)
            : null;
    }

    /** @return array<int, AccountingJournalEntry> */
    public function reverseOtherBySourceKeyPrefix(
        string $sourceKeyPrefix,
        string $currentSourceKey,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس القيد السابق المرتبط بالمصدر',
        ?int $userId = null,
    ): array {
        $reversals = [];

        while ($original = AccountingJournalEntry::query()
            ->where('source_key', 'like', $sourceKeyPrefix.'%')
            ->where('source_key', '!=', $currentSourceKey)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first()) {
            $reversal = $this->reverseEntry($original, $entryDate, $reason, $userId);
            if (! $reversal) {
                break;
            }
            $reversals[] = $reversal;
        }

        return $reversals;
    }

    /** @return array<int, AccountingJournalEntry> */
    public function reverseAll(
        string $sourceType,
        int $sourceId,
        Carbon|string|null $entryDate = null,
        string $reason = 'عكس جميع قيود المصدر',
        ?int $userId = null,
    ): array {
        $reversals = [];
        while ($reversal = $this->reverse($sourceType, $sourceId, $entryDate, $reason, $userId)) {
            $reversals[] = $reversal;
        }

        return $reversals;
    }

    private function reverseEntry(
        AccountingJournalEntry $original,
        Carbon|string|null $entryDate,
        string $reason,
        ?int $userId,
    ): ?AccountingJournalEntry {
        return DB::transaction(function () use ($original, $entryDate, $reason, $userId) {
            $lockedOriginal = AccountingJournalEntry::query()
                ->with('lines')
                ->whereKey($original->id)
                ->where('status', AccountingJournalEntry::STATUS_POSTED)
                ->whereNull('reverses_entry_id')
                ->lockForUpdate()
                ->first();

            if (! $lockedOriginal) {
                return null;
            }

            $original = $lockedOriginal;

            $date = $entryDate ? Carbon::parse($entryDate) : now();
            $period = $this->periodForDate($date, lock: true);
            $this->assertPeriodOpen($period, $date);

            $reversal = AccountingJournalEntry::create([
                'entry_number' => 'JRN-'.strtoupper((string) Str::ulid()),
                'source_key' => $original->source_key.':reversal:'.$original->id,
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'accounting_period_id' => $period?->id,
                'entry_date' => $date->toDateString(),
                'currency' => $original->currency,
                'status' => AccountingJournalEntry::STATUS_POSTED,
                'description' => $reason,
                'metadata' => ['reversed_source_type' => $original->source_type, 'reversed_source_id' => $original->source_id],
                'reverses_entry_id' => $original->id,
                'posted_at' => now(),
                'created_by' => $userId,
            ]);

            $reversal->lines()->createMany($original->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'description' => $reason,
                'customer_id' => $line->customer_id,
                'seller_id' => $line->seller_id,
                'delivery_company_id' => $line->delivery_company_id,
                'box_id' => $line->box_id,
                'product_id' => $line->product_id,
                'due_date' => $line->due_date,
                'metadata' => $line->metadata,
            ])->all());

            $original->update([
                'status' => AccountingJournalEntry::STATUS_REVERSED,
                'reversed_at' => now(),
            ]);

            return $reversal->fresh(['lines.account', 'period']);
        }, 3);
    }

    public function accountId(string $systemKey): int
    {
        $id = AccountingAccount::query()
            ->where('system_key', $systemKey)
            ->where('is_active', true)
            ->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                'account' => ['الحساب النظامي غير موجود أو غير فعال: '.$systemKey],
            ]);
        }

        return (int) $id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function validateAndNormalizeLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['القيد المحاسبي يجب أن يحتوي على سطرين على الأقل.'],
            ]);
        }

        $normalized = collect($lines)->map(function (array $line) {
            $debit = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);

            if ($debit < 0 || $credit < 0 || ($debit > self::EPSILON && $credit > self::EPSILON)) {
                throw ValidationException::withMessages([
                    'lines' => ['كل سطر يجب أن يكون مدينًا أو دائنًا بقيمة موجبة، وليس كليهما.'],
                ]);
            }
            if ($debit <= self::EPSILON && $credit <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'lines' => ['لا يمكن ترحيل سطر محاسبي بقيمة صفر.'],
                ]);
            }

            $accountId = isset($line['account_id'])
                ? (int) $line['account_id']
                : $this->accountId((string) ($line['account_key'] ?? ''));

            return [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
                'customer_id' => $line['customer_id'] ?? null,
                'seller_id' => $line['seller_id'] ?? null,
                'delivery_company_id' => $line['delivery_company_id'] ?? null,
                'box_id' => $line['box_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'due_date' => $line['due_date'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ];
        })->values();

        $debit = round((float) $normalized->sum('debit'), 4);
        $credit = round((float) $normalized->sum('credit'), 4);
        if (abs($debit - $credit) > self::EPSILON) {
            throw ValidationException::withMessages([
                'lines' => ["القيد غير متوازن: المدين {$debit} والدائن {$credit}."],
            ]);
        }

        return $normalized->all();
    }

    public function normalizeCurrency(?string $currency): string
    {
        return match (mb_strtolower(trim((string) $currency))) {
            'دولار', 'dollar', 'usd', '$' => 'دولار',
            'دينار', 'dinar', 'jod' => 'دينار',
            default => 'شيكل',
        };
    }

    private function periodForDate(Carbon $date, bool $lock = false): ?AccountingPeriod
    {
        $query = AccountingPeriod::query()
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertPeriodOpen(?AccountingPeriod $period, Carbon|string $date): void
    {
        if (! $period && AccountingPeriod::query()->exists()) {
            $label = $date instanceof Carbon ? $date->toDateString() : (string) $date;
            throw ValidationException::withMessages([
                'entry_date' => ['لا توجد فترة محاسبية معرفة للتاريخ '.$label.'.'],
            ]);
        }
        if ($period?->isClosed()) {
            $label = $date instanceof Carbon ? $date->toDateString() : (string) $date;
            throw ValidationException::withMessages([
                'entry_date' => ['الفترة المحاسبية مغلقة ولا يمكن تعديل قيود تاريخ '.$label.'.'],
            ]);
        }
    }
}
