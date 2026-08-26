<?php

namespace App\Http\Controllers\API;

use App\Enums\PurchaseReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\ReturnModel;
use App\Models\PurchaseAttachment;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseAttachmentService;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseReturnsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseReturnStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ReturnModel::query()
            ->with([
                'bill:id,seller_id,customer_id,currency',
                'seller:id,name',
                'customer:id,name',
                'items.product:id,nameAr',
                'items.size:id,size',
                'items.sizeColor:id,colorAr',
            ])
            ->withCount('items')->latest('id');
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['search'])) {
            $search = $data['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('bill_id', $search)
                    ->orWhereHas('seller', fn ($seller) => $seller->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            });
        }

        $page = $query->paginate($data['per_page'] ?? 25);
        $page->getCollection()->transform(fn ($return) => $this->withProductImages($return));
        return response()->json(['status' => 'success', 'purchase_returns' => $page]);
    }

    public function show(ReturnModel $purchaseReturn)
    {
        $purchaseReturn->load([
            'bill', 'seller', 'customer', 'items.product', 'items.size', 'items.sizeColor', 'settlements', 'activityLogs',
        ]);
        $attachmentService = app(PurchaseAttachmentService::class);
        $attachments = PurchaseAttachment::query()
            ->where('attachable_type', 'purchase_return')
            ->where('attachable_id', $purchaseReturn->id)
            ->latest('id')->get()->map(fn ($item) => $attachmentService->format($item))->values();
        $this->withProductImages($purchaseReturn);
        return response()->json([
            'status' => 'success',
            'purchase_return' => $purchaseReturn,
            'attachments' => $attachments,
            'timeline' => $purchaseReturn->activityLogs->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'title' => $log->title,
                'description' => $log->description,
                'created_by' => $log->created_by,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])->values(),
        ]);
    }

    public function availableItems(Bill $bill, PurchaseReturnService $service)
    {
        return response()->json([
            'status' => 'success',
            'bill' => $bill->load(['seller:id,name', 'customer:id,name']),
            'items' => collect($service->availableItems($bill))->map(function ($item) {
                $item['product_image'] = $this->productImage($item['product_id'], $item['size_color_id'] ?? null);
                return $item;
            })->values(),
        ]);
    }

    public function returnableBills(PurchaseReturnService $service)
    {
        $bills = Bill::query()
            ->with(['seller:id,name', 'customer:id,name'])
            ->whereHas('items', fn ($q) => $q->where('received_owned_quantity', '>', 0))
            ->latest('id')->limit(200)->get()
            ->map(function (Bill $bill) use ($service) {
                $items = $service->availableItems($bill);
                if ($items === []) return null;
                return [
                    'id' => $bill->id,
                    'party_name' => $bill->seller?->name ?? $bill->customer?->name ?? '—',
                    'currency' => $bill->currency,
                    'final_total' => (float) $bill->final_total,
                    'created_at' => $bill->created_at?->format('Y-m-d H:i:s'),
                    'available_items_count' => count($items),
                ];
            })->filter()->values();

        return response()->json(['status' => 'success', 'bills' => $bills]);
    }

    public function store(Request $request, PurchaseReturnService $service)
    {
        $data = $this->validateDraft($request);
        return response()->json(['status' => 'success', 'message' => 'تم حفظ مسودة مرتجع الشراء.', 'purchase_return' => $service->createDraft($data, $request->user()?->id)], 201);
    }

    public function update(Request $request, ReturnModel $purchaseReturn, PurchaseReturnService $service)
    {
        $data = $this->validateDraft($request);
        if ((int) $data['bill_id'] !== (int) $purchaseReturn->bill_id) abort(422, 'لا يمكن تغيير فاتورة المرتجع.');
        return response()->json(['status' => 'success', 'message' => 'تم تعديل المسودة.', 'purchase_return' => $service->updateDraft($purchaseReturn, $data, $request->user()?->id)]);
    }

    public function destroy(ReturnModel $purchaseReturn)
    {
        if ($purchaseReturn->status !== PurchaseReturnStatus::Draft->value) abort(422, 'يمكن حذف المسودة فقط.');
        $purchaseReturn->delete();
        return response()->json(['status' => 'success', 'message' => 'تم حذف المسودة.']);
    }

    public function confirm(Request $request, ReturnModel $purchaseReturn, PurchaseReturnService $service)
    {
        return response()->json(['status' => 'success', 'message' => 'تم اعتماد المرتجع وإخراج الأصناف من المخزون.', 'purchase_return' => $service->confirm($purchaseReturn, $request->user()?->id)]);
    }

    public function deliver(Request $request, ReturnModel $purchaseReturn, PurchaseReturnService $service)
    {
        return response()->json(['status' => 'success', 'message' => 'تم تسليم المرتجع وإنشاء الرصيد في دفتر الديون.', 'purchase_return' => $service->deliver($purchaseReturn, $request->user()?->id)]);
    }

    public function settle(Request $request, ReturnModel $purchaseReturn, PurchaseReturnService $service)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['cash_refund', 'bill_allocation'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'box_id' => ['required_if:type,cash_refund', 'nullable', 'integer', 'exists:boxes,id'],
            'bill_id' => ['required_if:type,bill_allocation', 'nullable', 'integer', 'exists:bills,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['status' => 'success', 'message' => 'تم تسجيل تسوية المرتجع.', 'purchase_return' => $service->settle($purchaseReturn, $data, $request->user()?->id)]);
    }

    public function cancel(Request $request, ReturnModel $purchaseReturn, PurchaseReturnService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['status' => 'success', 'message' => 'تم إلغاء المرتجع.', 'purchase_return' => $service->cancel($purchaseReturn, $data['reason'], $request->user()?->id)]);
    }

    public function uploadAttachments(Request $request, ReturnModel $purchaseReturn, PurchaseAttachmentService $attachments)
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:10240'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);
        $created = $attachments->store(
            $purchaseReturn->bill,
            $data['files'],
            $data['category'] ?? 'return_evidence',
            'purchase_return',
            $purchaseReturn->id,
            $request->user()?->id,
        );
        return response()->json(['status' => 'success', 'message' => 'تم رفع المرفقات.', 'attachments' => array_map(fn ($item) => $attachments->format($item), $created)]);
    }

    public function print(ReturnModel $purchaseReturn)
    {
        $purchaseReturn->load(['bill', 'seller', 'customer', 'items.product', 'items.size', 'items.sizeColor', 'settlements']);
        $html = view('pdf.purchase_return', ['return' => $purchaseReturn])->render();
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($html);
        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $text = substr($html, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
            $html = substr_replace($html, $arabic->utf8Glyphs($text, mb_strlen($text) + 1), $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }
        return Pdf::loadHTML($html)->download('purchase_return_'.$purchaseReturn->number.'.pdf');
    }

    private function validateDraft(Request $request): array
    {
        return $request->validate([
            'bill_id' => ['required', 'integer', 'exists:bills,id'],
            'reason' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.bill_item_id' => ['required', 'integer', 'exists:bill_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.reason' => ['nullable', 'string', 'max:60'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function withProductImages(ReturnModel $return): ReturnModel
    {
        $return->items->each(function ($item) {
            $item->setAttribute('product_image', $this->productImage($item->product_id, $item->size_color_id));
        });
        return $return;
    }

    private function productImage($productId, $sizeColorId = null): ?string
    {
        if ($sizeColorId) {
            $variant = DB::table('size_colors')->where('id', $sizeColorId)->value('image_url');
            if (trim((string) $variant) !== '') return \App\Support\ApiImageUrl::normalize($variant);
        }
        if (! Schema::hasTable('normal_image_products')) return null;
        $productColumn = Schema::hasColumn('normal_image_products', 'itemId') ? 'itemId' : 'product_id';
        $imageColumn = Schema::hasColumn('normal_image_products', 'imageUrl') ? 'imageUrl' : 'image_url';
        $image = DB::table('normal_image_products')->where($productColumn, $productId)->orderBy('id')->value($imageColumn);
        return trim((string) $image) === '' ? null : \App\Support\ApiImageUrl::normalize($image);
    }
}
