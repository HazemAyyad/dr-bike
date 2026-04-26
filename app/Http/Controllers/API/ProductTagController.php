<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductTag;
use App\Services\ProductTagService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductTagController extends Controller
{
    public function __construct(
        private readonly ProductTagService $productTagService
    ) {}

    public function index(Request $request)
    {
        try {
            $includeInactive = $request->boolean('include_inactive');
            $q = ProductTag::query()->orderBy('name');
            if (! $includeInactive) {
                $q->where('is_active', true);
            }

            return response()->json([
                'status' => 'success',
                'tags' => $q->get(['id', 'name', 'color', 'is_active', 'created_at', 'updated_at']),
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
                'color' => ['required', 'string', 'max:32', 'regex:/^#([0-9A-Fa-f]{6})$/'],
            ]);
            $tag = ProductTag::query()->create([
                'name' => $data['name'],
                'color' => $data['color'],
                'is_active' => true,
            ]);

            return response()->json([
                'status' => 'success',
                'tag' => $tag->only(['id', 'name', 'color', 'is_active', 'created_at', 'updated_at']),
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
                'tag_id' => ['required', 'integer', 'exists:product_tags,id'],
                'name' => ['sometimes', 'required', 'string', 'max:120'],
                'color' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^#([0-9A-Fa-f]{6})$/'],
            ]);
            $tag = ProductTag::query()->findOrFail($data['tag_id']);
            $updates = [];
            if (array_key_exists('name', $data)) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('color', $data)) {
                $updates['color'] = $data['color'];
            }
            if ($updates !== []) {
                $tag->update($updates);
            }

            return response()->json([
                'status' => 'success',
                'tag' => $tag->fresh()->only(['id', 'name', 'color', 'is_active', 'created_at', 'updated_at']),
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
                'tag_id' => ['required', 'integer', 'exists:product_tags,id'],
            ]);
            $tag = ProductTag::query()->findOrFail($request->integer('tag_id'));
            $tag->update(['is_active' => false]);

            return response()->json([
                'status' => 'success',
                'message' => 'OK',
                'tag' => $tag->only(['id', 'name', 'color', 'is_active']),
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

    public function attach(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'tag_ids' => ['required', 'array', 'min:1'],
                'tag_ids.*' => ['integer', 'exists:product_tags,id'],
            ]);
            $this->productTagService->attachTags((int) $data['product_id'], $data['tag_ids']);

            return response()->json(['status' => 'success'], 200);
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

    public function detach(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'tag_ids' => ['required', 'array', 'min:1'],
                'tag_ids.*' => ['integer', 'exists:product_tags,id'],
            ]);
            $this->productTagService->detachTags((int) $data['product_id'], $data['tag_ids']);

            return response()->json(['status' => 'success'], 200);
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

    public function productsByTag(Request $request)
    {
        try {
            $request->validate([
                'tag_id' => ['required', 'integer', 'exists:product_tags,id'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $tagId = (int) $request->input('tag_id');
            $tag = ProductTag::query()->findOrFail($tagId);

            $products = Product::query()
                ->whereHas('tags', fn ($q) => $q->where('product_tags.id', $tagId))
                ->with(['viewImages', 'normalImages', 'tags' => fn ($q) => $q->select('product_tags.id', 'product_tags.name', 'product_tags.color', 'product_tags.is_active')])
                ->select('id', 'nameAr', 'stock', 'product_code')
                ->paginate(15, ['*'], 'page', (int) $request->input('page', 1));

            $formatted = $products->getCollection()->map(function (Product $product) {
                $image = $product->viewImages->first() ?? $product->normalImages->first();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->nameAr,
                    'product_stock' => $product->stock,
                    'product_code' => $product->product_code,
                    'product_image' => $image ? ltrim((string) $image->imageUrl, '/') : 'no image',
                    'tags' => $product->tags->map(fn (ProductTag $t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ])->values(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'tag' => $tag->only(['id', 'name', 'color', 'is_active']),
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
