<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\Project;
use App\Models\Seller;
use App\Services\DebtLedgerService;
use App\Services\OfferPackageService;
use App\Support\ApiImageUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isEmpty;

class InstantSales extends Controller
{
    private function invoiceProductImage(?Product $product): string
    {
        if ($product === null) {
            return 'no image';
        }

        $image = $product->viewImages->first()
            ?? $product->normalImages->first();

        return $image ? ApiImageUrl::normalize($image->imageUrl) : 'no image';
    }

    private const TRADER_CUSTOMER_TYPES = [
        'trader', 'merchant', 'wholesale', 'تاجر', 'جملة', 'تاجر جملة',
    ];

    private function buyerTypeLabelAr(string $type): string
    {
        return match ($type) {
            'trader' => 'تاجر',
            'customer' => 'زبون',
            default => 'غير محدد',
        };
    }

    private function inferBuyerTypeFromCustomer(Customer $customer): string
    {
        $customerType = strtolower(trim((string) ($customer->type ?? '')));

        return in_array($customerType, self::TRADER_CUSTOMER_TYPES, true)
            ? 'trader'
            : 'customer';
    }

    /**
     * @return array{buyer_type: string, buyer_id: int|null, buyer_name: string, buyer_phone: ?string, buyer_address: ?string}
     */
    private function buyerSnapshotArray(string $type, ?Customer $customer = null): array
    {
        if (! $customer instanceof Customer) {
            return [
                'buyer_type' => $type,
                'buyer_id' => null,
                'buyer_name' => '-',
                'buyer_phone' => null,
                'buyer_address' => null,
            ];
        }

        return [
            'buyer_type' => $type,
            'buyer_id' => $customer->id,
            'buyer_name' => $customer->name ?: '-',
            'buyer_phone' => $customer->phone,
            'buyer_address' => $customer->address,
        ];
    }

    /**
     * Resolve buyer fields to persist on instant_sales (snapshot at sale time).
     *
     * @return array{buyer_type: string, buyer_id: int|null, buyer_name: string, buyer_phone: ?string, buyer_address: ?string}
     */
    private function resolveBuyerForStorage(Request $request, ?int $projectId = null, ?string $saleType = null): array
    {
        $requestedType = $request->input('buyer_type');
        $buyerId = $request->input('buyer_id');
        $sellerId = $request->input('seller_id');

        if ($sellerId) {
            $seller = Seller::find($sellerId);
            if ($seller instanceof Seller) {
                return [
                    'buyer_type' => 'seller',
                    'buyer_id' => null,
                    'seller_id' => $seller->id,
                    'buyer_name' => $seller->name ?: '-',
                    'buyer_phone' => $seller->phone,
                    'buyer_address' => $seller->address,
                ];
            }
        }

        if ($buyerId) {
            $customer = Customer::find($buyerId);
            if ($customer instanceof Customer) {
                $type = in_array($requestedType, ['trader', 'customer'], true)
                    ? $requestedType
                    : $this->inferBuyerTypeFromCustomer($customer);

                return array_merge(
                    $this->buyerSnapshotArray($type, $customer),
                    ['seller_id' => null]
                );
            }
        }

        $manualName = trim((string) $request->input('buyer_name', ''));
        if ($manualName !== '' || $request->filled('buyer_phone') || $request->filled('buyer_address')) {
            $type = in_array($requestedType, ['trader', 'customer', 'unknown'], true)
                ? $requestedType
                : 'unknown';

            return [
                'buyer_type' => $type,
                'buyer_id' => null,
                'buyer_name' => $manualName !== '' ? $manualName : '-',
                'buyer_phone' => $request->input('buyer_phone'),
                'buyer_address' => $request->input('buyer_address'),
            ];
        }

        if ($projectId) {
            $project = Project::with('partnership.customer')->find($projectId);
            $customer = $project?->partnership?->customer;
            if ($customer instanceof Customer) {
                $type = ($saleType === 'project')
                    ? 'trader'
                    : (in_array($requestedType, ['trader', 'customer'], true)
                        ? $requestedType
                        : $this->inferBuyerTypeFromCustomer($customer));

                return $this->buyerSnapshotArray($type, $customer);
            }
        }

        if (in_array($requestedType, ['trader', 'customer', 'unknown'], true)) {
            return $this->buyerSnapshotArray($requestedType);
        }

        return $this->buyerSnapshotArray('unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePaymentBoxForStorage(Request $request): array
    {
        if (! $request->filled('payment_box_id')) {
            return ['status' => 'active'];
        }

        $box = Box::find($request->input('payment_box_id'));
        $name = trim((string) $request->input('payment_box_name', ''));
        if ($name === '' && $box) {
            $name = (string) ($box->name ?? '');
        }

        $payload = [
            'payment_box_id' => (int) $request->input('payment_box_id'),
            'payment_box_name' => $name !== '' ? $name : null,
            'status' => 'active',
        ];

        if ($request->filled('payment_box_value')) {
            $payload['payment_box_value'] = (float) $request->input('payment_box_value');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentBoxInvoiceFields(InstantSale $sale): array
    {
        $boxName = $sale->payment_box_name;
        if (($boxName === null || $boxName === '') && $sale->relationLoaded('paymentBox') && $sale->paymentBox) {
            $boxName = $sale->paymentBox->name;
        }

        return [
            'payment_box_id' => $sale->payment_box_id,
            'payment_box_name' => $boxName,
            'payment_box_value' => $sale->payment_box_value,
        ];
    }

  /**
     * @return \Illuminate\Support\Collection<int, InstantSale>
     */
    private function stockLinesForSale(InstantSale $mainSale)
    {
        $lines = collect();

        if ($mainSale->offer_package_id === null && $mainSale->product_id) {
            $lines->push($mainSale);
        }

        foreach ($mainSale->subProducts as $sub) {
            if (! $sub->isCancelled()) {
                $lines->push($sub);
            }
        }

        return $lines;
    }

    private function saleLineQuantity(InstantSale $line): int
    {
        return max(0, (int) round((float) ($line->quantity ?? 0)));
    }

  /**
     * Restore stock for one invoice line only once (exact line quantity).
     */
    private function restoreStockForSaleLine(InstantSale $line): void
    {
        if ($this->saleLineStockAlreadyRestored($line)) {
            return;
        }

        $quantity = $this->saleLineQuantity($line);
        $productId = (int) $line->product_id;

        if ($productId <= 0 || $quantity <= 0) {
            $this->markSaleLineStockRestored($line);

            return;
        }

        $product = Product::withTrashed()->lockForUpdate()->find($productId);
        if (! $product instanceof Product) {
            $this->markSaleLineStockRestored($line);

            return;
        }

        Product::withTrashed()
            ->where('id', $productId)
            ->increment('stock', $quantity);

        $product->refresh();

        if ((float) $product->stock > 0) {
            $closeout = Closeout::where('product_id', $productId)->first();
            if ($closeout && $closeout->status === 'archived') {
                $closeout->update(['status' => 'ongoing']);
            }
        }

        $this->markSaleLineStockRestored($line);
    }

    private function saleLineStockAlreadyRestored(InstantSale $line): bool
    {
        if (Schema::hasColumn('instant_sales', 'stock_restored') && $line->stock_restored) {
            return true;
        }

        return false;
    }

    private function markSaleLineStockRestored(InstantSale $line): void
    {
        if (! Schema::hasColumn('instant_sales', 'stock_restored')) {
            return;
        }

        $line->update(['stock_restored' => true]);
    }

    private function formatQtyNumber(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    private function formatMoneyNumber(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function instantSaleInvoiceLinesSummary(InstantSale $mainSale): string
    {
        $mainSale->loadMissing(['product', 'subProducts.product']);
        $parts = [];

        $mainName = $mainSale->product?->nameAr ?? 'منتج';
        $parts[] = $mainName.' × '.$this->saleLineQuantity($mainSale);

        foreach ($mainSale->subProducts as $sub) {
            if ($sub->isCancelled()) {
                continue;
            }
            $subName = $sub->product?->nameAr ?? 'منتج';
            $parts[] = $subName.' × '.$this->saleLineQuantity($sub);
        }

        return implode(' | ', $parts);
    }

    private function instantSaleBoxLogNote(InstantSale $sale, string $action): string
    {
        $root = $sale->parent_id
            ? (InstantSale::with(['product', 'subProducts.product'])->find($sale->parent_id) ?? $sale)
            : $sale;

        $linesSummary = $this->instantSaleInvoiceLinesSummary($root);
        $amount = (float) ($root->payment_box_value ?? 0);
        $amountLabel = $this->formatMoneyNumber(
            $amount > 0 ? $amount : (float) ($root->total_cost ?? 0)
        );

        return match ($action) {
            'receive' => sprintf(
                'قبض — بيع فوري #%d | %s | مبلغ: %s',
                $root->id,
                $linesSummary,
                $amountLabel
            ),
            'cancel' => sprintf(
                'عكس قبض — إلغاء بيع فوري #%d | %s | مبلغ: %s',
                $root->id,
                $linesSummary,
                $amountLabel
            ),
            'edit_add' => sprintf('تعديل بيع فوري #%d — زيادة مبلغ في الصندوق', $root->id),
            'edit_minus' => sprintf('تعديل بيع فوري #%d — تخفيض مبلغ من الصندوق', $root->id),
            default => sprintf('بيع فوري #%d | %s', $root->id, $linesSummary),
        };
    }

    /**
     * After sale is saved, enrich the payment box log created during receive step.
     */
    private function linkPaymentBoxLogToInstantSale(InstantSale $sale): void
    {
        if (! $sale->payment_box_id) {
            return;
        }

        $amount = (float) ($sale->payment_box_value ?? 0);
        if ($amount <= 0) {
            return;
        }

        $note = $this->instantSaleBoxLogNote($sale, 'receive');
        $description = 'قبض — بيع فوري #'.$sale->id;

        $query = BoxLog::query()
            ->where('box_id', $sale->payment_box_id)
            ->where('created_at', '>=', now()->subMinutes(10));

        if (Schema::hasColumn('box_logs', 'type')) {
            $query->where('type', 'add');
        }

        if (Schema::hasColumn('box_logs', 'value')) {
            $query->where(function ($q) use ($amount) {
                $q->where('value', $amount)
                    ->orWhere('value', (string) $amount);
            });
        }

        $log = $query->orderByDesc('id')->first();

        if ($log) {
            $log->update([
                'description' => $description,
                'note' => $note,
            ]);
        }
    }

    private function reverseBoxForCancelledSale(InstantSale $sale): void
    {
        $boxId = $sale->payment_box_id;
        if (! $boxId && ! empty($sale->payment_box_name)) {
            $boxId = Box::where('name', $sale->payment_box_name)->value('id');
        }

        // Reverse only the amount recorded on the invoice — never total_cost fallback.
        $amount = (float) ($sale->payment_box_value ?? 0);

        if (! $boxId || $amount <= 0) {
            return;
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        $note = $this->instantSaleBoxLogNote($sale, 'cancel');

        if ((float) $box->total < $amount) {
            throw ValidationException::withMessages([
                'instant_sale_id' => [__('messages.box_out_of_money')],
            ]);
        }

        $box->total = (float) $box->total - $amount;
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            'سحب — عكس قبض بيع فوري',
            'minus',
            -$amount,
            $note
        );
    }

    private function markInstantSaleCancelled(InstantSale $sale): void
    {
        $payload = ['cancelled_at' => now()];
        if (Schema::hasColumn('instant_sales', 'status')) {
            $payload['status'] = 'cancelled';
        }
        $sale->update($payload);
    }

    /**
     * Keep only attributes that exist on instant_sales (safe if migrations pending).
     */
    private function sanitizeInstantSaleAttributes(array $data): array
    {
        $fillable = (new InstantSale)->getFillable();
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, $fillable, true)) {
                continue;
            }
            if (! Schema::hasColumn('instant_sales', $key)) {
                if ($key === 'cost' && Schema::hasColumn('instant_sales', 'maintenance_cost')) {
                    $sanitized['maintenance_cost'] = $value;
                }
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @return array{type: string, type_label_ar: string, name: string, phone: ?string, address: ?string, id: int|null}
     */
    private function resolveInvoiceBuyer(InstantSale $sale): array
    {
        $unknown = [
            'type' => 'unknown',
            'type_label_ar' => 'غير محدد',
            'name' => '-',
            'phone' => null,
            'address' => null,
            'id' => null,
        ];

        // A) Persisted snapshot on instant_sales
        if (! empty($sale->buyer_type)) {
            return [
                'type' => $sale->buyer_type,
                'type_label_ar' => $this->buyerTypeLabelAr($sale->buyer_type),
                'name' => $sale->buyer_name ?: '-',
                'phone' => $sale->buyer_phone,
                'address' => $sale->buyer_address,
                'id' => $sale->buyer_id,
            ];
        }

        // B) Legacy: project -> partnership -> customer
        $customer = $sale->project?->partnership?->customer;

        if ($sale->type === 'project' || $sale->project_id) {
            if ($customer instanceof Customer) {
                return [
                    'type' => 'trader',
                    'type_label_ar' => 'تاجر',
                    'name' => $customer->name ?: '-',
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'id' => $customer->id,
                ];
            }

            if ($sale->project) {
                return [
                    'type' => 'trader',
                    'type_label_ar' => 'تاجر',
                    'name' => $sale->project->name ?: '-',
                    'phone' => null,
                    'address' => null,
                    'id' => $sale->project_id,
                ];
            }
        }

        if ($customer instanceof Customer) {
            $isTrader = $this->inferBuyerTypeFromCustomer($customer) === 'trader';

            return [
                'type' => $isTrader ? 'trader' : 'customer',
                'type_label_ar' => $isTrader ? 'تاجر' : 'زبون',
                'name' => $customer->name ?: '-',
                'phone' => $customer->phone,
                'address' => $customer->address,
                'id' => $customer->id,
            ];
        }

        return $unknown;
    }



public function store(Request $request)
 {
    try{
    if ($request->input('project_id') === '' || $request->input('project_id') === '0') {
        $request->merge(['project_id' => null]);
    }

    $data = $request->validate([
        'offer_package_id' => 'nullable|integer|exists:offer_packages,id',
        'product_id' => 'required_without:offer_package_id|nullable|exists:products,id',
        'quantity' => 'required|numeric|min:1',
        'cost' => 'required|numeric|min:0',
        'discount' => 'required|numeric|min:0',
        'total_cost' => 'required|numeric|min:0',

        'notes' => 'nullable|string',

        'type' => 'required|string|in:normal,project',
        'project_id' => 'nullable|exists:projects,id',

        'other_products' => 'nullable|array',
        'other_products.*.product_id' => 'required|exists:products,id',
        'other_products.*.cost' => 'required|numeric|min:0',
        'other_products.*.quantity' => 'required|numeric|min:1',
        'other_products.*.type' => 'required|string|in:normal,project',
        'other_products.*.project_id' => 'nullable|exists:projects,id',

        'buyer_type' => 'nullable|string|in:trader,customer,unknown',
        'buyer_id' => 'nullable|integer|exists:customers,id',
        'buyer_name' => 'nullable|string|max:255',
        'buyer_phone' => 'nullable|string|max:50',
        'buyer_address' => 'nullable|string|max:500',

        'payment_box_id' => 'nullable|integer|exists:boxes,id',
        'payment_box_name' => 'nullable|string|max:255',
        'payment_box_value' => 'nullable|numeric|min:0',
        'seller_id' => 'nullable|integer|exists:sellers,id',

    ]);


        $otherNames = [];


        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $buyerPayload = $this->resolveBuyerForStorage(
            $request,
            $projectId,
            $data['type'] ?? null
        );
        $paymentBoxPayload = $this->resolvePaymentBoxForStorage($request);

        if (! empty($data['offer_package_id'])) {
            return $this->storeOfferPackageSale(
                $request,
                $data,
                $buyerPayload,
                $paymentBoxPayload
            );
        }

        // Save main instant sale
        $mainData = $this->sanitizeInstantSaleAttributes(
            collect($data)
                ->except([
                    'other_products',
                    'buyer_type',
                    'buyer_id',
                    'buyer_name',
                    'buyer_phone',
                    'buyer_address',
                    'payment_box_id',
                    'payment_box_name',
                    'payment_box_value',
                    'seller_id',
                ])
                ->merge($buyerPayload)
                ->merge($paymentBoxPayload)
                ->toArray()
        );

        $mainProduct = Product::findOrFail($mainData['product_id']);

        $mainSaleQuantity = $request->quantity;
        if( ($mainSaleQuantity > $mainProduct->stock) || ($mainProduct->stock <= 0) ){
            return response()->json([
                'status'=>'error',
                'message'=>__('messages.cant_sale'),
            ],200);
        }



        if ($request->has('other_products')) {

        foreach ($data['other_products'] as $item) {
            $product = Product::find($item['product_id']);
            $otherNames[] = $product->nameAr?? 'بدون اسم';
            if (($product->stock <= 0) || ($item['quantity'] > $product->stock)) {
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.cant_sale'),
                    ],200);
            } 
        }
    }
        $productProjects = $mainProduct->projects;


        if($mainData['type']==='project' && $productProjects->isEmpty()){
            return response()->json([
                'status'=>'error',
                'message'=>__('messages.cant_be_project_type'),
            ],200);
        }

        $mainInstantSale = InstantSale::create($mainData);

        $this->linkPaymentBoxLogToInstantSale(
            $mainInstantSale->fresh(['product', 'subProducts.product'])
        );

        app(DebtLedgerService::class)->syncInstantSaleToLedger(
            $mainInstantSale->fresh(['product', 'offerPackage'])
        );

        $mainProduct->stock -= $mainInstantSale->quantity;
        $mainProduct->save();
        if ($mainProduct->stock === 0) {
                $closeout = $mainProduct->closeout;

                if ($closeout) { // check if it exists
                    $closeout->status = 'archived'; 
                    $closeout->save();
                }
            }

        // Save other  if provided
        if ($request->has('other_products')) {
            foreach ($request->other_products as  $product) {
                $subProduct = Product::findOrFail($product['product_id']);
                $subProductProjects = $subProduct->projects;


                if($product['type']==='project' && $subProductProjects->isEmpty()){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.cant_be_project_type'),
                    ],200);
                }        
                $subProjectId = isset($product['project_id']) ? (int) $product['project_id'] : null;

                InstantSale::create($this->sanitizeInstantSaleAttributes(array_merge([
                    'product_id' => $product['product_id'],
                    'cost' => $product['cost'],
                    'quantity' => $product['quantity'],
                    'discount' => 0,
                    'total_cost' => (float) $product['cost'] * (float) $product['quantity'],
                    'parent_id' => $mainInstantSale->id,
                    'type' => $product['type'],
                    'project_id' => $product['project_id'] ?? null,
                ], $buyerPayload)));

                $subProduct->stock -= $product['quantity'];
                $subProduct->save();
                if ($subProduct->stock === 0) {
                        $closeout = $subProduct->closeout;

                         if ($closeout) { // check if it exists
                                    $closeout->status = 'archived'; 
                                    $closeout->save();
                                }
                            }
            }
        }
     $logDescription = "اضافة بيع فوري جديد للمنتج: " . ($mainInstantSale->product->nameAr ?? 'بدون اسم');
     if(count($otherNames)>0){
             $logDescription .= " مع منتجات إضافية: " . implode(", ", $otherNames);

     }
     $logDescription .= " بإجمالي تكلفة: " . $mainInstantSale->total_cost??0;

        Logs::createLog('اضافة بيع فوري جديد',
        $logDescription,
        'instant_sales');
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.instant_sale_created_successfully')
                ], 200);

            }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
            catch (QueryException $e) {
            Log::error('InstantSales::store QueryException', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.create_data_error'),
            ], 200);
        }
        catch (\Exception $e) {
            Log::error('InstantSales::store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }

}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $buyerPayload
     * @param  array<string, mixed>  $paymentBoxPayload
     */
    private function storeOfferPackageSale(
        Request $request,
        array $data,
        array $buyerPayload,
        array $paymentBoxPayload
    ) {
        $offerPackageService = app(OfferPackageService::class);

        return DB::transaction(function () use ($data, $buyerPayload, $paymentBoxPayload, $offerPackageService) {
            $package = OfferPackage::query()
                ->where('is_active', true)
                ->with(['items.product'])
                ->lockForUpdate()
                ->findOrFail((int) $data['offer_package_id']);

            $packagesSold = max(1, (int) round((float) $data['quantity']));

            $maxSellable = $offerPackageService->maxSellableQuantity($package);
            if ($packagesSold > $maxSellable) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cant_sale'),
                ], 200);
            }

            $stockCheck = $offerPackageService->validateStockForSale($package, $packagesSold);
            if (! $stockCheck['ok']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $stockCheck['message'] ?? __('messages.cant_sale'),
                ], 200);
            }

            $unitPrice = (float) $package->price;
            $mainData = $this->sanitizeInstantSaleAttributes(array_merge([
                'offer_package_id' => $package->id,
                'product_id' => null,
                'quantity' => $packagesSold,
                'cost' => $unitPrice,
                'discount' => (float) ($data['discount'] ?? 0),
                'total_cost' => (float) $data['total_cost'],
                'notes' => $data['notes'] ?? null,
                'type' => $data['type'],
                'project_id' => $data['project_id'] ?? null,
            ], $buyerPayload, $paymentBoxPayload));

            $mainInstantSale = InstantSale::create($mainData);

            $this->linkPaymentBoxLogToInstantSale(
                $mainInstantSale->fresh(['offerPackage', 'subProducts.product'])
            );

            app(DebtLedgerService::class)->syncInstantSaleToLedger(
                $mainInstantSale->fresh(['product', 'offerPackage'])
            );

            foreach ($package->items as $item) {
                $lineQty = (int) $item->quantity * $packagesSold;
                $subProduct = Product::findOrFail($item->product_id);

                InstantSale::create($this->sanitizeInstantSaleAttributes(array_merge([
                    'product_id' => $item->product_id,
                    'cost' => 0,
                    'quantity' => $lineQty,
                    'discount' => 0,
                    'total_cost' => 0,
                    'parent_id' => $mainInstantSale->id,
                    'type' => $data['type'],
                    'project_id' => $data['project_id'] ?? null,
                ], $buyerPayload)));

                $subProduct->stock -= $lineQty;
                $subProduct->save();

                if ((float) $subProduct->stock === 0.0) {
                    $closeout = $subProduct->closeout;
                    if ($closeout) {
                        $closeout->status = 'archived';
                        $closeout->save();
                    }
                }
            }

            $offerPackageService->decrementPackageQuantity($package, $packagesSold);

            $logDescription = 'اضافة بيع فوري لباكيج عرض: '.$package->name
                .' (×'.$packagesSold.') بإجمالي تكلفة: '.($mainInstantSale->total_cost ?? 0);

            Logs::createLog('اضافة بيع فوري جديد', $logDescription, 'instant_sales');

            return response()->json([
                'status' => 'success',
                'message' => __('messages.instant_sale_created_successfully'),
            ], 200);
        });
    }

    // get the projects of a product for chosing that product in the instant sale
    public function getProjectsOfProduct(Request $request){
        try{
            $request->validate(['product_id'=>'required|exists:products,id']);

            $product = Product::findOrFail($request->product_id);
            $productProjects = $product->projects ;
            $projects = $productProjects->map(function($productProject){
                return [
                    'project_id' => $productProject->project->id,
                    'project_name' => $productProject->project->name,
                ];
            });
            return response()->json([
                'status'=>'success',
                'projects' => $projects,
            ]);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    // get sub sales of parent sale
    public function getSubSales(Request $request){
      try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $subSales =  $sale->subProducts->map(function ($sub) {
                    return [
                        'id' => $sub->id,
                        'product_id' => $sub->product->nameAr,
                        'cost' => $sub->cost,
                        'quantity'=> $sub->quantity,

                    ];
                });
         return response()->json([
            'status'=>'success',
            'sub_sales'=> $subSales,
         ],200);
    }
            catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
    public function attachProjectToProductInSale(Request $request){
       try{
            $request->validate([
                'instant_sale_id'=>'required|exists:instant_sales,id',
                'project_id' => 'required|exists:projects,id',
            ]);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $sale->update(['project_id'=> $request->project_id]);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.sale_attached'),
            ]);

    }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
    public function getInstantSales(Request $request)
{
    try {
            $request->validate([
                'search' => 'nullable|string|max:255',
                'sort_direction' => 'nullable|string|in:asc,desc',
            ]);

            $search = trim((string) $request->input('search', ''));
            $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc'
                ? 'asc'
                : 'desc';

            $query = InstantSale::query()
                ->whereNull('parent_id')
            ->with([
                'product:id,nameAr',
                    'offerPackage:id,name',
                    'project:id,name',
                'subProducts.product:id,nameAr',
                ]);

            if ($search !== '') {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term, $search) {
                    $q->where('buyer_name', 'like', $term)
                        ->orWhere('buyer_phone', 'like', $term)
                        ->orWhere('buyer_address', 'like', $term)
                        ->orWhere('notes', 'like', $term)
                        ->orWhereHas('product', function ($productQuery) use ($term) {
                            $productQuery->where('nameAr', 'like', $term);
                        })
                        ->orWhereHas('project', function ($projectQuery) use ($term) {
                            $projectQuery->where('name', 'like', $term);
                        })
                        ->orWhereHas('subProducts.product', function ($subProductQuery) use ($term) {
                            $subProductQuery->where('nameAr', 'like', $term);
                        })
                        ->orWhereHas('offerPackage', function ($packageQuery) use ($term) {
                            $packageQuery->where('name', 'like', $term);
                        });

                    if (ctype_digit($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            }

            $instantSales = $query
                ->orderBy('created_at', $sortDirection)
                ->orderBy('id', $sortDirection)
            ->get();

        $formatted = $instantSales->map(function ($sale) {
                $buyerLabel = $this->buyerTypeLabelAr($sale->buyer_type ?? 'unknown');
                $isPackageSale = $sale->offer_package_id !== null;
                $packageName = $sale->offerPackage?->name;

            return [
                'id' => $sale->id,
                'sale_type' => $isPackageSale ? 'package' : 'product',
                'is_package_sale' => $isPackageSale,
                'offer_package_id' => $sale->offer_package_id,
                'package_name' => $packageName,
                'product' => $isPackageSale
                    ? ($packageName ?? 'باكيج محذوف')
                    : (optional($sale->product)->nameAr ?? 'منتج محذوف'),
                'cost' => $sale->cost,
                'total_cost' => $sale->total_cost,
                'quantity' => $sale->quantity,
                'notes' => $sale->notes,
                'date' => optional($sale->created_at)->format('Y-m-d'),
                    'created_at' => optional($sale->created_at)->format('Y-m-d H:i:s'),
                    'buyer_type' => $sale->buyer_type,
                    'buyer_type_label_ar' => $buyerLabel,
                    'buyer_id' => $sale->buyer_id,
                    'buyer_name' => $sale->buyer_name,
                    'buyer_phone' => $sale->buyer_phone,
                    'buyer_address' => $sale->buyer_address,
                    'project_name' => $sale->project?->name,
                    'status' => $sale->status ?? 'active',
                    'cancelled_at' => optional($sale->cancelled_at)->format('Y-m-d H:i:s'),
                    'payment_box_id' => $sale->payment_box_id,
                    'payment_box_name' => $sale->payment_box_name,
                    'payment_box_value' => $sale->payment_box_value,
                'sub_products' => $sale->subProducts->map(function ($sub) {
                    return [
                        'id' => $sub->id,
                        'product_name' => optional($sub->product)->nameAr ?? 'منتج محذوف',
                        'cost' => $sub->cost,
                        'quantity' => $sub->quantity,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'instant_sales' => $formatted,
                'sort_direction' => $sortDirection,
        ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
    } catch (\Throwable $e) {
        \Log::error('getInstantSales error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
            'debug' => config('app.debug') ? $e->getMessage() : null,
        ], 200);
    }
}

    public function showInstantSale(Request $request){
        try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);
            $instantSale = InstantSale::findOrFail($request->instant_sale_id)
            ->with('product:id,nameAr');

            return response()->json([
                'status'=>'success',
                'instant_sale_details' => $instantSale,
            ],200);
    }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
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

    public function edit(Request $request)
    {
        try {
            $data = $request->validate([
                'instant_sale_id' => 'required|exists:instant_sales,id',
            'cost' => 'required|numeric|min:0',
                'quantity' => 'required|numeric|min:1',
            'total_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            ]);

            DB::transaction(function () use ($request, $data) {
                $instantSale = InstantSale::query()
                    ->whereNull('parent_id')
                    ->with(['product', 'subProducts.product'])
                    ->lockForUpdate()
                    ->findOrFail($request->instant_sale_id);

                if ($instantSale->isCancelled()) {
                    throw ValidationException::withMessages([
                        'instant_sale_id' => [__('messages.instant_sale_already_cancelled')],
                    ]);
                }

                $oldQuantity = (float) $instantSale->quantity;
                $newQuantity = (float) $data['quantity'];
                $quantityDelta = $newQuantity - $oldQuantity;

                if ($quantityDelta > 0) {
                    $product = $instantSale->product ?? Product::findOrFail($instantSale->product_id);
                    if ($product->stock < $quantityDelta) {
                        throw ValidationException::withMessages([
                            'quantity' => [__('messages.cant_sale')],
                        ]);
                    }
                    $product->stock -= $quantityDelta;
                    $product->save();
                } elseif ($quantityDelta < 0) {
                    $product = $instantSale->product ?? Product::findOrFail($instantSale->product_id);
                    $product->stock += abs($quantityDelta);
                    $product->save();
                }

                $oldTotal = (float) $instantSale->total_cost;
                $newTotal = (float) $data['total_cost'];
                $totalDelta = $newTotal - $oldTotal;

                if (abs($totalDelta) > 0.0001 && $instantSale->payment_box_id) {
                    $box = Box::lockForUpdate()->findOrFail($instantSale->payment_box_id);

                    if ($totalDelta < 0 && (float) $box->total < abs($totalDelta)) {
                        throw ValidationException::withMessages([
                            'total_cost' => [__('messages.box_out_of_money')],
                        ]);
                    }

                    $box->total = (float) $box->total + $totalDelta;
                    $box->save();
                    $editAction = $totalDelta >= 0 ? 'edit_add' : 'edit_minus';
                    BoxLogs::createBoxLog(
                        $box,
                        $totalDelta >= 0 ? 'إضافة — تعديل بيع فوري' : 'سحب — تعديل بيع فوري',
                        $totalDelta >= 0 ? 'add' : 'minus',
                        $totalDelta,
                        $this->instantSaleBoxLogNote($instantSale, $editAction)
                    );
                    $data['payment_box_value'] = max(
                        0,
                        (float) ($instantSale->payment_box_value ?? 0) + $totalDelta
                    );
                }

                $instantSale->update(collect($data)->except(['instant_sale_id'])->toArray());
                Logs::createLog('تعديل بيع فوري', 'تم تعديل بيع فوري #'.$instantSale->id, 'instant_sales');
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.instant_sale_updated_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::edit error', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function cancel(Request $request)
    {
        try {
            $request->validate([
                'instant_sale_id' => 'required|integer|exists:instant_sales,id',
            ]);

            DB::transaction(function () use ($request) {
                $sale = InstantSale::query()
                    ->whereNull('parent_id')
                    ->with(['product', 'subProducts.product'])
                    ->lockForUpdate()
                    ->findOrFail($request->instant_sale_id);

                if ($sale->isCancelled()) {
                    throw ValidationException::withMessages([
                        'instant_sale_id' => [__('messages.instant_sale_already_cancelled')],
                    ]);
                }

                foreach ($this->stockLinesForSale($sale) as $line) {
                    $this->restoreStockForSaleLine($line);
                }

                if ($sale->offer_package_id) {
                    $package = OfferPackage::query()
                        ->lockForUpdate()
                        ->find($sale->offer_package_id);
                    if ($package) {
                        app(OfferPackageService::class)->restorePackageQuantity(
                            $package,
                            $this->saleLineQuantity($sale)
                        );
                    }
                }

                foreach ($sale->subProducts as $sub) {
                    if (! $sub->isCancelled()) {
                        $this->markInstantSaleCancelled($sub);
                    }
                }

                $this->reverseBoxForCancelledSale($sale);
                $this->markInstantSaleCancelled($sale);
                app(DebtLedgerService::class)->archiveInstantSaleLedger($sale);

                Logs::createLog(
                    'إلغاء بيع فوري',
                    'تم إلغاء بيع فوري #'.$sale->id.' واسترجاع المخزون',
                    'instant_sales'
                );
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.instant_sale_cancelled_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            Log::error('InstantSales::cancel error', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function invoiceDetails(Request $request)
    {
        try {
            $request->validate(['instant_sale_id' => 'required|integer|exists:instant_sales,id']);

            $sale = InstantSale::query()
                ->with([
                    'product.viewImages',
                    'product.normalImages',
                    'offerPackage',
                    'subProducts.product.viewImages',
                    'subProducts.product.normalImages',
                    'project.partnership.customer',
                    'paymentBox:id,name',
                ])
                ->findOrFail($request->instant_sale_id);

            $buyer = $this->resolveInvoiceBuyer($sale);
            $subtotalBeforeDiscount = (float) $sale->cost * (float) $sale->quantity;
            $discount = (float) ($sale->discount ?? 0);
            $totalCost = (float) $sale->total_cost;
            $isPackageSale = $sale->offer_package_id !== null;
            $displayName = $isPackageSale
                ? ($sale->offerPackage?->name ?? 'باكيج محذوف')
                : ($sale->product?->nameAr ?? '-');

            $formatted = [
                'id' => $sale->id,
                'invoice_number' => (string) $sale->id,
                'invoice_date' => optional($sale->created_at)->format('Y-m-d H:i:s'),
                'sale_type' => $isPackageSale ? 'package' : 'product',
                'is_package_sale' => $isPackageSale,
                'package_name' => $sale->offerPackage?->name,
                'product' => $displayName,
                'product_image' => $isPackageSale
                    ? app(OfferPackageService::class)->imagePublicPath($sale->offerPackage?->image_path)
                    : $this->invoiceProductImage($sale->product),
                'cost' => $sale->cost,
                'quantity' => $sale->quantity,
                'subtotal' => $subtotalBeforeDiscount,
                'total_cost' => $totalCost,
                'discount' => $discount,
                'tax' => 0,
                'paid_amount' => $totalCost,
                'remaining_amount' => 0,
                'sale_status' => $sale->type ?? 'normal',
                'payment_method' => $sale->project?->payment_method,
                'notes' => $sale->notes,
                'buyer' => $buyer,
                'trader_name' => $buyer['type'] === 'trader' ? $buyer['name'] : null,
                'customer_name' => $buyer['type'] === 'customer' ? $buyer['name'] : ($sale->project?->partnership?->customer?->name),
                'phone' => $buyer['phone'],
                'address' => $buyer['address'],
                'project_name' => $sale->project?->name,
                'status' => $sale->status ?? 'active',
                'cancelled_at' => optional($sale->cancelled_at)->format('Y-m-d H:i:s'),
                ...$this->paymentBoxInvoiceFields($sale),
                'sub_products' => $sale->subProducts->map(function ($sub) use ($isPackageSale) {
                    $lineSubtotal = (float) $sub->cost * (float) $sub->quantity;

                    return [
                        'id' => $sub->id,
                        'product_name' => $sub->product?->nameAr ?? '-',
                        'product_image' => $this->invoiceProductImage($sub->product),
                        'cost' => $sub->cost,
                        'quantity' => $sub->quantity,
                        'subtotal' => $lineSubtotal,
                        'is_package_component' => $isPackageSale,
                    ];
                })->values(),
             ];

             return response()->json([
                'status' => 'success',
                'instant_sale_invoice' => $formatted,
            ], 200);
        }
         catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),

            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
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