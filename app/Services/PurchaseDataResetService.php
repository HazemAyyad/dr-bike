<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\PurchaseInvoicePurgeBackup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseDataResetService
{
    // Only purchase source tables. Accounting and stock audit movements are retained.
    public const TABLES = [
        'bills', 'bill_items', 'purchase_receipts', 'purchase_receipt_items',
        'purchase_amanat_stocks', 'purchase_issue_resolutions', 'purchase_payments',
        'purchase_payment_allocations', 'returns', 'purchase_returns',
        'purchase_return_settlements', 'purchase_attachments',
        'purchase_price_histories', 'purchase_products', 'purchase_activity_logs',
    ];

    public function __construct(private readonly PurchaseInvoicePurgeService $purge) {}

    public function missingTables(): array
    {
        return array_values(array_filter(
            [...self::TABLES, 'purchase_invoice_purge_backups', 'debt_transactions', 'accounting_journal_entries'],
            fn ($table) => ! Schema::hasTable($table),
        ));
    }

    public function preview(): array
    {
        $this->ensureReady();
        $tables = $this->snapshot();

        return [
            'fingerprint' => $this->fingerprint($tables),
            'counts' => array_map('count', $tables),
            'totals' => collect($tables['bills'])->groupBy('currency')->map(fn ($rows) => [
                'total' => $rows->sum('final_total'),
                'paid' => $rows->sum('paid_amount'),
            ])->all(),
        ];
    }

    public function reset(string $fingerprint, User $actor, string $reason): array
    {
        if ($actor->type !== 'admin' || $actor->is_blocked) {
            abort(403);
        }

        return $this->performReset($fingerprint, $actor, $reason);
    }

    /** The web controller authorizes the security-center session before calling this. */
    public function resetFromSecurityCenter(string $fingerprint, ?User $actor, string $reason): array
    {
        abort_unless(session()->get('security_center_authenticated', false), 403);

        return $this->performReset($fingerprint, $actor, $reason);
    }

    private function performReset(string $fingerprint, ?User $actor, string $reason): array
    {
        $this->ensureReady();

        return DB::transaction(function () use ($fingerprint, $actor, $reason) {
            $tables = $this->snapshot(true);
            if (! hash_equals($fingerprint, $this->fingerprint($tables))) {
                throw ValidationException::withMessages(['preview' => 'تغيرت بيانات المشتريات بعد المعاينة. حدّث المعاينة ثم أعد التأكيد.']);
            }
            if (array_sum(array_map('count', $tables)) === 0) {
                throw ValidationException::withMessages(['preview' => 'لا توجد بيانات مشتريات لتصفيرها.']);
            }

            $reference = (string) Str::uuid();
            $backup = PurchaseInvoicePurgeBackup::query()->create([
                'reference' => $reference,
                // Zero denotes the reset batch rather than an individual invoice; no FK exists here.
                'bill_id' => 0,
                'bill_reference' => 'RESET-'.$reference,
                'workflow_status' => 'purchase_data_reset',
                'reason' => $reason,
                'payload' => ['version' => 1, 'scope' => 'all_purchase_data', 'tables' => $tables],
                'created_by' => $actor?->id,
            ]);

            // Remove independent allocations/returns first while their target bills still exist.
            $detached = $this->purge->purgeUnlinkedRecords($backup, $actor);
            $invoiceResults = [];
            foreach (array_reverse($tables['bills']) as $row) {
                $invoiceResults[] = $this->purge->purge(Bill::query()->findOrFail($row['id']), $actor, $reason, refreshPriceCache: false);
            }
            $legacyDebts = $this->purge->purgeRemainingPurchaseDebts(array_column($tables['purchase_debt_transactions'], 'id'));

            // Delete only IDs captured by this preview, including legacy price caches and orphan rows.
            foreach (array_reverse(self::TABLES) as $table) {
                $ids = array_column($tables[$table], 'id');
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->delete();
                }
            }
            foreach (self::TABLES as $table) {
                if (DB::table($table)->exists()) {
                    throw ValidationException::withMessages(['preview' => 'ظهرت بيانات مشتريات جديدة أثناء التنفيذ. لم يتم التصفير؛ أعد المعاينة.']);
                }
            }

            $files = collect($tables['purchase_attachments'])->filter(fn ($row) => trim((string) ($row['path'] ?? '')) !== '')
                ->map(fn ($row) => ['disk' => $row['disk'] ?: 'public', 'path' => $row['path']])->all();
            $this->purge->deleteFilesAfterCommit($files);
            $result = [
                'backup_reference' => $backup->reference,
                'counts' => array_map('count', $tables),
                'unlinked' => $detached,
                'legacy_debts' => $legacyDebts,
                'invoices' => $invoiceResults,
            ];
            $backup->update(['result_summary' => $result]);

            return $result;
        }, 1);
    }

    private function ensureReady(): void
    {
        if ($this->missingTables() !== []) {
            throw ValidationException::withMessages(['schema' => 'جداول المشتريات والتدقيق غير مكتملة. طبّق ترحيلات السيرفر أولًا.']);
        }
    }

    private function snapshot(bool $lock = false): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $tables[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }
        $debtIds = collect($tables['purchase_payments'])->pluck('debt_transaction_id')
            ->merge(collect($tables['returns'])->pluck('debt_transaction_id'))
            ->merge(collect($tables['purchase_return_settlements'])->pluck('debt_transaction_id'))
            ->filter()->unique();
        $query = DB::table('debt_transactions')->whereNull('archived_at')->whereNull('deleted_at')
            ->where(function ($query) use ($debtIds) {
                $query->whereIn('id', $debtIds)->orWhereIn('source', PurchaseInvoicePurgeService::DEBT_SOURCES);
            })->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $tables['purchase_debt_transactions'] = $query->get()->map(fn ($row) => (array) $row)->all();

        return $tables;
    }

    private function fingerprint(array $tables): string
    {
        return hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
