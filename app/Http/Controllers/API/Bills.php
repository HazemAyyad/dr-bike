<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillQuantity;
use App\Models\Debt;
use App\Models\Product;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchaseAttachment;
use App\Models\PurchaseProduct;
use App\Services\PurchaseAccountService;
use App\Services\PurchaseAttachmentService;
use App\Services\PurchasingService;
use App\Services\StoreManageItemService;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Bills extends Controller
{

        //DONE
    public function createBill(Request $request){
        try{
            $data = $request->validate([
                'seller_id'=>['nullable','integer','exists:sellers,id','required_without:customer_id'],
                'customer_id'=>['nullable','integer','exists:customers,id','required_without:seller_id'],
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.quantity'=>['required','numeric','min:1'],
                'products.*.purchase_price'=>['required','numeric','min:1'],
                'products.*.manual_override'=>['nullable','boolean'],

                'total'=>'nullable|numeric|min:1',
                'currency'=>'nullable|string',
                'notes'=>'nullable|string',
                'initial_payment' => ['nullable', 'numeric', 'min:0'],
                'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            ]);
            $bill = app(PurchasingService::class)->createPurchase($data, $request->user()?->id);

            Logs::createLog('انشاء فاتورة جديدة','انشاء فاتورة جديدة للتاجر'.' '
            .($bill->seller?->name ?? $bill->customer?->name ?? '').' '.'بقيمة'.' '.$bill->total,'bills');

            $payload = [
                'status'=>'success',
                'message'=> __('messages.bill_added'),
                'bill_id' => $bill->id,
            ];

            return response()->json($payload,200);
            
        }

             catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }


        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function receivePurchase(Request $request, PurchasingService $purchases)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
                'receipt_number' => ['nullable', 'string', 'max:120'],
                'received_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.bill_item_id' => ['required', 'integer', 'exists:bill_items,id'],
                'items.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.missing_quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.extra_quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.mismatched_quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                'items.*.resolution' => ['nullable', 'string', 'max:60'],
                'items.*.reason' => ['nullable', 'string'],
                'items.*.notes' => ['nullable', 'string'],
            ]);

            $receipt = $purchases->receive(Bill::findOrFail($data['bill_id']), $data, $request->user()?->id);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.product_was_delivered'),
                'receipt' => $receipt,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function finalizePurchase(Request $request, PurchasingService $purchases)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
                'initial_payment' => ['nullable', 'numeric', 'min:0'],
                'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            ]);

            $bill = $purchases->finalize(
                Bill::findOrFail($data['bill_id']),
                (float) ($data['initial_payment'] ?? 0),
                $data['box_id'] ?? null,
                $request->user()?->id
            );

            return response()->json(['status' => 'success', 'message' => __('messages.bill_was_delivered'), 'bill' => $bill], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function payPurchase(Request $request, PurchasingService $purchases)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'box_id' => ['required', 'integer', 'exists:boxes,id'],
                'note' => ['nullable', 'string'],
                'receipt_images' => ['nullable', 'array'],
                'receipt_images.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:8192'],
            ]);
            $receiptImages = $this->storeDebtReceiptImages($request);

            $payment = $purchases->recordPayment(
                Bill::findOrFail($data['bill_id']),
                (float) $data['amount'],
                (int) $data['box_id'],
                'payment',
                $data['note'] ?? null,
                $request->user()?->id,
                $receiptImages
            );

            return response()->json(['status' => 'success', 'message' => __('messages.data_added_successfully'), 'payment' => $payment], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function purchaseAmanat(Request $request, PurchasingService $purchases)
    {
        try {
            $data = $request->validate([
                'amanat_id' => ['required', 'integer', 'exists:purchase_amanat_stocks,id'],
                'quantity' => ['required', 'numeric', 'min:0.01'],
                'unit_price' => ['required', 'numeric', 'min:0'],
            ]);

            $amanat = $purchases->purchaseAmanat(
                PurchaseAmanatStock::findOrFail($data['amanat_id']),
                (float) $data['quantity'],
                (float) $data['unit_price'],
                $request->user()?->id
            );

            return response()->json(['status' => 'success', 'message' => __('messages.product_extra_was_purchased'), 'amanat' => $amanat], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function returnAmanat(Request $request, PurchaseAccountService $accounts)
    {
        try {
            $data = $request->validate([
                'amanat_id' => ['required', 'integer', 'exists:purchase_amanat_stocks,id'],
                'quantity' => ['required', 'numeric', 'min:0.01'],
                'note' => ['nullable', 'string'],
            ]);

            $amanat = $accounts->returnAmanat(
                PurchaseAmanatStock::findOrFail($data['amanat_id']),
                (float) $data['quantity'],
                $data['note'] ?? null,
                $request->user()?->id
            );

            return response()->json(['status' => 'success', 'message' => __('messages.data_added_successfully'), 'amanat' => $amanat], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function resolvePurchaseIssue(Request $request, PurchaseAccountService $accounts)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
                'bill_item_id' => ['required', 'integer', 'exists:bill_items,id'],
                'purchase_receipt_item_id' => ['nullable', 'integer', 'exists:purchase_receipt_items,id'],
                'issue_type' => ['required', 'string', 'in:missing,damaged,mismatched'],
                'resolution' => ['required', 'string', 'in:return_to_supplier,replacement_expected,accept_with_discount,accept_negotiated_price,other_settlement'],
                'quantity' => ['required', 'numeric', 'min:0.01'],
                'negotiated_unit_price' => ['nullable', 'numeric', 'min:0'],
                'financial_adjustment' => ['nullable', 'numeric'],
                'reason' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
            ]);

            $issue = $accounts->resolvePurchaseIssue($data, $request->user()?->id);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.data_added_successfully'),
                'issue_resolution' => $issue,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function paySupplierAccount(Request $request, PurchaseAccountService $accounts)
    {
        try {
            $data = $request->validate([
                'seller_id' => ['nullable', 'integer', 'exists:sellers,id', 'required_without:customer_id'],
                'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_without:seller_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'box_id' => ['required', 'integer', 'exists:boxes,id'],
                'currency' => ['nullable', 'string'],
                'paid_at' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
                'receipt_images' => ['nullable', 'array'],
                'receipt_images.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:8192'],
                'allocate_oldest_first' => ['nullable', 'boolean'],
                'allocations' => ['nullable', 'array'],
                'allocations.*.bill_id' => ['required_with:allocations', 'integer', 'exists:bills,id'],
                'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
            ]);
            $data['receipt_images'] = $this->storeDebtReceiptImages($request);

            $payment = $accounts->paySupplierOnAccount($data, $request->user()?->id);

            return response()->json(['status' => 'success', 'message' => __('messages.data_added_successfully'), 'payment' => $payment], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    private function storeDebtReceiptImages(Request $request): array
    {
        if (! $request->hasFile('receipt_images')) {
            return [];
        }

        $path = public_path('DebtsReceipts');
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $names = [];
        foreach ($request->file('receipt_images', []) as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $original = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName());
            $name = time().'_'.$index.'_'.$original;
            $file->move($path, $name);
            $names[] = $name;
        }

        return $names;
    }

    public function purchaseAccountOpenBills(Request $request)
    {
        try {
            $data = $request->validate([
                'seller_id' => ['nullable', 'integer', 'exists:sellers,id', 'required_without:customer_id'],
                'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_without:seller_id'],
                'currency' => ['nullable', 'string'],
            ]);

            $currency = $data['currency'] ?? 'شيكل';
            $bills = Bill::query()
                ->with(['seller:id,name', 'customer:id,name'])
                ->where('workflow_status', 'finalized')
                ->where('payment_status', '!=', 'paid')
                ->where('currency', $currency)
                ->when($data['seller_id'] ?? null, fn ($q, $sellerId) => $q->where('seller_id', $sellerId))
                ->when($data['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
                ->orderBy('finalized_at')
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(fn (Bill $bill) => [
                    'id' => $bill->id,
                    'source_type' => $bill->seller_id ? 'seller' : 'customer',
                    'source_id' => $bill->seller_id ?: $bill->customer_id,
                    'source_name' => $bill->seller?->name ?? $bill->customer?->name,
                    'currency' => $bill->currency,
                    'final_total' => (float) $bill->final_total,
                    'paid_amount' => (float) $bill->paid_amount,
                    'remaining_amount' => max(0, (float) $bill->final_total - (float) $bill->paid_amount),
                    'finalized_at' => optional($bill->finalized_at)->toDateTimeString(),
                    'created_at' => optional($bill->created_at)->toDateTimeString(),
                ]);

            return response()->json(['status' => 'success', 'bills' => $bills], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function purchaseTimeline(Request $request)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
            ]);

            $bill = Bill::with(['activityLogs' => fn ($q) => $q->latest('id')])->findOrFail($data['bill_id']);

            return response()->json([
                'status' => 'success',
                'timeline' => $bill->activityLogs,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function uploadPurchaseAttachment(Request $request, PurchaseAttachmentService $attachments)
    {
        try {
            $data = $request->validate([
                'bill_id' => ['required', 'integer', 'exists:bills,id'],
                'category' => ['nullable', 'string', 'max:60'],
                'attachable_type' => ['nullable', 'string', 'max:120'],
                'attachable_id' => ['nullable', 'integer'],
                'files' => ['required', 'array', 'min:1'],
                'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx', 'max:10240'],
            ]);

            $bill = Bill::findOrFail($data['bill_id']);
            $created = $attachments->store(
                $bill,
                $request->file('files', []),
                $data['category'] ?? 'evidence',
                $data['attachable_type'] ?? null,
                $data['attachable_id'] ?? null,
                $request->user()?->id
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.data_added_successfully'),
                'attachments' => array_map(fn (PurchaseAttachment $attachment) => $attachments->format($attachment), $created),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function purchasePriceIntelligence(Request $request, PurchasingService $purchases)
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
                'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            ]);

            return response()->json([
                'status' => 'success',
                'price_intelligence' => $purchases->priceIntelligence(
                    (int) $data['product_id'],
                    $data['seller_id'] ?? null,
                    $data['customer_id'] ?? null
                ),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'error' => $e->errors()], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    public function purchaseAmanatIndex(Request $request)
    {
        $status = $request->input('status');
        $search = trim((string) $request->input('search', ''));

        $rows = PurchaseAmanatStock::query()
            ->with(['product:id,nameAr', 'bill:id,seller_id,customer_id,created_at,status,workflow_status', 'bill.seller:id,name', 'bill.customer:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('product', fn ($p) => $p->where('nameAr', 'like', "%{$search}%"))
                    ->orWhereHas('bill.seller', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('bill.customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (PurchaseAmanatStock $row) {
                $bill = $row->bill;
                $source = $bill?->seller ?: $bill?->customer;
                return [
                    'id' => $row->id,
                    'bill_id' => $row->bill_id,
                    'bill_item_id' => $row->bill_item_id,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product?->nameAr ?? '',
                    'source_type' => $bill?->seller_id ? 'seller' : 'customer',
                    'source_id' => $bill?->seller_id ?: $bill?->customer_id,
                    'source_name' => $source?->name ?? '',
                    'quantity' => (float) $row->quantity,
                    'remaining_quantity' => (float) $row->remaining_quantity,
                    'status' => $row->status,
                    'negotiated_unit_price' => $row->negotiated_unit_price,
                    'received_at' => optional($row->created_at)->toDateTimeString(),
                    'age_days' => $row->created_at ? $row->created_at->diffInDays(now()) : null,
                    'notes' => $row->notes,
                ];
            });

        return response()->json([
            'status' => 'success',
            'amanat' => $rows,
        ], 200);
    }

    public function purchaseDiscrepanciesIndex(Request $request)
    {
        $type = $request->input('type');
        $search = trim((string) $request->input('search', ''));

        $rows = BillItem::query()
            ->with(['product:id,nameAr', 'bill:id,seller_id,customer_id,created_at,status,workflow_status', 'bill.seller:id,name', 'bill.customer:id,name'])
            ->where(function ($q) {
                $q->where('missing_amount', '>', 0)
                    ->orWhere('custody_quantity', '>', 0)
                    ->orWhere('damaged_quantity', '>', 0)
                    ->orWhere('mismatched_quantity', '>', 0)
                    ->orWhere('not_compatible_amount', '>', 0);
            })
            ->when($type === 'missing', fn ($q) => $q->where('missing_amount', '>', 0))
            ->when($type === 'extra', fn ($q) => $q->where('custody_quantity', '>', 0))
            ->when($type === 'damaged', fn ($q) => $q->where('damaged_quantity', '>', 0))
            ->when($type === 'mismatched', fn ($q) => $q->where(function ($m) {
                $m->where('mismatched_quantity', '>', 0)->orWhere('not_compatible_amount', '>', 0);
            }))
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('product', fn ($p) => $p->where('nameAr', 'like', "%{$search}%"))
                    ->orWhereHas('bill.seller', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('bill.customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (BillItem $item) {
                $bill = $item->bill;
                $source = $bill?->seller ?: $bill?->customer;
                return [
                    'bill_id' => $item->bill_id,
                    'bill_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->nameAr ?? '',
                    'source_type' => $bill?->seller_id ? 'seller' : 'customer',
                    'source_id' => $bill?->seller_id ?: $bill?->customer_id,
                    'source_name' => $source?->name ?? '',
                    'missing_quantity' => (float) ($item->missing_amount ?? 0),
                    'extra_quantity' => (float) ($item->custody_quantity ?? 0),
                    'damaged_quantity' => (float) ($item->damaged_quantity ?? 0),
                    'mismatched_quantity' => (float) (($item->mismatched_quantity ?? 0) ?: ($item->not_compatible_amount ?? 0)),
                    'status' => $item->status,
                    'description' => $item->not_compatible_description,
                    'created_at' => optional($bill?->created_at)->toDateTimeString(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'discrepancies' => $rows,
        ], 200);
    }

private function getBills($statuses)
{
    try {
        $bills = Bill::whereIn('status', (array) $statuses) 
            ->with(['seller:id,name', 'customer:id,name'])
            ->withCount([
                'items as items_count',
                'items as receiving_issues_count' => function ($q) {
                    $q->where(function ($issue) {
                        $issue->where('missing_amount', '>', 0)
                            ->orWhere('custody_quantity', '>', 0)
                            ->orWhere('damaged_quantity', '>', 0)
                            ->orWhere('mismatched_quantity', '>', 0)
                            ->orWhere('not_compatible_amount', '>', 0);
                    });
                },
            ])
            ->withSum('items as missing_quantity_total', 'missing_amount')
            ->withSum('items as extra_quantity_total', 'custody_quantity')
            ->withSum('items as damaged_quantity_total', 'damaged_quantity')
            ->withSum('items as mismatched_quantity_total', 'mismatched_quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'total',
                'final_total',
                'paid_amount',
                'payment_status',
                'workflow_status',
                'created_at',
                'seller_id',
                'customer_id',
                'status',
                'currency',
            ])
            ->map(fn ($bill) => $this->formatBillListItem($bill));

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
            ],200);


        }

        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    private function formatBillListItem(Bill $bill): array
    {
        $total = (float) ($bill->final_total ?: $bill->total);
        $paid = (float) ($bill->paid_amount ?? 0);

        return [
            'id' => $bill->id,
            'total' => $bill->total,
            'final_total' => $total,
            'paid_amount' => $paid,
            'remaining_amount' => max(0, $total - $paid),
            'payment_status' => $bill->payment_status ?: 'unpaid',
            'workflow_status' => $bill->workflow_status ?: 'awaiting_receiving',
            'seller' => $bill->seller?->name ?? $bill->customer?->name ?? 'no seller',
            'source_type' => $bill->seller_id ? 'seller' : ($bill->customer_id ? 'customer' : ''),
            'created_at' => $bill->created_at->format('Y-m-d'),
            'status' => $bill->status,
            'currency' => $bill->currency ?? 'شيكل',
            'items_count' => (int) ($bill->items_count ?? 0),
            'receiving_issues_count' => (int) ($bill->receiving_issues_count ?? 0),
            'missing_quantity_total' => (float) ($bill->missing_quantity_total ?? 0),
            'extra_quantity_total' => (float) ($bill->extra_quantity_total ?? 0),
            'damaged_quantity_total' => (float) ($bill->damaged_quantity_total ?? 0),
            'mismatched_quantity_total' => (float) ($bill->mismatched_quantity_total ?? 0),
        ];
    }



    public function getBillDetails(Request $request){
        try{

            $request->validate([
                'bill_id'=>'required|integer|exists:bills,id'
            ]);

            $bill = Bill::with([
                'items.product',
                'items.amanatStocks',
                'seller',
                'customer',
                'payments',
                'activityLogs' => fn ($q) => $q->latest('id'),
            ])->findOrFail($request->bill_id);
            $items = $bill->items;
            $attachmentService = app(PurchaseAttachmentService::class);
            $attachments = PurchaseAttachment::query()
                ->where('bill_id', $bill->id)
                ->latest('id')
                ->get()
                ->map(fn (PurchaseAttachment $attachment) => $attachmentService->format($attachment))
                ->values();
            $returns = \App\Models\ReturnModel::query()
                ->where('bill_id', $bill->id)
                ->with('items.product')
                ->latest('id')
                ->get()
                ->map(fn ($return) => [
                    'id' => (int) $return->id,
                    'total' => (float) $return->total,
                    'currency' => $return->currency,
                    'status' => $return->status,
                    'resolution' => $return->resolution,
                    'refund_box_id' => $return->refund_box_id ? (int) $return->refund_box_id : null,
                    'created_at' => $return->created_at?->format('Y-m-d H:i:s'),
                    'items' => $return->items->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'product_id' => (int) $item->product_id,
                        'product_name' => $item->product?->nameAr,
                        'quantity' => (float) $item->quantity,
                        'price' => (float) $item->price,
                    ])->values(),
                ])->values();

            $productsFormatted =  $items->map( function ($item) use ($bill){
                $image = null;
                if (\Illuminate\Support\Facades\Schema::hasTable('normal_image_products')) {
                    $productColumn = \Illuminate\Support\Facades\Schema::hasColumn('normal_image_products', 'itemId') ? 'itemId' : 'product_id';
                    $imageColumn = \Illuminate\Support\Facades\Schema::hasColumn('normal_image_products', 'imageUrl') ? 'imageUrl' : 'image_url';
                    $image = \Illuminate\Support\Facades\DB::table('normal_image_products')
                        ->where($productColumn, $item->product->id)
                        ->orderBy('id')
                        ->value($imageColumn);
                }
                $amanat = $item->amanatStocks
                    ->where('remaining_quantity', '>', 0)
                    ->values()
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'quantity' => (float) $row->quantity,
                        'remaining_quantity' => (float) $row->remaining_quantity,
                        'status' => $row->status,
                        'negotiated_unit_price' => $row->negotiated_unit_price ? (float) $row->negotiated_unit_price : null,
                        'notes' => $row->notes,
                    ]);

                return [
                        'bill_id' => $bill->id,
                        'bill_item_id' => $item->id,
                        'product_id' => $item->product->id,
                        'product_name'=> $item->product->nameAr,
                        'product_image' => $image ?: 'no image',
                        'quantity' => $item->quantity,
                        'ordered_quantity' => $item->ordered_quantity ?? $item->quantity,
                        'received_owned_quantity' => $item->received_owned_quantity ?? 0,
                        'remaining_quantity' => max(0, (float) ($item->ordered_quantity ?? $item->quantity) - (float) ($item->received_owned_quantity ?? 0) - (float) ($item->missing_amount ?? 0)),
                        'custody_quantity' => $item->custody_quantity ?? 0,
                        'price' => $item->price,
                        'product_status' => $item->status,
                        'sub_total' => $item->quantity * $item->price,
                        'extra_amount' => $item->extra_amount?? null,
                        'missing_amount' => $item->missing_amount?? null,
                        'not_compatible_amount' => $item->not_compatible_amount?? null,
                        'damaged_quantity' => $item->damaged_quantity ?? 0,
                        'mismatched_quantity' => $item->mismatched_quantity ?? 0,
                        'amanat_stocks' => $amanat,
                    ];
                } );
            $effectiveTotal = (float) ($bill->final_total ?: $bill->total);
            $paidAmount = (float) ($bill->paid_amount ?? 0);

            $formatted =  [
                'bill_id' => $bill->id,
                'products'=> $productsFormatted,
                'seller_id' => $bill->seller_id,
                'customer_id' => $bill->customer_id,
                'seller_name' => $bill->seller?->name ?? $bill->customer?->name ?? '',
                'created_at' => $bill->created_at->format('d M Y'), 
                'total_bill' => $bill->total,
                'workflow_status' => $bill->workflow_status,
                'payment_status' => $bill->payment_status,
                'final_total' => $effectiveTotal,
                'paid_amount' => $paidAmount,
                'remaining_amount' => max(0, $effectiveTotal - $paidAmount),
                'payments' => $bill->payments->map(fn ($payment) => [
                    'id' => (int) $payment->id,
                    'box_id' => $payment->box_id ? (int) $payment->box_id : null,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'type' => $payment->type,
                    'paid_at' => $payment->paid_at?->format('Y-m-d'),
                    'note' => $payment->note,
                ])->values(),
                'returns' => $returns,
                'attachments' => $attachments,
                'timeline' => $bill->activityLogs->map(fn ($log) => [
                    'id' => (int) $log->id,
                    'event' => $log->event,
                    'title' => $log->title,
                    'description' => $log->description,
                    'source_type' => $log->source_type,
                    'source_id' => $log->source_id ? (int) $log->source_id : null,
                    'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                ])->values(),
            ];

            return response()->json([
                'status'=>'success',
                'bill_details'=> $formatted,
            ],200);

        }


        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.retrieve_data_error'),
                ],200);
            }
        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                ],200);
            }


        catch(QueryException $e){
                \Illuminate\Support\Facades\Log::error('purchase bill details query failed', [
                    'bill_id' => $request->bill_id ?? null,
                    'error' => $e->getMessage(),
                ]);
                return response([
                    'status'=>'error',
                    'message' => __('messages.retrieve_data_error'),
                ],200);
            }
            

            catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('purchase bill details failed', [
                    'bill_id' => $request->bill_id ?? null,
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }


    public function createBillQuantity(Request $request){
                try{
            $data = $request->validate([
                'products.*'=>['required','array'],

                'products.*.product_id'=>['required','integer','exists:products,id'],
                'products.*.quantity'=>['required','integer','min:1'],

            ]);

                $storeSyncWarnings = [];
                foreach($request->products as $item){
                    $product = Product::findOrFail($item['product_id']);
                    $product->update(['stock'=> $product->stock+ $item['quantity']]);

                    BillQuantity::create([
                        'product_id'=> $product->id,
                        'quantity' => $item['quantity'],
                    ]);

                    $sync = app(StoreManageItemService::class)->syncProductStockToStore($product->fresh());
                    if (! ($sync['ok'] ?? false)) {
                        $storeSyncWarnings[] = ($sync['error'] ?? __('messages.something_wrong')).' (منتج '.$product->id.')';
                    }

                }

                $payload = [
                'status'=>'success',
                'message'=> __('messages.bill_quantity_added'),
            ];
                if (count($storeSyncWarnings) > 0) {
                    $payload['store_sync_warnings'] = $storeSyncWarnings;
                }

                return response()->json($payload,200);
            
        }

             catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }


        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }


}

    // *********************BILL STATUS ***********************
    // غير معالجة
   
    // bills that have at least one unfinished item (item with no status yet)
  //DONE
    public function getUnfinishedBills(){
       // return $this->getBills('unfinished');
       try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->where('status', 'unfinished');
                })        
            ->with(['seller:id,name', 'customer:id,name'])
            ->withCount([
                'items as items_count',
                'items as receiving_issues_count' => function ($q) {
                    $q->where(function ($issue) {
                        $issue->where('missing_amount', '>', 0)
                            ->orWhere('custody_quantity', '>', 0)
                            ->orWhere('damaged_quantity', '>', 0)
                            ->orWhere('mismatched_quantity', '>', 0)
                            ->orWhere('not_compatible_amount', '>', 0);
                    });
                },
            ])
            ->withSum('items as missing_quantity_total', 'missing_amount')
            ->withSum('items as extra_quantity_total', 'custody_quantity')
            ->withSum('items as damaged_quantity_total', 'damaged_quantity')
            ->withSum('items as mismatched_quantity_total', 'mismatched_quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id','total','final_total','paid_amount','payment_status','workflow_status','created_at','seller_id','customer_id','status','currency'])
            ->map(fn ($bill) => $this->formatBillListItem($bill));

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
            ],200);  
        
        }
            catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function getFinishedBills(){
        return $this->getBills('finished');
    }

    // if there's missing values for at least one item and bill isn't finished yet and all items have status
    public function getUnmatchedBills(){
     try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->where(function ($issue) {
                        $issue->where('missing_amount', '>', 0)
                            ->orWhere('custody_quantity', '>', 0)
                            ->orWhere('damaged_quantity', '>', 0)
                            ->orWhere('mismatched_quantity', '>', 0)
                            ->orWhere('not_compatible_amount', '>', 0);
                    });
                })
        ->whereDoesntHave('items', function ($q) {
                        // exclude bills with any unfinished items
                        $q->where('status', 'unfinished');
                    })      
           ->where('status','!=','finished')
            ->with(['seller:id,name', 'customer:id,name'])
            ->withCount([
                'items as items_count',
                'items as receiving_issues_count' => function ($q) {
                    $q->where(function ($issue) {
                        $issue->where('missing_amount', '>', 0)
                            ->orWhere('custody_quantity', '>', 0)
                            ->orWhere('damaged_quantity', '>', 0)
                            ->orWhere('mismatched_quantity', '>', 0)
                            ->orWhere('not_compatible_amount', '>', 0);
                    });
                },
            ])
            ->withSum('items as missing_quantity_total', 'missing_amount')
            ->withSum('items as extra_quantity_total', 'custody_quantity')
            ->withSum('items as damaged_quantity_total', 'damaged_quantity')
            ->withSum('items as mismatched_quantity_total', 'mismatched_quantity')
            ->latest('id')
            ->get(['id','total','final_total','paid_amount','payment_status','workflow_status','created_at','seller_id','customer_id','status','currency'])
            ->map(fn ($bill) => $this->formatBillListItem($bill));

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
            ],200);  
        
        }
            catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            } 
       }

    public function getSecuritiesBills(){
   try{
        $bills = Bill::whereHas('items', function ($q) {
                    $q->whereIn('status', ['extra', 'not_compatible']);

                })  
        ->whereDoesntHave('items', function ($q) {
                        // exclude bills with any unfinished items
                        $q->where('status', 'unfinished');
                    })      
           ->where('status','!=','finished')
            ->with(['seller:id,name', 'customer:id,name'])
            ->withCount([
                'items as items_count',
                'items as receiving_issues_count' => function ($q) {
                    $q->where(function ($issue) {
                        $issue->where('missing_amount', '>', 0)
                            ->orWhere('custody_quantity', '>', 0)
                            ->orWhere('damaged_quantity', '>', 0)
                            ->orWhere('mismatched_quantity', '>', 0)
                            ->orWhere('not_compatible_amount', '>', 0);
                    });
                },
            ])
            ->withSum('items as missing_quantity_total', 'missing_amount')
            ->withSum('items as extra_quantity_total', 'custody_quantity')
            ->withSum('items as damaged_quantity_total', 'damaged_quantity')
            ->withSum('items as mismatched_quantity_total', 'mismatched_quantity')
            ->latest('id')
            ->get(['id','total','final_total','paid_amount','payment_status','workflow_status','created_at','seller_id','customer_id','status','currency'])
            ->map(fn ($bill) => $this->formatBillListItem($bill));

            return response()->json([
                'status'=>'success',
                'bills'=> $bills,
            ],200);  
        
        }
            catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            } 
    }
    // cancelled + completed
    public function getArchivedBills(){
    return $this->getBills(['cancelled', 'finished']); 
    }


        public function changeProductStatus(Request $request){
        try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',
                'status' => 'required|string|in:finished,missing,extra,not_compatible',
                'extra_amount' =>'required_if:status,extra|nullable|integer|min:1',
                'missing_amount' =>'required_if:status,missing|nullable|integer|min:1',
                'not_compatible_amount' => 'required_if:status,not_compatible|nullable|integer|min:1' ,
                'not_compatible_description'=> 'required_if:status,not_compatible|nullable|string',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $billItem = BillItem::where('bill_id',$bill->id)
            ->where('product_id',$request->product_id)
            ->first();
            if($billItem->status !== 'unfinished'){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.can_only_change_status_once'),
                ],200);
            }

            if($request->status === 'finished'){
                $billItem->update(['status'=>'finished']);

                $this->changeProductStatusToFinished($billItem, $request->bill_id);
            }
            elseif($request->status === 'missing'){

                if($request->missing_amount > $billItem->quantity){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.entered_amount_bigger_than_quantity'),
                    ],200);
                }
                $billItem->update(['missing_amount'=> $request->missing_amount]);


                $amountToReduce = $request->missing_amount * $billItem->price;
                $bill->total -= $amountToReduce;
                $bill->save();

               $billItem->product()->decrement('stock', $request->missing_amount);


                $billItem->update(['status'=>'finished']);
                $this->changeProductStatusToFinished($billItem, $request->bill_id);

            }

            elseif($request->status === 'not_compatible'){
                if($request->not_compatible_amount > $billItem->quantity){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.entered_amount_bigger_than_quantity'),
                    ],200);
                }
                $billItem->update([
                    'not_compatible_amount'=>$request->not_compatible_amount,
                    'not_compatible_description' => $request->not_compatible_description,
                    'status' => 'not_compatible',
                ]);



                $amountToReduce = $request->not_compatible_amount * $billItem->price;
                $bill->total -= $amountToReduce;
                $bill->save();
         
                $product = $billItem->product;
                $product->stock -= $request->not_compatible_amount;
                $product->save();

            }

            elseif($request->status==='extra'){

                $billItem->update([
                    'extra_amount'=> $request->extra_amount,
                    'status' =>'extra',
                ]);
            }

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.product_status_updated'),
            ],200);


        }

        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }



    private function changeProductStatusToFinished(BillItem $billItem,$billId){
        


            $exists = BillItem::where('bill_id',$billId)
            ->whereNotIn('status',['finished'])
            ->exists();
            if(!$exists){
                $billItem->bill->update(['status'=>'finished']);
                Logs::createLog('اكتمال فاتورة ','تم اكتمال فاتورة  للتاجر'.' '
                .$billItem->bill->seller->name.' '.'بقيمة'.' '.$billItem->bill->total,'bills');

                Debt::create([
                'seller_id'=> $billItem->bill->seller_id,
                'type' => 'we owe',
                'total' => $billItem->bill->total,
                'bill_id' => $billItem->bill->id,
            ]);

                Logs::createLog(
                    'اضافة دين علينا للتاجر بعد اكتمال الفاتورة',
                    ' تمت اضافة دين علينا إلى رصيد ديون التاجر'.' '.$billItem->bill->seller->name
                    .' '.' بقيمة'.' '.$billItem->bill->total,
                    'debts'
                ); 

            }


        }

        


        public function purchaseExtraProducts(Request $request){
        try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',

            ]);

            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)
            ->first();

            if($billItem->status !== 'extra'){
                return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.must_be_status_extra'),
                    ],200);
            }


            $bill = Bill::findOrFail($request->bill_id);



            $amountToAdd = $billItem->extra_amount * $billItem->price;
            $bill->total += $amountToAdd;
            $bill->save();


            $product = Product::findOrFail($billItem->product_id);
            $product->stock += $billItem->extra_amount;
            $product->save();

            $billItem->update(['status'=>'finished']);
            $this->changeProductStatusToFinished($billItem, $request->bill_id);
            

            return response()->json([
                'status'=>'success',
                'message' =>__('messages.product_extra_was_purchased'),
            ],200);
          
            


        }

        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function deliverOneProduct(Request $request){
        try{
            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id'=> 'required|integer|exists:bill_items,product_id',

            ]);

            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)
            ->first();
            if($billItem->status ==='extra' || $billItem->status === 'not_compatible'){

                $billItem->update(['status'=>'finished']);

                $this->changeProductStatusToFinished($billItem, $request->bill_id);
              
                return response()->json([
                    'status'=>'success',
                    'message'=>__('messages.product_was_delivered'),
                ],200);


            }

            else{
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.product_extra_or_not_compatible'),
                ],200);
            }
        }
            catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                    'error' => $e->errors(),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    // for not compatible second option
        public function purchaseProdcutsNewPrice(Request $request){
             try{

            $request->validate([
                'bill_id'=> 'required|integer|exists:bills,id',
                'product_id' =>'required|integer|exists:bill_items,product_id',
                'price' => 'required|numeric|min:1',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $billItem = BillItem::where('bill_id',$request->bill_id)
            ->where('product_id',$request->product_id)->first();

            if($billItem->status !== 'not_compatible'){
                return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.must_be_status_not_compatible'),
                    ],200);
            }

 

            $billItem->update([
               // 'price' => $request->price,
                'status' => 'finished',
                'price' => $request->price,
            ]);

            $bill->total += $request->price * $billItem->quantity;
            $bill->save();

            $this->changeProductStatusToFinished($billItem, $request->bill_id);


            return response()->json([
                'status'=>'success',
                'message' =>__('messages.bill_was_delivered'),
            ],200);
          
            


        }

        catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function cancelBill(Request $request){
        try{

            $request->validate([
                'bill_id'=>'required|integer|exists:bills,id',
            ]);

            $bill = Bill::findOrFail($request->bill_id);
            $bill->update(['status'=>'cancelled']);
            $bill->items()->update(['status'=>'cancelled']);



            foreach($bill->items as $item){
                $item->product->update(['stock' => $item->product->stock - $item->quantity ]);
                    PurchaseProduct::where('seller_id',$bill->seller_id)
                    ->where('product_id',$item->product_id)->delete();
            }

            Logs::createLog('ارجاع فاتورة ','تم ارجاع فاتورة  للتاجر'.' '
            .$bill->seller->name.' '.'بقيمة'.' '.$bill->total,'bills');


            return response()->json([
                'status'=>'success',
                'message'=>__('messages.bill_cancelled'),
            ],200);
        }
            catch(ValidationException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.validation_failed'),
                ],200);
            }

        catch(ModelNotFoundException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    // deliver the whole bill at one
    // public function deliverBill(Request $request){
    //          try{

    //         $request->validate([
    //             'bill_id'=> 'required|integer|exists:bills,id',

    //         ]);

    //         $bill = Bill::findOrFail($request->bill_id);


    //         $bill->update(['status'=>'finished']);
    //         $bill->items()->update(['status'=>'finished']);

    //         Logs::createLog('تسليم فاتورة ','تم تسليم فاتورة  للتاجر'.' '
    //         .$bill->seller->name.' '.'بقيمة'.' '.$bill->total,'bills');

    //         return response()->json([
    //             'status'=>'success',
    //             'message' =>__('messages.bill_was_delivered'),
    //         ],200);
          
            


    //     }

    //     catch(ValidationException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.validation_failed'),
    //             ],200);
    //         }

    //     catch(ModelNotFoundException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.something_wrong'),
    //             ],200);
    //         }
    //     catch(QueryException $e){
    //             return response([
    //                 'status'=>'error',
    //                 'message' => __('messages.something_wrong'),
    //             ],200);
    //         }
            

    //         catch (\Exception $e) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => __('messages.something_wrong'),
    //             ], 200);
    //         }
    // }


    // download bill as pdf
    public function downloadBill(Request $request){
    try {
                $request->validate([
                    'bill_id' => 
                        'required',
                        'integer','exists:bills,id'
 
                    ]);


        $bill = Bill::with([
            'items.product',
            'seller',
            'customer',
            'payments' => fn ($query) => $query->oldest('paid_at')->oldest('id'),
        ])->findOrFail($request->bill_id);
   
       // 🔹 First render HTML from the Blade
        $reportHtml = view('pdf.bill_report', [
            'bill' => $bill,
        ])->render();

        // 🔹 Fix Arabic text
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $arabicText = substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
            $utf8ar = $arabic->utf8Glyphs($arabicText, mb_strlen($arabicText) + 1);
            $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }

        // 🔹 Load fixed HTML into PDF
        $pdf = Pdf::loadHTML($reportHtml);

        $fileName = 'purchase_invoice_'.$bill->id.'.pdf';

        return $pdf->download($fileName);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),

        ], 200);
    }  catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }
    

}
