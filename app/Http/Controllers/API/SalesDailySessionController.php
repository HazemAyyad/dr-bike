<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SalesCancellationRequest;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailyReopenRequest;
use App\Services\SalesCancellationExecutor;
use App\Services\SalesDailySessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesDailySessionController extends Controller
{
    public function __construct(
        protected SalesDailySessionService $sessionService,
        protected SalesCancellationExecutor $cancellationExecutor
    ) {}

    private function normalizeNumericInput($value)
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim(str_replace([' ', '٬', '،'], ['', ',', ','], $value));
        $hasDot = str_contains($value, '.');
        $commaCount = substr_count($value, ',');

        if (! $hasDot && $commaCount === 1 && preg_match('/,\d{1,2}$/', $value)) {
            return str_replace(',', '.', $value);
        }

        if ($hasDot && $commaCount > 0 && strrpos($value, ',') > strrpos($value, '.')) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        return str_replace(',', '', $value);
    }

    private function normalizeCountRowsInput(Request $request, string $field): void
    {
        $rows = $request->input($field);
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (['physical_count', 'float_to_keep', 'payments_counts', 'payment_counts'] as $key) {
                if (! array_key_exists($key, $row)) {
                    continue;
                }

                $row[$key] = $this->normalizeNumericInput($row[$key]);
            }
        }
        unset($row);

        $request->merge([$field => $rows]);
    }

    private function normalizeCashCountInput(Request $request): void
    {
        $this->normalizeCountRowsInput($request, 'cash_counts');
    }

    private function normalizeOpeningCountInput(Request $request): void
    {
        $this->normalizeCountRowsInput($request, 'opening_counts');
    }

    public function current(Request $request)
    {
        try {
            $payload = $this->sessionService->buildSessionPayload($request->user());

            return response()->json([
                'status' => 'success',
                'daily_session' => $payload,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function open(Request $request)
    {
        try {
            $this->normalizeOpeningCountInput($request);

            $data = $request->validate([
                'opening_counts' => 'nullable|array',
                'opening_counts.*.currency' => 'required|string',
                'opening_counts.*.physical_count' => 'required|numeric|min:0',
                'confirm_opening_variance' => 'nullable|boolean',
            ]);

            $session = $this->sessionService->openSession(
                $request->user(),
                null,
                $data['opening_counts'] ?? [],
                (bool) ($data['confirm_opening_variance'] ?? false)
            );
            $payload = $this->sessionService->buildSessionPayload($request->user());

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_opened'),
                'daily_session' => $payload,
                'session_id' => $session->id,
            ], 200);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return response()->json([
                'status' => 'error',
                'message' => $firstError ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function openSessions(Request $request)
    {
        try {
            $result = $this->sessionService->listOpenSessions($request->user());

            return response()->json([
                'status' => 'success',
                'sessions' => $result['sessions']->values()->all(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->validate([
                'business_date' => 'nullable|date',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'status' => 'nullable|string|in:open,closing_requested,closed',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            $result = $this->sessionService->listSessions($request->user(), $filters);

            return response()->json([
                'status' => 'success',
                'sessions' => $result['sessions']->values()->all(),
                'pagination' => $result['pagination'],
                'can_view_all' => $this->sessionService->canReviewAllSessions($request->user()),
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

    public function todayOverview(Request $request)
    {
        try {
            $overview = $this->sessionService->buildTodayOverview($request->user());

            return response()->json([
                'status' => 'success',
                'overview' => $overview,
                'can_view_all' => $this->sessionService->canReviewAllSessions($request->user()),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function show(Request $request, int $sessionId)
    {
        try {
            $detail = $this->sessionService->buildSessionDetail($request->user(), $sessionId);

            return response()->json([
                'status' => 'success',
                'session_detail' => $detail,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function closePayload(Request $request, int $sessionId)
    {
        try {
            $payload = $this->sessionService->buildClosePayloadForSession(
                $request->user(),
                $sessionId
            );

            return response()->json([
                'status' => 'success',
                'daily_session' => $payload,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function requestClosing(Request $request)
    {
        try {
            $this->normalizeCashCountInput($request);

            $data = $request->validate([
                'cash_counts' => 'required|array|min:1',
                'cash_counts.*.currency' => 'required|string',
                'cash_counts.*.physical_count' => 'required|numeric|min:0',
                'cash_counts.*.float_to_keep' => 'required|numeric|min:0',
                'cash_counts.*.employee_note' => 'nullable|string|max:1000',
                'late_close_reason' => 'nullable|string|max:2000',
                'session_id' => 'nullable|integer|exists:sales_daily_sessions,id',
                'transfers' => 'nullable|array',
                'transfers.*.currency' => 'required|string',
                'transfers.*.to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->sessionService->requestClosing(
                $request->user(),
                $data['cash_counts'],
                $data['late_close_reason'] ?? null,
                isset($data['session_id']) ? (int) $data['session_id'] : null,
                $data['transfers'] ?? null,
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => $closingRequest->isPending()
                    ? __('messages.sales_daily_closing_requested')
                    : __('messages.sales_daily_closing_approved'),
                'closing_request' => $this->formatClosingRequest($closingRequest),
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

    public function pendingClosing(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $items = SalesDailyClosingRequest::query()
                ->with(['session.user', 'session.employee.user', 'requestedBy'])
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->get()
                ->map(fn ($item) => $this->formatClosingRequest($item));

            return response()->json([
                'status' => 'success',
                'closing_requests' => $items,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function approveClosing(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'closing_request_id' => 'required|integer|exists:sales_daily_closing_requests,id',
                'transfers' => 'nullable|array',
                'transfers.*.currency' => 'required|string',
                'transfers.*.to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->sessionService->approveClosing(
                $request->user(),
                (int) $data['closing_request_id'],
                $data['transfers'] ?? [],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_closing_approved'),
                'closing_request' => $this->formatClosingRequest($closingRequest),
            ], 200);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return response()->json([
                'status' => 'error',
                'message' => $firstError ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: __('messages.something_wrong'),
            ], 200);
        }
    }

    public function directClose(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $this->normalizeCashCountInput($request);

            $data = $request->validate([
                'cash_counts' => 'required|array|min:1',
                'cash_counts.*.currency' => 'required|string',
                'cash_counts.*.physical_count' => 'required|numeric|min:0',
                'cash_counts.*.float_to_keep' => 'required|numeric|min:0',
                'cash_counts.*.employee_note' => 'nullable|string|max:1000',
                'session_id' => 'required|integer|exists:sales_daily_sessions,id',
                'transfers' => 'nullable|array',
                'transfers.*.currency' => 'required|string',
                'transfers.*.to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->sessionService->directClose(
                $request->user(),
                $data['cash_counts'],
                (int) $data['session_id'],
                $data['transfers'] ?? [],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_direct_closed'),
                'closing_request' => $this->formatClosingRequest($closingRequest),
            ], 200);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return response()->json([
                'status' => 'error',
                'message' => $firstError ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: __('messages.something_wrong'),
            ], 200);
        }
    }

    public function rejectClosing(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'closing_request_id' => 'required|integer|exists:sales_daily_closing_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->sessionService->rejectClosing(
                $request->user(),
                (int) $data['closing_request_id'],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_closing_rejected'),
                'closing_request' => $this->formatClosingRequest($closingRequest),
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

    public function requestReopen(Request $request)
    {
        try {
            $data = $request->validate([
                'reason' => 'required|string|max:2000',
            ]);

            $reopenRequest = $this->sessionService->requestReopen(
                $request->user(),
                $data['reason']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_reopen_requested'),
                'reopen_request' => $this->formatReopenRequest($reopenRequest),
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

    public function pendingReopen(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $items = $this->sessionService->pendingReopenRequests()
                ->map(fn ($item) => $this->formatReopenRequest($item));

            return response()->json([
                'status' => 'success',
                'reopen_requests' => $items,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function approveReopen(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'reopen_request_id' => 'required|integer|exists:sales_daily_reopen_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $reopenRequest = $this->sessionService->approveReopen(
                $request->user(),
                (int) $data['reopen_request_id'],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_reopen_approved'),
                'reopen_request' => $this->formatReopenRequest($reopenRequest),
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
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function rejectReopen(Request $request)
    {
        try {
            if (! $this->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'reopen_request_id' => 'required|integer|exists:sales_daily_reopen_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $reopenRequest = $this->sessionService->rejectReopen(
                $request->user(),
                (int) $data['reopen_request_id'],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_reopen_rejected'),
                'reopen_request' => $this->formatReopenRequest($reopenRequest),
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

    public function requestCancellation(Request $request)
    {
        try {
            $data = $request->validate([
                'sale_type' => 'required|string|in:instant,profit',
                'sale_id' => 'required|integer|min:1',
                'reason' => 'required|string|max:2000',
            ]);

            $cancelRequest = $this->sessionService->requestCancellation(
                $request->user(),
                $data['sale_type'],
                (int) $data['sale_id'],
                $data['reason']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_cancel_requested'),
                'cancellation_request' => $this->formatCancellationRequest($cancelRequest),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function pendingCancellations(Request $request)
    {
        try {
            $canReviewAll = $this->canReviewCancellation($request->user());

            $query = SalesCancellationRequest::query()
                ->with(['session.user', 'requestedBy'])
                ->where('status', 'pending');

            if (! $canReviewAll) {
                $query->whereHas('session', function ($q) use ($request) {
                    $q->where('user_id', $request->user()->id);
                });
            }

            $items = $query->orderByDesc('id')
                ->get()
                ->map(fn ($item) => $this->formatCancellationRequest($item));

            return response()->json([
                'status' => 'success',
                'cancellation_requests' => $items,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function approveCancellation(Request $request)
    {
        try {
            $data = $request->validate([
                'cancellation_request_id' => 'required|integer|exists:sales_cancellation_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $cancelRequest = SalesCancellationRequest::query()
                ->with('session')
                ->findOrFail($data['cancellation_request_id']);

            if (! $this->canReviewCancellation($request->user()) && ! $this->isSessionOwner($request->user(), $cancelRequest)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $cancelRequest = $this->cancellationExecutor->approve(
                $cancelRequest,
                (int) $request->user()->id,
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_cancel_approved'),
                'cancellation_request' => $this->formatCancellationRequest($cancelRequest),
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

    public function rejectCancellation(Request $request)
    {
        try {
            $data = $request->validate([
                'cancellation_request_id' => 'required|integer|exists:sales_cancellation_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $cancelRequest = SalesCancellationRequest::query()
                ->with('session')
                ->findOrFail($data['cancellation_request_id']);

            if (! $this->canReviewCancellation($request->user()) && ! $this->isSessionOwner($request->user(), $cancelRequest)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            if (! $cancelRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => [__('messages.sales_daily_request_not_pending')],
                ]);
            }

            $cancelRequest->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_cancel_rejected'),
                'cancellation_request' => $this->formatCancellationRequest($cancelRequest->fresh()),
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

    private function canReviewClosing($user): bool
    {
        if ($user && $user->type === 'admin') {
            return true;
        }

        return $this->userHasPermission($user, config('sales_daily.permissions.daily_close_review'));
    }

    private function canReviewCancellation($user): bool
    {
        if ($user && $user->type === 'admin') {
            return true;
        }

        return $this->userHasPermission($user, config('sales_daily.permissions.cancel_closed_review'));
    }

    private function isSessionOwner($user, SalesCancellationRequest $request): bool
    {
        return $user
            && $request->session
            && (int) $request->session->user_id === (int) $user->id;
    }

    private function userHasPermission($user, string $permission): bool
    {
        if (! $user || $user->type !== 'employee' || ! $user->employee) {
            return false;
        }

        return $user->employee->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', $permission))
            ->exists();
    }

    private function formatClosingRequest(SalesDailyClosingRequest $request): array
    {
        $request->loadMissing(['session.user', 'session.employee.user', 'requestedBy', 'reviewedBy']);

        return array_merge(
            $this->sessionService->formatClosingRequestRow($request),
            [
                'employee_name' => $request->session?->user?->name,
                'session_id' => $request->session_id,
                'session_status' => $request->session?->status,
            ]
        );
    }

    private function formatReopenRequest(SalesDailyReopenRequest $request): array
    {
        $request->loadMissing(['session.user', 'session.employee.user', 'requestedBy', 'reviewedBy']);

        return [
            'id' => $request->id,
            'status' => $request->status,
            'reason' => $request->reason,
            'requested_at' => $request->requested_at?->toDateTimeString(),
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'review_notes' => $request->review_notes,
            'employee_name' => $request->session?->user?->name,
            'business_date' => $request->session?->business_date?->toDateString(),
            'session_id' => $request->session_id,
            'session_status' => $request->session?->status,
        ];
    }

    private function formatCancellationRequest(SalesCancellationRequest $request): array
    {
        $request->loadMissing(['session.user', 'requestedBy']);

        return [
            'id' => $request->id,
            'sale_type' => $request->sale_type,
            'sale_id' => $request->sale_id,
            'status' => $request->status,
            'reason' => $request->reason,
            'requested_at' => $request->requested_at?->toDateTimeString(),
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'review_notes' => $request->review_notes,
            'session_id' => $request->session_id,
            'business_date' => $request->session?->business_date?->toDateString(),
            'employee_name' => $request->session?->user?->name,
        ];
    }
}
