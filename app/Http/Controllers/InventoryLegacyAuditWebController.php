<?php

namespace App\Http\Controllers;

use App\Services\LegacyInventoryAuditService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryLegacyAuditWebController extends Controller
{
    public function __construct(private readonly LegacyInventoryAuditService $audit) {}

    public function index(Request $request)
    {
        $token = trim((string) $request->query('token'));
        $expected = (string) env(
            'INVENTORY_AUDIT_TOKEN',
            env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123')
        );
        abort_if($token === '' || $expected === '' || ! hash_equals($expected, $token), 403);

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

        return view('inventory-legacy-audit', [
            'rows' => $rows,
            'summary' => $summary,
            'schema' => $result['schema'],
            'status' => $status,
            'search' => (string) $request->query('search'),
            'token' => $token,
        ]);
    }
}
