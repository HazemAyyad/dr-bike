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
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $returns = SalesReturn::query()
            ->with([
                'customer:id,name,phone',
                'seller:id,name,phone',
                'refundBox:id,name,currency',
                'items.instantSale:id,parent_id,serial_number',
                'items.instantSale.parentSale:id,serial_number',
                'items.salesOrderItem:id,sales_order_id',
                'items.salesOrderItem.salesOrder:id,serial_number',
            ])
            ->withCount('items')
            ->withSum('items as returned_quantity', 'quantity')
            ->where('return_type', 'direct')
            ->when(
                $data['date'] ?? null,
                fn ($query, $date) => $query->whereDate('completed_at', $date)
            )
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        $returns->getCollection()->each(function (SalesReturn $return): void {
            $sourceNumbers = $return->items
                ->map(function ($item): ?string {
                    if ($item->instantSale) {
                        $sale = $item->instantSale->parentSale ?: $item->instantSale;

                        return $sale->serial_number ?: '#'.$sale->id;
                    }

                    $order = $item->salesOrderItem?->salesOrder;

                    return $order?->serial_number ?: ($order ? '#'.$order->id : null);
                })
                ->filter()
                ->unique()
                ->values();

            $return->setAttribute('source_invoice_numbers', $sourceNumbers);
            $return->unsetRelation('items');
        });

        return $this->success(['sales_returns' => $returns]);
    }

    public function show(Request $request)
    {
        $data = $request->validate(['sales_return_id' => ['required', 'integer', 'exists:sales_returns,id']]);

        return $this->success([
            'sales_return' => $this->service->show((int) $data['sales_return_id'], $request->user()),
        ]);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate($this->returnRules());
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

    public function cancel(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_return_id' => ['required', 'integer', 'exists:sales_returns,id'],
                'reason' => ['required', 'string', 'min:3', 'max:500'],
            ]);
            $return = $this->service->cancel($request->user(), (int) $data['sales_return_id'], trim($data['reason']));

            return $this->success([
                'message' => 'تم إلغاء فاتورة المرتجع وعكس آثارها المحاسبية.',
                'sales_return' => $return,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 200);
        } catch (\Throwable $e) {
            Log::error('Sales return cancellation failed', ['message' => $e->getMessage(), 'sales_return_id' => $request->input('sales_return_id')]);

            return response()->json(['status' => 'error', 'message' => config('app.debug') ? $e->getMessage() : 'تعذر إلغاء فاتورة المرتجع.'], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $data = $request->validate(array_merge($this->returnRules(), [
                'sales_return_id' => ['required', 'integer', 'exists:sales_returns,id'],
                'edit_reason' => ['required', 'string', 'min:3', 'max:500'],
            ]));
            $returnId = (int) $data['sales_return_id'];
            $reason = trim($data['edit_reason']);
            unset($data['sales_return_id'], $data['edit_reason']);
            $return = $this->service->replace($request->user(), $returnId, $data, $reason);

            return $this->success([
                'message' => 'تم تعديل المرتجع بإنشاء نسخة محاسبية بديلة مع حفظ أثر النسخة السابقة.',
                'sales_return' => $return,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 200);
        } catch (\Throwable $e) {
            Log::error('Sales return update failed', ['message' => $e->getMessage(), 'sales_return_id' => $request->input('sales_return_id')]);

            return response()->json(['status' => 'error', 'message' => config('app.debug') ? $e->getMessage() : 'تعذر تعديل فاتورة المرتجع.'], 200);
        }
    }

    /** @return array<string, mixed> */
    private function returnRules(): array
    {
        return [
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
        ];
    }

    /** @param array<string, mixed> $payload */
    private function success(array $payload)
    {
        return response()->json(array_merge(['status' => 'success'], $payload), 200);
    }
}
