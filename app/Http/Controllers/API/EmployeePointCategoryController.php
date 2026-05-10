<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeePointCategoryResource;
use App\Models\EmployeePointCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeePointCategoryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'operation_type' => ['nullable', Rule::in([EmployeePointCategory::OPERATION_ADD, EmployeePointCategory::OPERATION_DEDUCT])],
                'is_active' => ['nullable', 'boolean'],
            ]);

            /** @phpstan-ignore-next-line */
            $query = EmployeePointCategory::query()->ordered();
            if (! empty($validated['operation_type'])) {
                $query->where('operation_type', $validated['operation_type']);
            }
            if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
                $query->where('is_active', (bool) $validated['is_active']);
            }

            $categories = $query->get();

            return response()->json([
                'status' => 'success',
                'categories' => EmployeePointCategoryResource::collection($categories),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validateCategory($request);
            $category = EmployeePointCategory::create($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.created_successfully'),
                'category' => new EmployeePointCategoryResource($category),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            /** @var EmployeePointCategory $category */
            $category = EmployeePointCategory::findOrFail($id);
            $data = $this->validateCategory($request, $category->id);
            $category->update($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.updated_successfully'),
                'category' => new EmployeePointCategoryResource($category->fresh()),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.not_found'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(int $id)
    {
        try {
            /** @var EmployeePointCategory $category */
            $category = EmployeePointCategory::findOrFail($id);
            $category->delete();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.deleted_successfully'),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.not_found'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCategory(Request $request, ?int $existingId = null): array
    {
        $codeRule = ['required', 'string', 'max:64'];
        if ($existingId !== null) {
            $codeRule[] = Rule::unique('employee_point_categories', 'code')->ignore($existingId);
        } else {
            $codeRule[] = Rule::unique('employee_point_categories', 'code');
        }

        $validated = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'code' => $codeRule,
            'operation_type' => ['required', Rule::in([EmployeePointCategory::OPERATION_ADD, EmployeePointCategory::OPERATION_DEDUCT])],
            'default_points' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $validated['is_active'] = (bool) $validated['is_active'];
        }

        return $validated;
    }
}
