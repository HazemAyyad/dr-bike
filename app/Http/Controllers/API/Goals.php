<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\GoalCategory;
use App\Models\GoalDailySnapshot;
use App\Models\GoalEmployeeShare;
use App\Models\GoalLog;
use App\Models\GoalPeople;
use App\Models\GoalProduct;
use App\Models\GoalStoreSection;
use App\Models\GoalSubCategory;
use App\Models\EmployeeDetail;
use App\Services\Goals\GoalCalculationService;
use App\Services\Goals\GoalNotificationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Goals extends Controller
{
    private const GOAL_TYPES = 'total_sell_values,net_profit,sell_pieces,purchase_pieces,total_purchase_values,finish_tasks,pay_person,deposit_to_box';

    public function __construct(
        private GoalCalculationService $calculator,
        private GoalNotificationService $goalNotifications
    )
    {
    }

    public function createGoal(Request $request)
    {
        try {
            $data = $this->validatedGoalData($request);
            $data['current_value'] = 0;
            $data['achievement_percentage'] = 0;

            $goal = DB::transaction(function () use ($request, $data) {
                $goal = Goal::create($data);
                $this->syncGoalRelations($goal, $request);
                $this->syncSharedEmployees($goal, $request);

                GoalLog::create([
                    'title' => 'اضافة هدف جديد',
                    'description' => 'تم اضافة هدف جديد باسم '.$goal->name,
                    'goal_id' => $goal->id,
                ]);

                return $this->calculator->recalculate($goal);
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_created_successfully'),
                'goal' => $goal,
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
                'message' => __('messages.failed_to_create_goal'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function editGoal(Request $request)
    {
        try {
            $request->validate(['goal_id' => 'required|integer|exists:goals,id']);
            $goal = Goal::findOrFail((int) $request->goal_id);
            $data = $this->validatedGoalData($request, true);
            unset($data['current_value'], $data['achievement_percentage']);

            $goal = DB::transaction(function () use ($request, $goal, $data) {
                $goal->update($data);
                $this->syncGoalRelations($goal->fresh(), $request);
                $this->syncSharedEmployees($goal->fresh(), $request);

                GoalLog::create([
                    'title' => 'تعديل بيانات هدف ',
                    'description' => 'تم تعديل بيانات هدف باسم '.$goal->name,
                    'goal_id' => $goal->id,
                ]);

                return $this->calculator->recalculate($goal->fresh());
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_updated_successfully'),
                'goal' => $goal,
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
                'message' => __('messages.goal_not_found'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.update_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_update_goal'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function showGoal(Request $request)
    {
        try {
            $request->validate(['goal_id' => 'required|exists:goals,id']);
            $goal = $this->calculator->recalculate(Goal::findOrFail((int) $request->goal_id));
            $goal->load(['box:id,name', 'employee.user:id,name']);

            if ($goal->employee_id && $goal->employee?->user) {
                $goal['employee'] = [
                    'id' => $goal->employee_id,
                    'name' => $goal->employee->user->name,
                ];
                $goal->unsetRelation('employee');
            }

            $goal['main_categories'] = GoalCategory::where('goal_id', $goal->id)->get()->map(fn ($row) => [
                'category_id' => $row->category?->id,
                'category_name' => $row->category?->nameAr,
            ]);
            $goal['sub_categories'] = GoalSubCategory::where('goal_id', $goal->id)->get()->map(fn ($row) => [
                'sub_category_id' => $row->subCategory?->id,
                'sub_category_name' => $row->subCategory?->nameAr,
            ]);
            $goal['store_sections'] = GoalStoreSection::where('goal_id', $goal->id)
                ->leftJoin('store_sections', 'store_sections.id', '=', 'goal_store_sections.store_section_id')
                ->get(['goal_store_sections.store_section_id', 'store_sections.name as store_section_name'])
                ->map(fn ($row) => [
                    'store_section_id' => $row->store_section_id,
                    'store_section_name' => $row->store_section_name,
                ]);
            $goal['products'] = GoalProduct::where('goal_id', $goal->id)->get()->map(fn ($row) => [
                'product_id' => $row->product?->id,
                'product_name' => $row->product?->nameAr,
            ]);
            $goal['people'] = GoalPeople::where('goal_id', $goal->id)->get()->map(fn ($row) => [
                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer?->name,
                'seller_id' => $row->seller_id,
                'seller_name' => $row->seller?->name,
            ]);
            $goal['shared_employees'] = GoalEmployeeShare::where('goal_id', $goal->id)
                ->with('employee.user:id,name')
                ->get()
                ->map(fn ($row) => [
                    'employee_id' => $row->employee_id,
                    'employee_name' => $row->employee?->user?->name,
                ]);
            $goal['status_meta'] = $this->goalNotifications->decorateGoal($goal);
            $snapshots = GoalDailySnapshot::where('goal_id', $goal->id)
                ->orderBy('snapshot_date')
                ->get(['snapshot_date', 'current_value', 'achievement_percentage'])
                ->keyBy(fn ($row) => optional($row->snapshot_date)->toDateString() ?? (string) $row->snapshot_date);

            $startDate = $goal->start_date ? Carbon::parse($goal->start_date)->startOfDay() : Carbon::parse($goal->created_at)->startOfDay();
            $dueDate = $goal->due_date ? Carbon::parse($goal->due_date)->startOfDay() : now()->startOfDay();
            if ($dueDate->lt($startDate)) {
                $dueDate = $startDate->copy();
            }
            $today = now()->toDateString();
            $goal['progress_history'] = collect(CarbonPeriod::create($startDate, $dueDate))
                ->map(function (Carbon $date) use ($snapshots, $goal, $today) {
                    $key = $date->toDateString();
                    $snapshot = $snapshots->get($key);
                    $useCurrentGoal = $key === $today;
                    return [
                        'date' => $key,
                        'current_value' => $snapshot || $useCurrentGoal
                            ? number_format((float) ($snapshot?->current_value ?? $goal->current_value), 2, '.', '')
                            : '',
                        'achievement_percentage' => $snapshot || $useCurrentGoal
                            ? number_format((float) ($snapshot?->achievement_percentage ?? $goal->achievement_percentage), 2, '.', '')
                            : '',
                        'has_data' => (bool) ($snapshot || $useCurrentGoal),
                    ];
                })
                ->values();

            $goalLogs = $goal->logs()->get(['title', 'description']);
            $goal->makeHidden(['product_id', 'customer_id', 'employee_id', 'seller_id', 'box_id']);

            return response()->json([
                'status' => 'success',
                'goal' => $goal,
                'goal_logs' => $goalLogs,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.goal_not_found')], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_goal'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function getGoals()
    {
        try {
            $goals = Goal::orderByDesc('created_at')->get([
                'id',
                'scope',
                'calculation_mode',
                'name',
                'type',
                'achievement_percentage',
                'targeted_value',
                'current_value',
                'is_canceled',
                'created_at',
                'start_date',
                'due_date',
            ])->map(function (Goal $goal) {
                $goal = $this->calculator->recalculate($goal);
                $goal['status_meta'] = $this->goalNotifications->decorateGoal($goal);

                return $goal;
            });

            return response()->json([
                'status' => 'success',
                'goals' => $goals,
            ], 200);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.retrieve_data_error')], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_goals'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function recalculateGoal(Request $request)
    {
        try {
            $request->validate(['goal_id' => 'required|exists:goals,id']);
            $goal = $this->calculator->recalculate(Goal::findOrFail((int) $request->goal_id));

            return response()->json([
                'status' => 'success',
                'goal' => $goal,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.goal_not_found')], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function shareGoal(Request $request)
    {
        try {
            $request->validate([
                'goal_id' => 'required|integer|exists:goals,id',
                'employee_ids' => 'nullable|array',
                'employee_ids.*' => 'integer|exists:employee_details,id',
            ]);

            $goal = Goal::findOrFail((int) $request->goal_id);
            $this->syncSharedEmployees($goal, $request, true);

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحديث مشاركة الهدف بنجاح',
                'shared_employees' => GoalEmployeeShare::where('goal_id', $goal->id)
                    ->with('employee.user:id,name')
                    ->get()
                    ->map(fn ($row) => [
                        'employee_id' => $row->employee_id,
                        'employee_name' => $row->employee?->user?->name,
                    ]),
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
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function cancelGoal(Request $request)
    {
        return $this->changeGoal($request, 'is_canceled', 1, 'الغاء هدف', 'تم الغاء هدف باسم', 'cancelled');
    }

    public function transferGoal(Request $request)
    {
        try {
            $request->validate(['goal_id' => 'required|exists:goals,id']);
            $goal = Goal::findOrFail((int) $request->goal_id);
            $newScope = $goal->scope === 'private' ? 'public' : 'private';
            $goal->update(['scope' => $newScope]);

            GoalLog::create([
                'title' => 'نقل هدف ',
                'description' => 'تم نقل هدف باسم '.$goal->name.' الى '.($newScope === 'public' ? 'عام' : 'خاص'),
                'goal_id' => $goal->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_transferred_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.goal_not_found')], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function deleteGoal(Request $request)
    {
        try {
            $request->validate(['goal_id' => 'required|integer|exists:goals,id']);
            Goal::findOrFail((int) $request->goal_id)->delete();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_deleted'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.goal_not_found')], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    private function validatedGoalData(Request $request, bool $editing = false): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:'.self::GOAL_TYPES,
            'calculation_mode' => 'nullable|string|in:total,detailed',
            'form' => 'nullable|string|in:main_categories,sub_categories,products,store_sections,employee,people,box',
            'targeted_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'scope' => 'nullable|string|in:public,private',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'people' => 'nullable|array',
            'people.*.customer_id' => 'nullable|integer|exists:customers,id',
            'people.*.seller_id' => 'nullable|integer|exists:sellers,id',
            'products' => 'nullable|array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'main_categories' => 'nullable|array',
            'main_categories.*.main_category_id' => 'required|integer|exists:categories,id',
            'sub_categories' => 'nullable|array',
            'sub_categories.*.sub_category_id' => 'required|integer|exists:sub_categories,id',
            'store_sections' => 'nullable|array',
            'store_sections.*.store_section_id' => 'required|integer|exists:store_sections,id',
            'employee_id' => 'nullable|exists:employee_details,id',
            'box_id' => 'nullable|exists:boxes,id',
            'shared_employee_ids' => 'nullable|array',
            'shared_employee_ids.*' => 'integer|exists:employee_details,id',
        ]);

        $data['scope'] = $data['scope'] ?? 'public';
        $data['calculation_mode'] = $data['calculation_mode'] ?? 'total';

        if (in_array($data['type'], ['finish_tasks', 'pay_person', 'deposit_to_box'], true)) {
            $data['calculation_mode'] = 'detailed';
        }

        if ($data['type'] === 'finish_tasks') {
            if (! $request->filled('employee_id')) {
                throw ValidationException::withMessages(['employee_id' => [__('messages.must_select_employee')]]);
            }
            $data['form'] = 'employee';
        } elseif ($data['type'] === 'pay_person') {
            $this->validatePeoplePayload($request);
            $data['form'] = $request->filled('employee_id') ? 'employee' : 'people';
        } elseif ($data['type'] === 'deposit_to_box') {
            if (! $request->filled('box_id')) {
                throw ValidationException::withMessages(['box_id' => [__('messages.must_select_box')]]);
            }
            $data['form'] = 'box';
        } elseif ($data['calculation_mode'] === 'detailed') {
            $this->validateDetailedProductGoal($request, $data['type']);
        } else {
            $data['form'] = null;
        }

        return $data;
    }

    private function validateDetailedProductGoal(Request $request, string $type): void
    {
        $allowed = ['main_categories', 'sub_categories', 'products', 'store_sections'];
        if ($type === 'total_purchase_values') {
            $allowed[] = 'people';
        }

        $filled = collect($allowed)->filter(fn ($field) => $request->filled($field))->values();

        if ($filled->isEmpty()) {
            throw ValidationException::withMessages(['form' => [__('messages.must_select_choice')]]);
        }

        if ($filled->count() > 1) {
            throw ValidationException::withMessages(['form' => [__('messages.must_select_one_choice')]]);
        }

        if ($request->input('form') !== $filled->first()) {
            throw ValidationException::withMessages(['form' => [__('messages.form_does_not_match_selected_field')]]);
        }
    }

    private function validatePeoplePayload(Request $request): void
    {
        $hasPeople = $request->filled('people');
        $hasEmployee = $request->filled('employee_id');

        if (! $hasPeople && ! $hasEmployee) {
            throw ValidationException::withMessages(['people' => [__('messages.must_select_perosn')]]);
        }

        if ($hasPeople && $hasEmployee) {
            throw ValidationException::withMessages(['people' => [__('messages.must_select_one_perosn')]]);
        }

        if (! $hasPeople) {
            return;
        }

        if (count($request->people) > 1) {
            throw ValidationException::withMessages(['people' => [__('messages.must_select_one_person')]]);
        }

        foreach ($request->people as $person) {
            $customer = $person['customer_id'] ?? null;
            $seller = $person['seller_id'] ?? null;

            if (($customer && $seller) || (! $customer && ! $seller)) {
                throw ValidationException::withMessages(['people' => [__('messages.must_select_either_customer_or_seller')]]);
            }
        }
    }

    private function syncGoalRelations(Goal $goal, Request $request): void
    {
        GoalCategory::where('goal_id', $goal->id)->delete();
        GoalSubCategory::where('goal_id', $goal->id)->delete();
        GoalProduct::where('goal_id', $goal->id)->delete();
        GoalStoreSection::where('goal_id', $goal->id)->delete();
        GoalPeople::where('goal_id', $goal->id)->delete();

        if ($goal->calculation_mode !== 'detailed') {
            return;
        }

        foreach ($request->main_categories ?? [] as $row) {
            GoalCategory::create(['goal_id' => $goal->id, 'category_id' => $row['main_category_id']]);
        }

        foreach ($request->sub_categories ?? [] as $row) {
            GoalSubCategory::create(['goal_id' => $goal->id, 'sub_category_id' => $row['sub_category_id']]);
        }

        foreach ($request->store_sections ?? [] as $row) {
            GoalStoreSection::create(['goal_id' => $goal->id, 'store_section_id' => $row['store_section_id']]);
        }

        foreach ($request->products ?? [] as $row) {
            GoalProduct::create(['goal_id' => $goal->id, 'product_id' => $row['product_id']]);
        }

        foreach ($request->people ?? [] as $row) {
            GoalPeople::create([
                'goal_id' => $goal->id,
                'customer_id' => $row['customer_id'] ?? null,
                'seller_id' => $row['seller_id'] ?? null,
            ]);
        }
    }

    private function syncSharedEmployees(Goal $goal, Request $request, bool $force = false): void
    {
        if (! $force && ! $request->has('shared_employee_ids') && ! $request->has('employee_ids')) {
            return;
        }

        $employeeIds = collect($request->input('shared_employee_ids', $request->input('employee_ids', [])))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        GoalEmployeeShare::where('goal_id', $goal->id)
            ->whereNotIn('employee_id', $employeeIds)
            ->delete();

        foreach ($employeeIds as $employeeId) {
            $share = GoalEmployeeShare::firstOrCreate(
                [
                    'goal_id' => $goal->id,
                    'employee_id' => $employeeId,
                ],
                [
                    'shared_by_user_id' => optional($request->user())->id,
                ]
            );

            if ($share->wasRecentlyCreated) {
                $employee = EmployeeDetail::find($employeeId);
                if ($employee) {
                    $this->goalNotifications->notifyGoalShared($goal, $employee);
                }
            }
        }
    }

    private function changeGoal(Request $request, string $field, mixed $value, ?string $logTitle, ?string $logMessage, string $msgType)
    {
        try {
            $request->validate(['goal_id' => 'required|exists:goals,id']);
            $goal = Goal::findOrFail((int) $request->goal_id);
            $goal->update([$field => $value]);

            if ($logTitle && $logMessage) {
                GoalLog::create([
                    'title' => $logTitle,
                    'description' => $logMessage.' '.$goal->name,
                    'goal_id' => $goal->id,
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_'.$msgType.'_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.goal_not_found')], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
}
