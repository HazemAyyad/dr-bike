<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DeliveryCompanyAccountService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryCompanyAccountsController extends Controller
{
    public function __construct(
        protected DeliveryCompanyAccountService $accounts,
    ) {}

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'accounts' => $this->accounts->accounts(),
        ]);
    }

    public function show(Request $request)
    {
        $data = $request->validate([
            'delivery_company_id' => 'required|integer|exists:delivery_companies,id',
            'delivery_company_name' => 'required|string|max:255',
        ]);

        return response()->json([
            'status' => 'success',
            'account' => $this->accounts->account(
                (int) $data['delivery_company_id'],
                trim((string) $data['delivery_company_name'])
            ),
        ]);
    }

    public function settle(Request $request)
    {
        try {
            $data = $request->validate([
                'delivery_company_id' => 'required|integer|exists:delivery_companies,id',
                'delivery_company_name' => 'required|string|max:255',
                'allocations' => 'required|array|min:1|max:100',
                'allocations.*.order_id' => 'required|integer|exists:sales_orders,id',
                'allocations.*.amount' => 'required|numeric|gt:0|max:999999999.99',
                'payment_box_id' => 'nullable|integer|exists:boxes,id',
                'idempotency_key' => 'required|string|max:100',
                'notes' => 'nullable|string|max:2000',
            ]);

            $batch = $this->accounts->settleBatch($request->user(), $data);

            return response()->json([
                'status' => 'success',
                'message' => 'تمت تسوية الطلبيات المحددة بنجاح.',
                'batch' => $batch,
                'account' => $this->accounts->account(
                    (int) $data['delivery_company_id'],
                    trim((string) $data['delivery_company_name'])
                ),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?? __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
