<?php

namespace App\Http\Controllers;

use App\Services\LegacyInventoryAuditService;
use App\Services\LegacyInventoryReviewWorkbookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryLegacyAuditWebController extends Controller
{
    public function __construct(
        private readonly LegacyInventoryAuditService $audit,
        private readonly LegacyInventoryReviewWorkbookService $workbook,
    ) {}

    public function index(Request $request): View
    {
        $token = $this->authorizedToken($request);
        $result = $this->audit->run(false);
        $allRows = collect($result['rows']);
        $status = trim((string) $request->query('status'));
        $search = mb_strtolower(trim((string) $request->query('search')));
        $filtered = $allRows
            ->when($status !== '', fn ($rows) => $rows->where('status', $status))
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search) {
                $haystack = mb_strtolower(implode(' ', [
                    $row['product_id'], $row['product_code'], $row['product_name'], $row['variant_label'],
                ]));

                return str_contains($haystack, $search);
            }))->values();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $rows = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $summary = [
            'identities' => $allRows->count(),
            'covered' => $allRows->where('status', 'covered')->count(),
            'ready' => $allRows->where('status', 'ready')->count(),
            'review' => $allRows->whereIn('status', ['review', 'over_covered'])->count(),
            'physical_quantity' => $allRows->sum('physical_quantity'),
            'costed_quantity' => $allRows->sum('costed_quantity'),
            'missing_quantity' => $allRows->sum('missing_quantity'),
            'suggested_values' => $allRows
                ->filter(fn (array $row) => $row['suggested_value'] !== null)
                ->groupBy('suggested_currency')
                ->map(fn ($rows) => $rows->sum(fn (array $row) => (float) $row['suggested_value']))
                ->all(),
        ];
        $sourceBreakdown = $allRows->groupBy('cost_source')->map(fn ($sourceRows) => [
            'identities' => $sourceRows->count(),
            'missing_quantity' => $sourceRows->sum('missing_quantity'),
            'suggested_value' => $sourceRows->sum(fn (array $row) => (float) ($row['suggested_value'] ?? 0)),
        ])->all();
        $reviewBreakdown = [
            'main' => $allRows->whereIn('status', ['review', 'over_covered'])->whereNull('size_color_id')->count(),
            'variants' => $allRows->whereIn('status', ['review', 'over_covered'])->whereNotNull('size_color_id')->count(),
            'variants_with_reference' => $allRows->whereIn('status', ['review', 'over_covered'])
                ->whereNotNull('size_color_id')
                ->filter(fn (array $row) => $row['reference_unit_cost'] !== null)
                ->count(),
        ];
        $productReferenceRows = $allRows->filter(fn (array $row) => $row['status'] === 'review'
            && $row['reason'] === 'reliable_opening_unit_cost_not_found'
            && $row['size_color_id'] !== null
            && $row['reference_unit_cost'] !== null
            && $row['reference_source'] === 'purchase_products.product_level_reference');
        $productReferenceBatch = [
            'identities' => $productReferenceRows->count(),
            'products' => $productReferenceRows->pluck('product_id')->unique()->count(),
            'quantity' => $productReferenceRows->sum('missing_quantity'),
            'value' => $productReferenceRows->sum(fn (array $row) => (float) $row['missing_quantity'] * (float) $row['reference_unit_cost']),
        ];
        $manualReviewGroups = $this->audit->manualReviewGroups($result['rows']);
        $manualReviewSummary = [
            'groups' => count($manualReviewGroups),
            'identities' => collect($manualReviewGroups)->sum(fn (array $group) => count($group['identities'])),
            'quantity' => collect($manualReviewGroups)->sum('missing_quantity'),
        ];
        $resolveIdentity = trim((string) $request->query('resolve'));
        $resolveRow = $resolveIdentity === ''
            ? null
            : $allRows->firstWhere('identity_key', $resolveIdentity);

        return view('inventory-legacy-audit', [
            'rows' => $rows,
            'summary' => $summary,
            'sourceBreakdown' => $sourceBreakdown,
            'reviewBreakdown' => $reviewBreakdown,
            'productReferenceBatch' => $productReferenceBatch,
            'manualReviewSummary' => $manualReviewSummary,
            'reviewImportPreview' => $request->session()->get('legacy_inventory_review_preview'),
            'resolveRow' => $resolveRow,
            'schema' => $result['schema'],
            'status' => $status,
            'search' => (string) $request->query('search'),
            'token' => $token,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizedToken($request);
        $rows = collect($this->audit->run(false)['rows']);

        return response()->streamDownload(function () use ($rows) {
            echo "\xEF\xBB\xBF";
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'identity_key', 'product_id', 'product_code', 'product_name', 'variant',
                'physical_quantity', 'costed_quantity', 'missing_quantity', 'suggested_unit_cost',
                'currency', 'suggested_value', 'cost_source', 'cost_source_id', 'status', 'reason',
                'product_level_reference_cost', 'reference_currency', 'reference_source_id',
            ]);
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['identity_key'], $row['product_id'], $row['product_code'], $row['product_name'],
                    $row['variant_label'], $row['physical_quantity'], $row['costed_quantity'],
                    $row['missing_quantity'], $row['suggested_unit_cost'], $row['suggested_currency'],
                    $row['suggested_value'], $row['cost_source'], $row['cost_source_id'], $row['status'], $row['reason'],
                    $row['reference_unit_cost'], $row['reference_currency'], $row['reference_source_id'],
                ]);
            }
            fclose($stream);
        }, 'legacy-inventory-audit-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportReviewWorkbook(Request $request): StreamedResponse
    {
        $this->authorizedToken($request);
        $spreadsheet = $this->workbook->build($this->audit->manualReviewGroups());

        return response()->streamDownload(function () use ($spreadsheet) {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'legacy-inventory-cost-review-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function previewReviewWorkbook(Request $request): RedirectResponse
    {
        $token = $this->authorizedToken($request);
        $request->session()->forget('legacy_inventory_review_preview');
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);
        $file = $validated['file'];
        $preview = $this->workbook->preview($file->getRealPath(), $this->audit->manualReviewGroups());
        $preview['preview_token'] = Str::random(48);
        $preview['created_at'] = now()->timestamp;
        $preview['file_hash'] = hash_file('sha256', $file->getRealPath());
        $preview['file_name'] = $file->getClientOriginalName();
        $request->session()->put('legacy_inventory_review_preview', $preview);

        return redirect()->route('inventory.legacy-audit', ['token' => $token, 'status' => 'review']);
    }

    public function applyReviewWorkbook(Request $request): RedirectResponse
    {
        $token = $this->authorizedToken($request);
        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'operator' => ['required', 'string', 'max:120'],
            'backup_confirmed' => ['accepted'],
            'confirmation' => ['required', 'in:IMPORT'],
        ]);
        $preview = $request->session()->get('legacy_inventory_review_preview');
        if (! is_array($preview)
            || ! hash_equals((string) ($preview['preview_token'] ?? ''), $validated['preview_token'])
            || (int) ($preview['created_at'] ?? 0) < now()->subHours(2)->timestamp) {
            $request->session()->forget('legacy_inventory_review_preview');
            throw ValidationException::withMessages(['file' => ['انتهت صلاحية المعاينة. ارفع ملف المراجعة من جديد.']]);
        }
        if (($preview['errors'] ?? []) !== [] || ($preview['rows'] ?? []) === []) {
            throw ValidationException::withMessages(['file' => ['لا يمكن تنفيذ ملف يحتوي أخطاء أو لا يحتوي صفوفاً معتمدة.']]);
        }

        $result = $this->audit->applyReviewedWorkbook(
            $preview['rows'],
            $validated['operator'],
            (string) $preview['file_hash'],
        );
        $request->session()->forget('legacy_inventory_review_preview');

        return redirect()->route('inventory.legacy-audit', ['token' => $token, 'status' => 'review'])
            ->with('review_import_result', $result);
    }

    public function applyReadyBatch(Request $request): RedirectResponse
    {
        $token = $this->authorizedToken($request);
        $validated = $request->validate([
            'operator' => ['required', 'string', 'max:120'],
            'batch_size' => ['required', 'integer', 'in:10,25,50'],
            'backup_confirmed' => ['accepted'],
            'confirmation' => ['required', 'in:BACKFILL'],
        ]);
        $result = $this->audit->applyReadyBatch((int) $validated['batch_size'], $validated['operator']);

        return redirect()->route('inventory.legacy-audit', ['token' => $token, 'status' => 'ready'])
            ->with('backfill_result', $result);
    }

    public function applyReviewedCost(Request $request): RedirectResponse
    {
        $token = $this->authorizedToken($request);
        $validated = $request->validate([
            'identity_key' => ['required', 'string', 'max:191'],
            'unit_cost' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'max:20'],
            'operator' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'backup_confirmed' => ['accepted'],
            'confirmation' => ['required', 'in:REVIEW'],
        ]);
        $outcome = $this->audit->applyReviewedCost(
            identityKey: $validated['identity_key'],
            unitCost: (float) $validated['unit_cost'],
            currency: $validated['currency'],
            operator: $validated['operator'],
            reason: $validated['reason'],
            notes: $validated['notes'] ?? null,
        );

        $message = match ($outcome) {
            'created' => 'تم إنشاء تغطية التكلفة المعتمدة دون تغيير كمية المخزون.',
            'over_covered' => 'لم تُنشأ طبقة: كمية التكلفة الحالية أكبر من المخزون وتحتاج تصحيحاً منفصلاً.',
            'review_required' => 'لم تُنشأ طبقة: ما زالت الهوية تحتاج مراجعة.',
            default => 'لم تُنشأ طبقة جديدة لأن الهوية أصبحت مغطاة أو سبق تنفيذها.',
        };

        return redirect()->route('inventory.legacy-audit', ['token' => $token, 'status' => 'review'])
            ->with('review_result', $message);
    }

    public function applyProductReferenceBatch(Request $request): RedirectResponse
    {
        $token = $this->authorizedToken($request);
        $validated = $request->validate([
            'operator' => ['required', 'string', 'max:120'],
            'batch_size' => ['required', 'integer', 'in:10,25,50'],
            'backup_confirmed' => ['accepted'],
            'confirmation' => ['required', 'in:VARIANTS'],
        ]);
        $result = $this->audit->applyProductReferenceBatch((int) $validated['batch_size'], $validated['operator']);

        return redirect()->route('inventory.legacy-audit', ['token' => $token, 'status' => 'review'])
            ->with('variant_reference_result', $result);
    }

    private function authorizedToken(Request $request): string
    {
        $token = trim((string) ($request->query('token') ?: $request->input('token')));
        $expected = (string) env(
            'INVENTORY_AUDIT_TOKEN',
            env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123')
        );
        abort_if($token === '' || $expected === '' || ! hash_equals($expected, $token), 403);

        return $token;
    }
}
