<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\FinancialAccountingReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialAccountingReportController extends Controller
{
    public function report(Request $request, string $type, FinancialAccountingReportService $service)
    {
        $filters = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'currency' => ['required', Rule::in(['شيكل', 'دولار', 'دينار'])],
            'include_opening' => ['nullable', 'boolean'],
            'category_key' => ['nullable', 'string', 'max:100'],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
        ]);

        return response()->json(['status' => 'success', 'data' => $service->report($type, $filters)]);
    }

    public function detail(string $kind, int $id, FinancialAccountingReportService $service)
    {
        return response()->json(['status' => 'success', 'data' => $service->detail($kind, $id)]);
    }
}
