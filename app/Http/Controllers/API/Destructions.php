<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Destruction;
use App\Http\Resources\DestructionResource;
use App\Models\Product;
use App\Models\Expense;
use App\Models\InventoryCostLayer;
use App\Models\ProductStockMovement;
use App\Services\InventoryCostingService;
use App\Services\ProductStockService;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
class Destructions extends Controller
{
    public function storeBatch(Request $request)
    {
        try {
            $rawItems = $request->input('items');
            $items = is_string($rawItems) ? json_decode($rawItems, true) : $rawItems;
            $validated = Validator::make(
                ['items' => $items, 'destruction_reason' => $request->input('destruction_reason')],
                [
                    'items' => 'required|array|min:1|max:100',
                    'items.*.product_id' => 'required|integer|exists:products,id',
                    'items.*.pieces_number' => 'required|integer|min:1',
                    'items.*.cost_layer_id' => 'nullable|integer|exists:inventory_cost_layers,id',
                    'destruction_reason' => 'nullable|string',
                ]
            )->validate();
            $request->validate([
                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
            ]);
            $files = $this->fileStorage($request);

            $created = DB::transaction(function () use ($validated, $files, $request) {
                $rows = [];
                foreach ($validated['items'] as $item) {
                    $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);
                    $rows[] = $this->createAccountingLine(
                        product: $product,
                        quantity: (int) $item['pieces_number'],
                        reason: $validated['destruction_reason'] ?? null,
                        media: $files,
                        userId: $request->user()?->id,
                        costLayerId: isset($item['cost_layer_id']) ? (int) $item['cost_layer_id'] : null,
                    );
                }
                return $rows;
            }, 3);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.destruction_created'),
                'created_count' => count($created),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: __('messages.something_wrong')], 200);
        }
    }

    private function createAccountingLine(Product $product, int $quantity, ?string $reason, array $media, ?int $userId, ?int $costLayerId): Destruction
    {
        if ($quantity < 1 || (int) $product->stock < $quantity) {
            throw ValidationException::withMessages(['items' => [__('messages.stcok_failed')]]);
        }
        $destruction = Destruction::create([
            'product_id' => $product->id,
            'pieces_number' => $quantity,
            'destruction_reason' => $reason,
            'media' => $media,
            'created_by_user_id' => $userId,
        ]);
        $hasLayers = Schema::hasTable('inventory_cost_layers')
            && Schema::hasTable('inventory_cost_allocations')
            && (float) InventoryCostLayer::query()->where('product_id', $product->id)->where('remaining_quantity', '>', 0)->sum('remaining_quantity') >= $quantity;

        if ($costLayerId) {
            $layer = InventoryCostLayer::query()->findOrFail($costLayerId);
            $cost = app(InventoryCostingService::class)->consumeOwnedStockFromLayer(
                $product, $layer, $quantity, ProductStockMovement::TYPE_DESTRUCTION,
                'destruction', $destruction->id, $userId, $reason
            );
        } elseif ($hasLayers) {
            $cost = app(InventoryCostingService::class)->consumeOwnedStock(
                $product, $quantity, ProductStockMovement::TYPE_DESTRUCTION,
                'destruction', $destruction->id, userId: $userId, note: $reason
            );
        } else {
            $unitCost = (float) ($product->price ?? 0);
            $cost = ['method' => 'legacy_product_price_estimate', 'unit_cost' => $unitCost, 'total_cost' => $unitCost * $quantity];
            app(ProductStockService::class)->adjustStock(
                $product, -$quantity, ProductStockMovement::TYPE_DESTRUCTION,
                referenceType: 'destruction', referenceId: $destruction->id,
                note: $reason, userId: $userId, unitCost: $unitCost, totalCost: $cost['total_cost']
            );
        }
        $destruction->update([
            'cost_method' => $cost['method'],
            'unit_cost' => round((float) $cost['unit_cost'], 6),
            'total_cost' => round((float) $cost['total_cost'], 6),
        ]);
        Expense::create([
            'name' => 'إتلاف بضاعة - '.($product->nameAr ?: $product->nameEng),
            'price' => round((float) $cost['total_cost'], 2),
            'expense_type' => 'destruction',
            'expense_date' => now()->toDateString(),
            'notes' => $reason,
            'destruction_id' => $destruction->id,
            'created_by_user_id' => $userId,
            'media' => $media,
            'invoice_img' => [],
        ]);
        return $destruction;
    }
    public function costLayers(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $layers = InventoryCostLayer::query()
            ->where('product_id', $data['product_id'])
            ->where('remaining_quantity', '>', 0)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InventoryCostLayer $layer) => [
                'id' => $layer->id,
                'unit_cost' => (float) $layer->unit_cost,
                'remaining_quantity' => (float) $layer->remaining_quantity,
                'currency' => $layer->currency,
                'source_type' => $layer->source_type,
                'effective_at' => $layer->effective_at?->toIso8601String(),
            ]);

        return response()->json(['status' => 'success', 'layers' => $layers]);
    }


    private $destructionMediaPath = 'DestructionsMedia';

    private function fileStorage(Request $request)
    {
        $files = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';

                $destinationPath = public_path($this->destructionMediaPath . '/' . $folder);

                $extension = strtolower($file->getClientOriginalExtension());
                $fullName = (string) Str::uuid().($extension ? '.'.$extension : '');
                $file->move($destinationPath, $fullName);
                $files[] = $fullName;
            }
        }
        return $files;
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => 'required|exists:products,id',
                'pieces_number' => 'required|integer|min:1',
                'destruction_reason' => 'nullable|string',
                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
                'cost_layer_id' => 'nullable|integer|exists:inventory_cost_layers,id',
            ]);

            $files = $this->fileStorage($request);
            $data['media'] = $files;
            $product = Product::findOrFail($request->product_id);
            if( ($product->stock <= 0) || ($product->stock < $request->pieces_number)){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.stcok_failed'),
                ],200);
            }
            $destruction = DB::transaction(function () use ($data, $product, $request) {
                $destruction = Destruction::create(array_merge($data, [
                    'created_by_user_id' => $request->user()?->id,
                ]));

                $quantity = (int) $request->pieces_number;
                $hasCostLayers = Schema::hasTable('inventory_cost_layers')
                    && Schema::hasTable('inventory_cost_allocations')
                    && (float) InventoryCostLayer::query()
                        ->where('product_id', $product->id)
                        ->where('remaining_quantity', '>', 0)
                        ->sum('remaining_quantity') >= $quantity;

                if (! empty($data['cost_layer_id'])) {
                    $selectedLayer = InventoryCostLayer::query()->findOrFail($data['cost_layer_id']);
                    $cost = app(InventoryCostingService::class)->consumeOwnedStockFromLayer(
                        product: $product,
                        selectedLayer: $selectedLayer,
                        quantity: $quantity,
                        movementType: ProductStockMovement::TYPE_DESTRUCTION,
                        referenceType: 'destruction',
                        referenceId: $destruction->id,
                        userId: $request->user()?->id,
                        note: $request->destruction_reason,
                    );
                } elseif ($hasCostLayers) {
                    $cost = app(InventoryCostingService::class)->consumeOwnedStock(
                        product: $product,
                        quantity: $quantity,
                        movementType: ProductStockMovement::TYPE_DESTRUCTION,
                        referenceType: 'destruction',
                        referenceId: $destruction->id,
                        userId: $request->user()?->id,
                        note: $request->destruction_reason,
                    );
                } else {
                    $unitCost = (float) ($product->price ?? 0);
                    $cost = [
                        'method' => 'legacy_product_price_estimate',
                        'unit_cost' => $unitCost,
                        'total_cost' => $unitCost * $quantity,
                    ];
                    app(ProductStockService::class)->adjustStock(
                        product: $product,
                        quantityDelta: -$quantity,
                        type: ProductStockMovement::TYPE_DESTRUCTION,
                        referenceType: 'destruction',
                        referenceId: $destruction->id,
                        note: $request->destruction_reason,
                        userId: $request->user()?->id,
                        unitCost: $cost['unit_cost'],
                        totalCost: $cost['total_cost'],
                    );
                }

                $destruction->update([
                    'cost_method' => $cost['method'],
                    'unit_cost' => round((float) $cost['unit_cost'], 6),
                    'total_cost' => round((float) $cost['total_cost'], 6),
                ]);

                Expense::create([
                    'name' => 'إتلاف بضاعة - '.($product->nameAr ?: $product->nameEng),
                    'price' => round((float) $cost['total_cost'], 2),
                    'expense_type' => 'destruction',
                    'expense_date' => now()->toDateString(),
                    'notes' => $request->destruction_reason,
                    'destruction_id' => $destruction->id,
                    'created_by_user_id' => $request->user()?->id,
                    'media' => $data['media'] ?? [],
                    'invoice_img' => [],
                ]);

                return $destruction;
            });

            Logs::createLog(
                'اضافة اتلاف بضاعة جديد',
                'تم اضافة اتلاف البضاعة ' . ($destruction->product->nameAr ? $destruction->product->nameAr : 'غير معروف') . ' بنجاح',
                'destructions'
            );


            return response()->json([
                'status' => 'success',
                'message' => __('messages.destruction_created'),
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
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function getDestructions()
    {
        try {
            $destructions = Destruction::query()->latest('id')->get();
            $formatted = DestructionResource::collection($destructions);

            return response()->json([
                'status' => 'success',
                'destructions' => $formatted,
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 500);
        }

            catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function showDestruction(Request $request){
        try{
            $request->validate(['destruction_id'=>'required|integer|exists:destructions,id']);

            $destruction = Destruction::with('product:id,nameAr')
            ->findOrFail($request->destruction_id);

            $media = [];
            if($destruction->media && count($destruction->media)>0){
                foreach($destruction->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $type = null;
                        if(in_array($extension,['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])){
                            $type='images';
                        }
                        elseif(in_array($extension,['mp4','quicktime','x-msvideo','x-ms-wmv','x-matroska','webm'])){
                            $type='videos';
                        }
                    $media[] = 'public/DestructionsMedia/'.$type.'/'.$file;
                }
            }
            $destruction->makeHidden('product_id');
            $destruction['media'] = $media;

            return response()->json([
                'status'=>'success',
                'destruction' => $destruction,
            ],200);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
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

    public function editDestruction(Request $request)
    {
        try {
            $data = $request->validate([
                'destruction_id' => 'required|integer|exists:destructions,id',
                'destruction_reason' => 'nullable|string',
                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
            ]);
            $destruction = Destruction::query()->findOrFail($data['destruction_id']);
            $newFiles = $this->fileStorage($request);
            $media = array_values(array_unique(array_merge($destruction->media ?? [], $newFiles)));

            DB::transaction(function () use ($destruction, $data, $media) {
                $destruction->update([
                    'destruction_reason' => $data['destruction_reason'] ?? null,
                    'media' => $media,
                ]);
                $destruction->expense?->update([
                    'notes' => $data['destruction_reason'] ?? null,
                    'media' => $media,
                ]);
            });

            return response()->json(['status' => 'success', 'message' => 'تم تعديل تفاصيل الإتلاف بنجاح.']);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed'), 'errors' => $e->errors()], 200);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
}
