<?php

namespace App\Http\Controllers;

use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
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
    ): View {
        [$from, $to, $input] = $this->validatedDates($request);
        $this->ensureAccountingTablesReady();

        return $this->page([
            'mode' => 'preview',
            'repairResult' => $repair->run(true),
            'integrityResult' => $integrity->run($from, $to),
            'from' => $input['from'],
            'to' => $input['to'],
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

        $expected = (string) config('accounting_integrity.web_repair_token', '');
        if ($expected === '') {
            throw ValidationException::withMessages([
                'access_token' => 'الإصلاح من الويب معطّل حتى يتم ضبط ACCOUNTING_REPAIR_WEB_TOKEN على السيرفر.',
            ]);
        }
        if (! hash_equals($expected, (string) $data['access_token'])) {
            throw ValidationException::withMessages([
                'access_token' => 'رمز مركز الأمان غير صحيح، ولم يتم تنفيذ أي إصلاح.',
            ]);
        }

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

    /** @return array{0:?Carbon,1:?Carbon,2:array{from:?string,to:?string}} */
    private function validatedDates(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->datesFromValidated($data);
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

    /** @param array<string, mixed> $overrides */
    private function page(array $overrides = []): View
    {
        return view('accounting-integrity', array_merge([
            'mode' => null,
            'repairResult' => null,
            'remainingResult' => null,
            'integrityResult' => null,
            'from' => null,
            'to' => null,
            'migrationStatus' => $this->migrationStatus(),
            'webRepairEnabled' => (string) config('accounting_integrity.web_repair_token', '') !== '',
        ], $overrides));
    }

    /** @return array{ready:bool,tables:array<string,bool>,service_revenue:bool} */
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

        return [
            'ready' => ! in_array(false, $tables, true) && $serviceRevenue,
            'tables' => $tables,
            'service_revenue' => $serviceRevenue,
        ];
    }
}
