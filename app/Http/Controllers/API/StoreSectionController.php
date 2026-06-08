<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreSection;
use App\Support\ApiImageUrl;
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
                ->orderBy('sort_order')
                ->orderBy('name');
            if (! $includeInactive) {
                $q->where('is_active', true);
            }

            return response()->json([
                'status' => 'success',
                'sections' => $q->get($this->sectionFields()),
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
                ->update([
                    'store_section_id' => null,
                    'shelf_number' => null,
                ]);
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
                'section' => $section->only(['id', 'name', 'description', 'sort_order', 'is_active']),
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
                'shelf_number' => ['nullable', 'string', 'max:30'],
            ]);

            StoreSection::query()->findOrFail((int) $data['store_section_id']);

            $shelf = trim((string) ($data['shelf_number'] ?? ''));
            $updated = Product::query()
                ->whereIn('id', $data['product_ids'])
                ->update([
                    'store_section_id' => (int) $data['store_section_id'],
                    'shelf_number' => $shelf === '' ? null : $shelf,
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
            ]);

            $idsA = array_values(array_unique(array_map('intval', $data['group_a'])));
            $idsB = array_values(array_unique(array_map('intval', $data['group_b'])));

            if (array_intersect($idsA, $idsB)) {
                throw ValidationException::withMessages([
                    'group_b' => [__('messages.product_swap_overlap')],
                ]);
            }

            $swapped = 0;

            DB::transaction(function () use ($idsA, $idsB, &$swapped) {
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

                $locsA = [];
                foreach ($idsA as $id) {
                    $p = $productsA[$id];
                    $locsA[] = [
                        'store_section_id' => $p->store_section_id,
                        'shelf_number' => $p->shelf_number,
                    ];
                }
                $locsB = [];
                foreach ($idsB as $id) {
                    $p = $productsB[$id];
                    $locsB[] = [
                        'store_section_id' => $p->store_section_id,
                        'shelf_number' => $p->shelf_number,
                    ];
                }

                $countA = count($idsA);
                $countB = count($idsB);

                $updatesA = [];
                foreach ($idsA as $i => $id) {
                    $updatesA[$id] = $locsB[$i % $countB];
                }
                $updatesB = [];
                foreach ($idsB as $j => $id) {
                    $updatesB[$id] = $locsA[$j % $countA];
                }

                foreach ($updatesA as $id => $loc) {
                    $productsA[$id]->update([
                        'store_section_id' => $loc['store_section_id'],
                        'shelf_number' => $loc['shelf_number'],
                    ]);
                    $swapped++;
                }
                foreach ($updatesB as $id => $loc) {
                    $productsB[$id]->update([
                        'store_section_id' => $loc['store_section_id'],
                        'shelf_number' => $loc['shelf_number'],
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
}
