<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreSection;
use App\Support\ProductImageResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreSectionController extends Controller
{
    private function sectionFields(): array
    {
        return ['id', 'name', 'description', 'sort_order', 'is_active', 'created_at', 'updated_at'];
    }

    public function index(Request $request)
    {
        try {
            $includeInactive = $request->boolean('include_inactive');
            $q = StoreSection::query()
                ->withCount(['products'])
                ->orderBy('sort_order')
                ->orderBy('name');
            if (! $includeInactive) {
                $q->where('is_active', true);
            }

            $sections = $q->get($this->sectionFields())
                ->map(function (StoreSection $section) {
                    $row = $section->only($this->sectionFields());
                    $row['product_count'] = (int) $section->products_count;

                    return $row;
                })
                ->values();

            return response()->json([
                'status' => 'success',
                'sections' => $sections,
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
                'description' => ['nullable', 'string', 'max:2000'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            ]);
            $section = StoreSection::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => true,
            ]);

            return response()->json([
                'status' => 'success',
                'section' => $section->only($this->sectionFields()),
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
                'description' => ['nullable', 'string', 'max:2000'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ]);
            $section = StoreSection::query()->findOrFail($data['section_id']);
            $updates = [];
            if (array_key_exists('name', $data)) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $updates['description'] = $data['description'];
            }
            if (array_key_exists('sort_order', $data)) {
                $updates['sort_order'] = $data['sort_order'];
            }
            if ($updates !== []) {
                $section->update($updates);
            }

            return response()->json([
                'status' => 'success',
                'section' => $section->fresh()->only($this->sectionFields()),
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
                'section' => $section->only(['id', 'name', 'description', 'sort_order', 'is_active']),
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

    public function destroy(Request $request)
    {
        try {
            $request->validate([
                'section_id' => ['required', 'integer', 'exists:store_sections,id'],
            ]);
            $sectionId = $request->integer('section_id');
            Product::query()
                ->where('store_section_id', $sectionId)
                ->update(['store_section_id' => null]);
            StoreSection::query()->whereKey($sectionId)->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'OK',
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
                'section_id' => ['required'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:40'],
            ]);

            $sectionIdRaw = $request->input('section_id');
            $withoutLocation = in_array((string) $sectionIdRaw, ['none', 'null', '0'], true);

            if (! $withoutLocation) {
                $request->validate([
                    'section_id' => ['integer', 'exists:store_sections,id'],
                ]);
            }

            $sectionId = $withoutLocation ? null : (int) $sectionIdRaw;
            $section = $withoutLocation
                ? null
                : StoreSection::query()->findOrFail($sectionId);

            $productsQuery = Product::query()
                ->with([
                    'viewImages',
                    'normalImages',
                    'image3d',
                    'storeSection:id,name',
                    'purchasePrices' => fn ($q) => $q->latest('id'),
                ])
                ->select(
                    'id',
                    'nameAr',
                    'stock',
                    'min_stock',
                    'product_code',
                    'store_section_id',
                    'normailPrice',
                    'wholesalePrice',
                    'price',
                    'min_sale_price',
                    'discount',
                    'rotation_date'
                )
                ->orderBy('nameAr');

            if ($withoutLocation) {
                $productsQuery->whereNull('store_section_id');
            } else {
                $productsQuery->where('store_section_id', $sectionId);
            }

            $products = $productsQuery
                ->paginate(
                    min(max((int) $request->input('per_page', 15), 1), 40),
                    ['*'],
                    'page',
                    (int) $request->input('page', 1)
                );

            $formatted = $products->getCollection()->map(function (Product $product) {
                $images = ProductImageResolver::formatForList($product);

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_min_sale_price' => $product->min_sale_price,
                    'product_min_stock' => $product->min_stock,
                    'product_normail_price' => $product->normailPrice,
                    'product_wholesale_price' => $product->wholesalePrice,
                    'cost_price' => optional($product->purchasePrices->first())->price,
                    'has_cost_price' => optional($product->purchasePrices->first())->price !== null,
                    'product_price' => $product->price,
                    'discount' => $product->discount,
                    'rotation_date' => $product->rotation_date,
                    'product_code' => $product->product_code,
                    'product_image' => $images['product_image'],
                    'product_viewImages' => $images['product_viewImages'],
                    'product_normalImages' => $images['product_normalImages'],
                    'product_image3d' => $images['product_image3d'],
                    'store_section_id' => $product->store_section_id,
                    'store_section_name' => $product->storeSection?->name,
                ];
            });

            return response()->json([
                'status' => 'success',
                'section' => $section?->only(['id', 'name', 'description', 'sort_order', 'is_active']),
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

    public function moveProducts(Request $request)
    {
        try {
            $data = $request->validate([
                'product_ids' => ['required', 'array', 'min:1'],
                'product_ids.*' => ['integer', 'exists:products,id'],
                'store_section_id' => ['required', 'integer', 'exists:store_sections,id'],
            ]);

            StoreSection::query()->findOrFail((int) $data['store_section_id']);

            $updated = Product::query()
                ->whereIn('id', $data['product_ids'])
                ->update([
                    'store_section_id' => (int) $data['store_section_id'],
                ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.products_location_moved'),
                'updated' => $updated,
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

    public function swapProductLocations(Request $request)
    {
        try {
            $data = $request->validate([
                'group_a' => ['required', 'array', 'min:1'],
                'group_a.*' => ['integer', 'exists:products,id', 'distinct'],
                'group_b' => ['required', 'array', 'min:1'],
                'group_b.*' => ['integer', 'exists:products,id', 'distinct'],
                'group_a_target' => ['required', 'array'],
                'group_a_target.store_section_id' => ['required', 'integer', 'exists:store_sections,id'],
                'group_b_target' => ['required', 'array'],
                'group_b_target.store_section_id' => ['required', 'integer', 'exists:store_sections,id'],
            ]);

            $idsA = array_values(array_unique(array_map('intval', $data['group_a'])));
            $idsB = array_values(array_unique(array_map('intval', $data['group_b'])));

            if (array_intersect($idsA, $idsB)) {
                throw ValidationException::withMessages([
                    'group_b' => [__('messages.product_swap_overlap')],
                ]);
            }

            $targetA = $this->normalizeSwapGroupTarget($data['group_a_target'] ?? null);
            $targetB = $this->normalizeSwapGroupTarget($data['group_b_target'] ?? null);

            $swapped = 0;

            DB::transaction(function () use ($idsA, $idsB, $targetA, $targetB, &$swapped) {
                $productsA = Product::query()
                    ->whereIn('id', $idsA)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $productsB = Product::query()
                    ->whereIn('id', $idsB)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($productsA->count() !== count($idsA) || $productsB->count() !== count($idsB)) {
                    throw ValidationException::withMessages([
                        'group_a' => [__('messages.validation_failed')],
                    ]);
                }

                if ($targetA === null || $targetB === null) {
                    throw ValidationException::withMessages([
                        'group_a_target' => [__('messages.product_swap_group_a_target_required')],
                    ]);
                }

                foreach ($idsA as $id) {
                    $productsA[$id]->update([
                        'store_section_id' => $targetA['store_section_id'],
                    ]);
                    $swapped++;
                }
                foreach ($idsB as $id) {
                    $productsB[$id]->update([
                        'store_section_id' => $targetB['store_section_id'],
                    ]);
                    $swapped++;
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.products_location_swapped'),
                'swapped' => $swapped,
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

    private function normalizeSwapGroupTarget(?array $target): ?array
    {
        if ($target === null || ! isset($target['store_section_id'])) {
            return null;
        }

        return [
            'store_section_id' => (int) $target['store_section_id'],
        ];
    }
}
