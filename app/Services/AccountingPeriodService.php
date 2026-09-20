<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function __construct(private AccountingReportService $reports) {}

    public function create(string $name, string $startsAt, string $endsAt): AccountingPeriod
    {
        $from = Carbon::parse($startsAt)->startOfDay();
        $to = Carbon::parse($endsAt)->startOfDay();
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['ends_at' => ['نهاية الفترة يجب أن تكون بعد بدايتها.']]);
        }

        $overlap = AccountingPeriod::query()
            ->whereDate('starts_at', '<=', $to->toDateString())
            ->whereDate('ends_at', '>=', $from->toDateString())
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => ['الفترة تتداخل مع فترة محاسبية موجودة.']]);
        }

        return AccountingPeriod::create([
            'name' => trim($name),
            'starts_at' => $from->toDateString(),
            'ends_at' => $to->toDateString(),
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
    }

    public function close(AccountingPeriod $period, ?int $userId, ?string $note = null): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $userId, $note) {
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($locked->isClosed()) {
                return $locked;
            }

            $unbalanced = DB::table('accounting_journal_entries as entries')
                ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
                ->whereBetween('entries.entry_date', [$locked->starts_at->toDateString(), $locked->ends_at->toDateString()])
                ->where('entries.status', 'posted')
                ->groupBy('entries.id')
                ->havingRaw('ABS(SUM(lines.debit) - SUM(lines.credit)) > 0.0001')
                ->exists();
            if ($unbalanced) {
                throw ValidationException::withMessages(['period' => ['لا يمكن إغلاق فترة تحتوي قيودًا غير متوازنة.']]);
            }
            $quality = $this->reports->quality();
            if (! $quality['complete']) {
                throw ValidationException::withMessages([
                    'period' => ['لا يمكن إغلاق الفترة قبل معالجة فشل الترحيل وحساب التسوية وفروق المطابقة المحاسبية.'],
                ]);
            }

            $locked->update([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $userId,
                'closing_note' => $note,
            ]);

            return $locked->fresh();
        }, 3);
    }
}
