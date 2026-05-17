<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OfferPackage;
use App\Services\OfferPackageService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OfferPackageController extends Controller
{
    public function __construct(
        private readonly OfferPackageService $offerPackageService
    ) {}

    public function index(Request $request)
    {
        try {
            $tab = $request->string('tab', 'active')->toString();

            $packages = OfferPackage::query()
                ->with(['items.product'])
                ->orderByDesc('updated_at')
                ->get();

            $formatted = $packages
                ->map(fn (OfferPackage $p) => $this->offerPackageService->formatPackage($p))
                ->filter(function (array $row) use ($tab) {
                    if ($tab === 'needs_adjustment') {
                        return $row['needs_adjustment'] === true;
                    }

                    return $row['needs_adjustment'] === false;
                })
                ->values();

            return response()->json([
                'status' => 'success',
                'packages' => $formatted,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function forSale()
    {
        try {
            $packages = OfferPackage::query()
                ->where('is_active', true)
                ->with(['items.product'])
                ->orderBy('name')
                ->get()
                ->filter(fn (OfferPackage $p) => ! $this->offerPackageService->needsAdjustment($p))
                ->map(fn (OfferPackage $p) => $this->offerPackageService->formatPackage($p, true))
                ->values();

            return response()->json([
                'status' => 'success',
                'packages' => $packages,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function show(Request $request)
    {
        try {
            $request->validate([
                'offer_package_id' => ['required', 'integer', 'exists:offer_packages,id'],
            ]);

            $package = OfferPackage::query()
                ->with(['items.product.normalImages', 'items.product.viewImages'])
                ->findOrFail($request->integer('offer_package_id'));

            return response()->json([
                'status' => 'success',
                'package' => $this->offerPackageService->formatPackage($package),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function normalizeItemsInput(Request $request): void
    {
        $items = $request->input('items');
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }
    }

    public function store(Request $request)
    {
        try {
            $this->normalizeItemsInput($request);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'price' => ['required', 'numeric', 'min:0'],
                'package_quantity' => ['nullable', 'integer', 'min:1'],
                'image' => ['nullable', 'image', 'max:5120'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
            ]);

            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $this->offerPackageService->storeUploadedImage($request->file('image'));
            }

            $package = OfferPackage::query()->create([
                'name' => $data['name'],
                'price' => $data['price'],
                'package_quantity' => max(1, (int) ($data['package_quantity'] ?? 1)),
                'image_path' => $imagePath,
                'is_active' => true,
            ]);

            $this->offerPackageService->syncItems($package, $data['items']);

            Logs::createLog(
                'إضافة باكيج عرض',
                'تم إضافة باكيج عرض: '.$package->name,
                'offer_packages'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.offer_package_created'),
                'package' => $this->offerPackageService->formatPackage($package->fresh(['items.product'])),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            \Illuminate\Support\Facades\Log::error('OfferPackageController::store', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.create_data_error'),
            ], 200);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('OfferPackageController::store', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $this->normalizeItemsInput($request);

            $data = $request->validate([
                'offer_package_id' => ['required', 'integer', 'exists:offer_packages,id'],
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'price' => ['sometimes', 'required', 'numeric', 'min:0'],
                'package_quantity' => ['sometimes', 'integer', 'min:1'],
                'image' => ['nullable', 'image', 'max:5120'],
                'remove_image' => ['nullable', 'boolean'],
                'items' => ['sometimes', 'required', 'array', 'min:1'],
                'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
                'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            ]);

            $package = OfferPackage::query()->findOrFail($data['offer_package_id']);

            $updates = [];
            if (array_key_exists('name', $data)) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('price', $data)) {
                $updates['price'] = $data['price'];
            }
            if (array_key_exists('package_quantity', $data)) {
                $updates['package_quantity'] = (int) $data['package_quantity'];
            }

            if ($request->boolean('remove_image') && $package->image_path) {
                $this->offerPackageService->deleteImageFile($package->image_path);
                $updates['image_path'] = null;
            }

            if ($request->hasFile('image')) {
                $this->offerPackageService->deleteImageFile($package->image_path);
                $updates['image_path'] = $this->offerPackageService->storeUploadedImage($request->file('image'));
            }

            if ($updates !== []) {
                $package->update($updates);
            }

            if (array_key_exists('items', $data)) {
                $this->offerPackageService->syncItems($package, $data['items']);
            }

            Logs::createLog(
                'تعديل باكيج عرض',
                'تم تعديل باكيج عرض: '.$package->name,
                'offer_packages'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.offer_package_updated'),
                'package' => $this->offerPackageService->formatPackage($package->fresh(['items.product'])),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $request->validate([
                'offer_package_id' => ['required', 'integer', 'exists:offer_packages,id'],
            ]);

            $package = OfferPackage::query()->findOrFail($request->integer('offer_package_id'));
            $name = $package->name;

            $this->offerPackageService->deleteImageFile($package->image_path);
            $package->delete();

            Logs::createLog(
                'حذف باكيج عرض',
                'تم حذف باكيج عرض: '.$name,
                'offer_packages'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.offer_package_deleted'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
