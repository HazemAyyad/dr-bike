<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeReminder;
use App\Models\EmployeeReminderOccurrence;
use App\Services\EmployeeReminderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeRemindersController extends Controller
{
    public function __construct(
        private readonly EmployeeReminderService $reminders
    ) {}

    public function index(Request $request)
    {
        $query = EmployeeReminder::query()
            ->with(['employee.user', 'occurrences' => fn ($q) => $q->latest('scheduled_at')->limit(3)])
            ->latest();

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->input('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => 'success',
            'reminders' => $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employee_details,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_at' => ['required', 'date'],
            'repeat_type' => ['nullable', 'string', Rule::in(EmployeeReminder::REPEAT_TYPES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $created = $this->reminders->createForEmployees(
            array_map('intval', $validated['employee_ids']),
            $validated,
            (int) $request->user()->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء التذكير بنجاح',
            'reminders' => $created->values(),
        ], 201);
    }

    public function update(Request $request, EmployeeReminder $reminder)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'repeat_type' => ['sometimes', 'required', 'string', Rule::in(EmployeeReminder::REPEAT_TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث التذكير بنجاح',
            'reminder' => $this->reminders->updateReminder($reminder, $validated),
        ]);
    }

    public function destroy(EmployeeReminder $reminder)
    {
        $reminder->update(['is_active' => false]);
        $reminder->occurrences()
            ->whereIn('status', [EmployeeReminderOccurrence::STATUS_PENDING, EmployeeReminderOccurrence::STATUS_SNOOZED])
            ->update(['status' => EmployeeReminderOccurrence::STATUS_CANCELED]);
        $reminder->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف التذكير بنجاح',
        ]);
    }

    public function employeeIndex(Request $request)
    {
        $employeeId = (int) $request->user()->employee->id;

        $query = EmployeeReminderOccurrence::query()
            ->with('reminder')
            ->where('employee_id', $employeeId)
            ->whereHas('reminder', fn ($q) => $q->where('is_active', true))
            ->whereIn('status', [
                EmployeeReminderOccurrence::STATUS_PENDING,
                EmployeeReminderOccurrence::STATUS_SNOOZED,
            ])
            ->orderBy('scheduled_at');

        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('scheduled_at', '<=', $request->input('to'));
        }

        return response()->json([
            'status' => 'success',
            'reminders' => $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100)),
        ]);
    }

    public function markDone(Request $request, EmployeeReminderOccurrence $occurrence)
    {
        $this->assertOccurrenceOwner($request, $occurrence);

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنهاء التذكير',
            'occurrence' => $this->reminders->markDone($occurrence),
        ]);
    }

    public function snooze(Request $request, EmployeeReminderOccurrence $occurrence)
    {
        $this->assertOccurrenceOwner($request, $occurrence);

        $validated = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تأجيل التذكير',
            'occurrence' => $this->reminders->snooze($occurrence, (int) ($validated['minutes'] ?? 30)),
        ]);
    }

    private function assertOccurrenceOwner(Request $request, EmployeeReminderOccurrence $occurrence): void
    {
        abort_unless((int) $occurrence->employee_id === (int) $request->user()->employee->id, 403);
    }
}
