<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesReturnsController extends Controller
{
    public function __construct(private SalesReturnService $service) {}

    public function people(Request $request)
    {
        return $this->success(['people' => $this->service->people($request->query('search'))]);
    }

    public function availableItems(Request $request)
    {
        $data = $request->validate([
            'person_type' => ['required', Rule::in(['customer', 'seller'])],
            'person_id' => ['required', 'integer'],
        ]);

        return $this->success([
            'items' => $this->service->availableItems($data['person_type'], (int) $data['person_id']),
        ]);
    }

    public function index(Request $request)
    {
        $returns = SalesReturn::query()
            ->with(['customer:id,name,phone', 'seller:id,name,phone', 'refundBox:id,name,currency'])
            ->where('return_type', 'direct')
            ->orderByDesc('id')
            ->paginate(min(100, max(10, (int) $request->query('per_page', 30))));

        return $this->success(['sales_returns' => $returns]);
    }

    public function show(Request $request)
    {
        $data = $request->validate(['sales_return_id' => ['required', 'integer', 'exists:sales_returns,id']]);

        return $this->success(['sales_return' => $this->service->show((int) $data['sales_return_id'])]);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'person_type' => ['required', Rule::in(['customer', 'seller'])],
                'person_id' => ['required', 'integer'],
                'cash_refund_amount' => ['nullable', 'numeric', 'min:0'],
                'refund_box_id' => ['nullable', 'integer', 'exists:boxes,id'],
                'note' => ['nullable', 'string', 'max:1000'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.source_type' => ['required', Rule::in(['instant_sale', 'sales_order'])],
                'items.*.source_item_id' => ['required', 'integer'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
                'items.*.price_override_reason' => ['nullable', 'string', 'max:500'],
            ]);
            $return = $this->service->create($request->user(), $data);

            return $this->success([
                'message' => 'تم إنشاء فاتورة مرتجع المبيعات بنجاح.',
                'sales_return' => $return,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => collect($e->errors())->flatten()->first() ?: 'فشل التحقق من البيانات.', 'errors' => $e->errors()], 200);
        } catch (\Throwable $e) {
            Log::error('Sales return creation failed', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'user_id' => $request->user()?->id]);

            return response()->json(['status' => 'error', 'message' => config('app.debug') ? $e->getMessage() : 'تعذر إنشاء فاتورة المرتجع.'], 200);
        }
    }

    /** @param array<string, mixed> $payload */
    private function success(array $payload)
    {
        return response()->json(array_merge(['status' => 'success'], $payload), 200);
    }
}
