<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreSection;
use App\Support\ApiImageUrl;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreSectionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $includeInactive = $request->boolean('include_inactive');
            $q = StoreSection::query()
                ->orderBy('sort_order')
                ->orderBy('name');
            if (! $includeInactive) {
                $q->where('is_active', true);
            }

            return response()->json([
                'status' => 'success',
                'sections' => $q->get(['id', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            ]);
            $section = StoreSection::query()->create([
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => true,
            ]);

            return response()->json([
                'status' => 'success',
                'section' => $section->only(['id', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at']),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $data = $request->validate([
                'section_id' => ['required', 'integer', 'exists:store_sections,id'],
                'name' => ['sometimes', 'required', 'string', 'max:120'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ]);
            $section = StoreSection::query()->findOrFail($data['section_id']);
            $updates = [];
            if (array_key_exists('name', $data)) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('sort_order', $data)) {
                $updates['sort_order'] = $data['sort_order'];
            }
            if ($updates !== []) {
                $section->update($updates);
            }

            return response()->json([
                'status' => 'success',
                'section' => $section->fresh()->only(['id', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at']),
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

    public function deactivate(Request $request)
    {
        try {
            $request->validate([
                'section_id' => ['required', 'integer', 'exists:store_sections,id'],
            ]);
            $section = StoreSection::query()->findOrFail($request->integer('section_id'));
            $section->update(['is_active' => false]);

            return response()->json([
                'status' => 'success',
                'message' => 'OK',
                'section' => $section->only(['id', 'name', 'sort_order', 'is_active']),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function shelves(Request $request)
    {
        try {
            $request->validate([
                'section_id' => ['required', 'integer', 'exists:store_sections,id'],
            ]);

            $shelves = Product::query()
                ->where('store_section_id', (int) $request->input('section_id'))
                ->whereNotNull('shelf_number')
                ->where('shelf_number', '!=', '')
                ->distinct()
                ->orderBy('shelf_number')
                ->pluck('shelf_number')
                ->values();

            return response()->json([
                'status' => 'success',
                'shelves' => $shelves,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function productsByLocation(Request $request)
    {
        try {
            $request->validate([
                'section_id' => ['required', 'integer', 'exists:store_sections,id'],
                'shelf_number' => ['nullable', 'string', 'max:30'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $sectionId = (int) $request->input('section_id');
            $section = StoreSection::query()->findOrFail($sectionId);

            $query = Product::query()
                ->where('store_section_id', $sectionId)
                ->with(['viewImages', 'normalImages', 'storeSection:id,name'])
                ->select('id', 'nameAr', 'stock', 'product_code', 'store_section_id', 'shelf_number');

            if ($request->filled('shelf_number')) {
                $query->where('shelf_number', $request->string('shelf_number'));
            }

            $products = $query
                ->orderBy('shelf_number')
                ->orderBy('nameAr')
                ->paginate(15, ['*'], 'page', (int) $request->input('page', 1));

            $formatted = $products->getCollection()->map(function (Product $product) {
                $image = $product->viewImages->first() ?? $product->normalImages->first();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_code' => $product->product_code,
                    'product_image' => $image
                        ? ApiImageUrl::normalize((string) $image->imageUrl)
                        : 'no image',
                    'store_section_id' => $product->store_section_id,
                    'store_section_name' => $product->storeSection?->name,
                    'shelf_number' => $product->shelf_number,
                ];
            });

            return response()->json([
                'status' => 'success',
                'section' => $section->only(['id', 'name', 'sort_order', 'is_active']),
                'products' => $formatted,
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'next_page_url' => $products->nextPageUrl(),
                    'prev_page_url' => $products->previousPageUrl(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
