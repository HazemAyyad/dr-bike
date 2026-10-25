<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SalesAccountingReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesAccountingReportController extends Controller
{
    public function __invoke(Request $request, SalesAccountingReportService $reports)
    {
        $filters = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'group_by' => ['nullable', Rule::in(['customer', 'month', 'day'])],
            'include_returns' => ['nullable', 'boolean'],
            'person_key' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(['sales_desc', 'sales_asc', 'label'])],
            'currency' => ['nullable', Rule::in(['شيكل', 'دولار', 'دينار'])],
            'cash_only' => ['nullable', 'boolean'],
            'include_other_currencies' => ['nullable', 'boolean'],
            'representative_id' => ['nullable', 'integer', 'exists:users,id'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0', Rule::when($request->filled('min_amount'), ['gte:min_amount'])],
        ]);

        return response()->json(['status' => 'success', 'data' => $reports->report($filters)]);
    }
}
