<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSuggestion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeSuggestionsController extends Controller
{
    public function adminIndex(Request $request)
    {
        $query = EmployeeSuggestion::query()
            ->with(['employee.user:id,name', 'reviewer:id,name'])
            ->latest();

        if ($request->filled('status') && in_array($request->input('status'), EmployeeSuggestion::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category') && in_array($request->input('category'), EmployeeSuggestion::CATEGORIES, true)) {
            $query->where('category', $request->input('category'));
        }

        if ($request->boolean('anonymous_only')) {
            $query->where('is_anonymous', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhereHas('employee.user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'status' => 'success',
            'suggestions' => $query->paginate($perPage)->through(fn ($suggestion) => $this->adminPayload($suggestion)),
        ]);
    }

    public function employeeIndex(Request $request)
    {
        $employeeId = (int) $request->user()->employee->id;
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $suggestions = EmployeeSuggestion::query()
            ->where('employee_id', $employeeId)
            ->with('reviewer:id,name')
            ->latest()
            ->paginate($perPage)
            ->through(fn ($suggestion) => $this->employeePayload($suggestion));

        return response()->json([
            'status' => 'success',
            'suggestions' => $suggestions,
        ]);
    }

    public function store(Request $request)
    {
        $employeeId = (int) $request->user()->employee->id;

        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(EmployeeSuggestion::CATEGORIES)],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $suggestion = EmployeeSuggestion::create([
            'employee_id' => $employeeId,
            'category' => $validated['category'] ?? EmployeeSuggestion::CATEGORY_SUGGESTION,
            'title' => $validated['title'] ?? null,
            'message' => $validated['message'],
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
            'status' => EmployeeSuggestion::STATUS_NEW,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم إرسال الاقتراح بنجاح',
            'suggestion' => $this->employeePayload($suggestion->fresh('reviewer:id,name')),
        ], 201);
    }

    public function employeeUpdate(Request $request, EmployeeSuggestion $suggestion)
    {
        $this->assertEmployeeOwner($request, $suggestion);

        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(EmployeeSuggestion::CATEGORIES)],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $suggestion->update([
            'category' => $validated['category'] ?? EmployeeSuggestion::CATEGORY_SUGGESTION,
            'title' => $validated['title'] ?? null,
            'message' => $validated['message'],
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
            'status' => EmployeeSuggestion::STATUS_NEW,
            'admin_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الاقتراح بنجاح',
            'suggestion' => $this->employeePayload($suggestion->fresh('reviewer:id,name')),
        ]);
    }

    public function employeeDestroy(Request $request, EmployeeSuggestion $suggestion)
    {
        $this->assertEmployeeOwner($request, $suggestion);
        $suggestion->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الاقتراح بنجاح',
        ]);
    }

    public function update(Request $request, EmployeeSuggestion $suggestion)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(EmployeeSuggestion::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $payload = [];
        if (array_key_exists('status', $validated)) {
            $payload['status'] = $validated['status'];
        }
        if (array_key_exists('admin_note', $validated)) {
            $payload['admin_note'] = $validated['admin_note'];
        }

        if ($payload !== []) {
            $payload['reviewed_by'] = (int) $request->user()->id;
            $payload['reviewed_at'] = now();
            $suggestion->update($payload);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الاقتراح بنجاح',
            'suggestion' => $this->adminPayload($suggestion->fresh(['employee.user:id,name', 'reviewer:id,name'])),
        ]);
    }

    private function adminPayload(EmployeeSuggestion $suggestion): array
    {
        $hidden = (bool) $suggestion->is_anonymous;

        return [
            'id' => $suggestion->id,
            'category' => $suggestion->category,
            'title' => $suggestion->title,
            'message' => $suggestion->message,
            'is_anonymous' => $hidden,
            'employee_id' => $hidden ? null : $suggestion->employee_id,
            'employee_name' => $hidden ? 'مخفي' : (string) ($suggestion->employee?->user?->name ?? ''),
            'status' => $suggestion->status,
            'admin_note' => $suggestion->admin_note,
            'reviewed_by_name' => (string) ($suggestion->reviewer?->name ?? ''),
            'reviewed_at' => optional($suggestion->reviewed_at)->toIso8601String(),
            'created_at' => optional($suggestion->created_at)->toIso8601String(),
        ];
    }

    private function employeePayload(EmployeeSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'category' => $suggestion->category,
            'title' => $suggestion->title,
            'message' => $suggestion->message,
            'is_anonymous' => (bool) $suggestion->is_anonymous,
            'status' => $suggestion->status,
            'admin_note' => $suggestion->admin_note,
            'reviewed_by_name' => (string) ($suggestion->reviewer?->name ?? ''),
            'reviewed_at' => optional($suggestion->reviewed_at)->toIso8601String(),
            'created_at' => optional($suggestion->created_at)->toIso8601String(),
        ];
    }

    private function assertEmployeeOwner(Request $request, EmployeeSuggestion $suggestion): void
    {
        abort_unless((int) $suggestion->employee_id === (int) $request->user()->employee->id, 403);
    }
}
