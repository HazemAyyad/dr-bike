<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\FinancialAccountingReportService;
use App\Services\SalesPurchasesExpensesReportService;
use App\Services\PartyAccountStatementReportService;
use App\Models\Customer;
use App\Models\Seller;
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

    public function salesPurchasesExpenses(Request $request, SalesPurchasesExpensesReportService $service)
    {
        $filters = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'currency' => ['required', Rule::in(['شيكل', 'دولار', 'دينار'])],
        ]);

        return response()->json(['status' => 'success', 'data' => $service->report($filters)]);
    }

    public function accountStatementPeople()
    {
        $people = Customer::query()->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn ($person) => array_merge($person->toArray(), ['type' => 'customer']))
            ->concat(Seller::query()->orderBy('name')->get(['id', 'name', 'phone'])
                ->map(fn ($person) => array_merge($person->toArray(), ['type' => 'seller'])));

        return response()->json(['status' => 'success', 'data' => ['people' => $people->values()]]);
    }

    public function accountStatement(Request $request, PartyAccountStatementReportService $service)
    {
        $filters = $request->validate([
            'person_type' => ['required', Rule::in(['customer', 'seller'])],
            'person_id' => ['required', 'integer', Rule::exists($request->input('person_type') === 'seller' ? 'sellers' : 'customers', 'id')],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'currency' => ['required', Rule::in(['شيكل', 'دولار', 'دينار'])],
            'include_opening' => ['nullable', 'boolean'],
            'include_returned_checks' => ['nullable', 'boolean'],
        ]);

        return response()->json(['status' => 'success', 'data' => $service->report($filters)]);
    }
}
