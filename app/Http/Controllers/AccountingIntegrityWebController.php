<?php

namespace App\Http\Controllers;

use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use App\Services\AssetDepreciationWorkflowService;
use App\Services\DebtLedgerBalanceRepairService;
use App\Services\MaintenancePrepaymentSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountingIntegrityWebController extends Controller
{
    public function index(): View
    {
        return $this->page();
    }

    public function inspect(
        Request $request,
        AccountingProjectionRepairService $repair,
        AccountingIntegrityService $integrity,
        MaintenancePrepaymentSyncService $prepayments,
        AssetDepreciationWorkflowService $depreciation,
        DebtLedgerBalanceRepairService $debtBalances,
    ): View {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'depreciation_period' => ['nullable', 'date_format:Y-m'],
        ]);
        [$from, $to, $input] = $this->datesFromValidated($data);
        $this->ensureAccountingTablesReady();
        $period = (string) ($data['depreciation_period'] ?? now()->format('Y-m'));

        return $this->page([
            'mode' => 'preview',
            'repairResult' => $repair->run(true),
            'prepaymentResult' => $prepayments->run(true),
            'depreciationResult' => $depreciation->preview($period),
            'debtBalanceResult' => $debtBalances->run(true),
            'integrityResult' => $integrity->run($from, $to),
            'from' => $input['from'],
            'to' => $input['to'],
            'depreciationPeriod' => $period,
        ]);
    }

    public function repair(
        Request $request,
        AccountingProjectionRepairService $repair,
        AccountingIntegrityService $integrity,
    ): View {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:500'],
            'confirmation' => ['required', 'string', 'in:إصلاح القيود'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'confirmation.in' => 'اكتب عبارة التأكيد كما تظهر تمامًا: إصلاح القيود',
        ]);

        $this->ensureValidRepairToken((string) $data['access_token']);

        $this->ensureAccountingTablesReady();
        [$from, $to, $input] = $this->datesFromValidated($data);

        Log::notice('accounting_projection_repair_started_from_web', [
            'ip' => $request->ip(),
            'from' => $input['from'],
            'to' => $input['to'],
        ]);

        $repairResult = $repair->run(false);
        $remainingResult = $repair->run(true);
        $integrityResult = $integrity->run($from, $to);

        Log::notice('accounting_projection_repair_finished_from_web', [
            'ip' => $request->ip(),
            'summary' => $repairResult['summary'],
            'remaining_summary' => $remainingResult['summary'],
        ]);

        return $this->page([
            'mode' => 'repair',
            'repairResult' => $repairResult,
            'remainingResult' => $remainingResult,
            'integrityResult' => $integrityResult,
            'from' => $input['from'],
            'to' => $input['to'],
        ]);
    }

    public function syncMaintenancePrepayments(
        Request $request,
        MaintenancePrepaymentSyncService $prepayments,
        AccountingIntegrityService $integrity,
    ): View {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:500'],
            'confirmation' => ['required', 'string', 'in:ترحيل عربونات الصيانة'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'confirmation.in' => 'اكتب عبارة التأكيد كما تظهر تمامًا: ترحيل عربونات الصيانة',
        ]);
        $this->ensureValidRepairToken((string) $data['access_token']);
        $this->ensureAccountingTablesReady();
        [$from, $to, $input] = $this->datesFromValidated($data);

        Log::notice('maintenance_prepayment_sync_started_from_web', [
            'ip' => $request->ip(),
        ]);
        $result = $prepayments->run(false);
        $remaining = $prepayments->run(true);
        $integrityResult = $integrity->run($from, $to);
        Log::notice('maintenance_prepayment_sync_finished_from_web', [
            'ip' => $request->ip(),
            'summary' => $result['summary'],
            'remaining_summary' => $remaining['summary'],
        ]);

        return $this->page([
            'mode' => 'prepayment_sync',
            'prepaymentResult' => $result,
            'remainingPrepaymentResult' => $remaining,
            'integrityResult' => $integrityResult,
            'from' => $input['from'],
            'to' => $input['to'],
        ]);
    }

    public function runAssetDepreciation(
        Request $request,
        AssetDepreciationWorkflowService $depreciation,
        AccountingIntegrityService $integrity,
    ): View {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:500'],
            'confirmation' => ['required', 'string', 'in:تنفيذ إهلاك الأصول'],
            'depreciation_period' => ['required', 'date_format:Y-m'],
        ], [
            'confirmation.in' => 'اكتب عبارة التأكيد كما تظهر تمامًا: تنفيذ إهلاك الأصول',
        ]);
        $this->ensureValidRepairToken((string) $data['access_token']);
        $this->ensureAccountingTablesReady();
        $period = (string) $data['depreciation_period'];
        if ($period !== now()->format('Y-m')) {
            throw ValidationException::withMessages([
                'depreciation_period' => 'التنفيذ من الويب مسموح للشهر الحالي فقط؛ الفترات الأخرى تحتاج مراجعة محاسبية وأمرًا يدويًا.',
            ]);
        }

        Log::notice('asset_depreciation_started_from_web', [
            'ip' => $request->ip(),
            'period' => $period,
        ]);
        $result = $depreciation->run($period, $request->user()?->id);
        $periodDate = Carbon::createFromFormat('Y-m-d', $period.'-01');
        $integrityResult = $integrity->run($periodDate->copy()->startOfMonth(), $periodDate->copy()->endOfMonth());
        Log::notice('asset_depreciation_finished_from_web', [
            'ip' => $request->ip(),
            'period' => $period,
            'execution' => $result['execution'],
        ]);

        return $this->page([
            'mode' => 'depreciation_run',
            'depreciationResult' => $result['after'],
            'depreciationExecution' => $result,
            'integrityResult' => $integrityResult,
            'from' => $periodDate->copy()->startOfMonth()->toDateString(),
            'to' => $periodDate->copy()->endOfMonth()->toDateString(),
            'depreciationPeriod' => $period,
        ]);
    }

    public function repairDebtLedgerBalances(
        Request $request,
        DebtLedgerBalanceRepairService $debtBalances,
        AccountingIntegrityService $integrity,
    ): View {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:500'],
            'confirmation' => ['required', 'string', 'in:إصلاح أرصدة دفتر الديون'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'confirmation.in' => 'اكتب عبارة التأكيد كما تظهر تمامًا: إصلاح أرصدة دفتر الديون',
        ]);
        $this->ensureValidRepairToken((string) $data['access_token']);
        $this->ensureAccountingTablesReady();
        [$from, $to, $input] = $this->datesFromValidated($data);

        Log::notice('debt_ledger_balance_repair_started_from_web', [
            'ip' => $request->ip(),
        ]);
        $result = $debtBalances->run(false);
        $integrityResult = $integrity->run($from, $to);
        Log::notice('debt_ledger_balance_repair_finished_from_web', [
            'ip' => $request->ip(),
            'summary' => $result['summary'],
        ]);

        return $this->page([
            'mode' => 'debt_balance_repair',
            'debtBalanceResult' => $result,
            'integrityResult' => $integrityResult,
            'from' => $input['from'],
            'to' => $input['to'],
        ]);
    }

    /** @param array<string, mixed> $data @return array{0:?Carbon,1:?Carbon,2:array{from:?string,to:?string}} */
    private function datesFromValidated(array $data): array
    {
        $fromInput = isset($data['from']) ? (string) $data['from'] : null;
        $toInput = isset($data['to']) ? (string) $data['to'] : null;
        if ($fromInput && $toInput && $toInput < $fromInput) {
            throw ValidationException::withMessages([
                'to' => 'تاريخ النهاية يجب أن يساوي تاريخ البداية أو يأتي بعده.',
            ]);
        }

        return [
            $fromInput ? Carbon::createFromFormat('Y-m-d', $fromInput)->startOfDay() : null,
            $toInput ? Carbon::createFromFormat('Y-m-d', $toInput)->endOfDay() : null,
            ['from' => $fromInput, 'to' => $toInput],
        ];
    }

    private function ensureAccountingTablesReady(): void
    {
        $status = $this->migrationStatus();
        if (! $status['ready']) {
            throw ValidationException::withMessages([
                'migration' => 'جداول أو حسابات المحاسبة غير مكتملة. شغّل migration يدويًا أولًا، ولم يتم تغيير أي بيانات.',
            ]);
        }
    }

    private function ensureValidRepairToken(string $provided): void
    {
        $expected = (string) config('accounting_integrity.web_repair_token', '');
        if ($expected === '') {
            throw ValidationException::withMessages([
                'access_token' => 'الإصلاح من الويب معطّل حتى يتم ضبط ACCOUNTING_REPAIR_WEB_TOKEN على السيرفر.',
            ]);
        }
        if (! hash_equals($expected, $provided)) {
            throw ValidationException::withMessages([
                'access_token' => 'رمز مركز الأمان غير صحيح، ولم يتم تنفيذ أي إصلاح.',
            ]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function page(array $overrides = []): View
    {
        return view('accounting-integrity', array_merge([
            'mode' => null,
            'repairResult' => null,
            'remainingResult' => null,
            'prepaymentResult' => null,
            'remainingPrepaymentResult' => null,
            'depreciationResult' => null,
            'depreciationExecution' => null,
            'debtBalanceResult' => null,
            'integrityResult' => null,
            'from' => null,
            'to' => null,
            'depreciationPeriod' => now()->format('Y-m'),
            'migrationStatus' => $this->migrationStatus(),
            'webRepairEnabled' => (string) config('accounting_integrity.web_repair_token', '') !== '',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function migrationStatus(): array
    {
        $tables = collect([
            'accounting_accounts',
            'accounting_journal_entries',
            'accounting_projection_failures',
            'inventory_cost_layers',
            'inventory_cost_allocations',
        ])->mapWithKeys(fn (string $table) => [$table => Schema::hasTable($table)])->all();

        $serviceRevenue = $tables['accounting_accounts']
            && Schema::hasColumn('accounting_accounts', 'system_key')
            && DB::table('accounting_accounts')->where('system_key', 'service_revenue')->exists();
        $maintenancePaymentStage = Schema::hasTable('maintenance_payments')
            && Schema::hasColumn('maintenance_payments', 'payment_stage');
        $assetDepreciationFields = Schema::hasTable('assets')
            && Schema::hasColumn('assets', 'months_number')
            && Schema::hasColumn('assets', 'depreciation_price')
            && Schema::hasTable('asset_logs')
            && Schema::hasColumn('asset_logs', 'depreciation_period')
            && Schema::hasColumn('asset_logs', 'depreciation_amount');
        $debtLedgerSafety = Schema::hasTable('debt_transactions')
            && Schema::hasTable('boxes')
            && Schema::hasTable('box_logs')
            && Schema::hasColumn('box_logs', 'reason_code')
            && Schema::hasColumn('box_logs', 'created_by');
        $cashDifferenceAccounts = $tables['accounting_accounts']
            && DB::table('accounting_accounts')
                ->whereIn('system_key', ['cash_overage_income', 'cash_shortage_expense'])
                ->distinct()->count('system_key') === 2;

        return [
            'ready' => ! in_array(false, $tables, true)
                && $serviceRevenue
                && $maintenancePaymentStage
                && $assetDepreciationFields
                && $debtLedgerSafety
                && $cashDifferenceAccounts,
            'tables' => $tables,
            'service_revenue' => $serviceRevenue,
            'maintenance_payment_stage' => $maintenancePaymentStage,
            'asset_depreciation_fields' => $assetDepreciationFields,
            'debt_ledger_safety' => $debtLedgerSafety,
            'cash_difference_accounts' => $cashDifferenceAccounts,
        ];
    }
}
