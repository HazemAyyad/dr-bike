<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeRewardRuleResource;
use App\Models\EmployeeRewardRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeRewardRuleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'is_active' => ['nullable', 'boolean'],
            ]);

            /** @phpstan-ignore-next-line */
            $query = EmployeeRewardRule::query()->orderBy('min_points');
            if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
                $query->where('is_active', (bool) $validated['is_active']);
            }

            $rules = $query->get();

            return response()->json([
                'status' => 'success',
                'rules' => EmployeeRewardRuleResource::collection($rules),
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
            $data = $this->validateRule($request);
            $rule = EmployeeRewardRule::create($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.created_successfully'),
                'rule' => new EmployeeRewardRuleResource($rule),
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
            /** @var EmployeeRewardRule $rule */
            $rule = EmployeeRewardRule::findOrFail($id);
            $data = $this->validateRule($request, true);
            $rule->update($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.updated_successfully'),
                'rule' => new EmployeeRewardRuleResource($rule->fresh()),
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
            /** @var EmployeeRewardRule $rule */
            $rule = EmployeeRewardRule::findOrFail($id);
            $rule->delete();

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
    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'min_points' => [$isUpdate ? 'sometimes' : 'required', 'integer'],
            'max_points' => ['nullable', 'integer'],
            'reward_amount' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);

        $minPoints = (int) ($validated['min_points'] ?? 0);
        $maxPoints = $validated['max_points'] ?? null;
        if ($maxPoints !== null) {
            if ((int) $maxPoints < $minPoints) {
                throw ValidationException::withMessages([
                    'max_points' => __('The max points must be greater than or equal to min points.'),
                ]);
            }
            $validated['max_points'] = (int) $maxPoints;
        }

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $validated['is_active'] = (bool) $validated['is_active'];
        }

        return $validated;
    }
}
