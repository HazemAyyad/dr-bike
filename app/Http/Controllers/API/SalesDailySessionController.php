<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SalesCancellationRequest;
use App\Models\SalesDailyClosingRequest;
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

    public function requestClosing(Request $request)
    {
        try {
            $data = $request->validate([
                'cash_counts' => 'required|array|min:1',
                'cash_counts.*.currency' => 'required|string',
                'cash_counts.*.physical_count' => 'required|numeric|min:0',
                'cash_counts.*.float_to_keep' => 'required|numeric|min:0',
                'cash_counts.*.employee_note' => 'nullable|string|max:1000',
            ]);

            $closingRequest = $this->sessionService->requestClosing(
                $request->user(),
                $data['cash_counts']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_closing_requested'),
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
                'transfers' => 'required|array',
                'transfers.*.currency' => 'required|string',
                'transfers.*.to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->sessionService->approveClosing(
                $request->user(),
                (int) $data['closing_request_id'],
                $data['transfers'],
                $data['review_notes'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_daily_closing_approved'),
                'closing_request' => $this->formatClosingRequest($closingRequest),
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
            if (! $this->canReviewCancellation($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $items = SalesCancellationRequest::query()
                ->with(['session.user', 'requestedBy'])
                ->where('status', 'pending')
                ->orderByDesc('id')
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
            if (! $this->canReviewCancellation($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'cancellation_request_id' => 'required|integer|exists:sales_cancellation_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $cancelRequest = SalesCancellationRequest::query()->findOrFail($data['cancellation_request_id']);
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
            if (! $this->canReviewCancellation($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'cancellation_request_id' => 'required|integer|exists:sales_cancellation_requests,id',
                'review_notes' => 'nullable|string|max:2000',
            ]);

            $cancelRequest = SalesCancellationRequest::query()->findOrFail($data['cancellation_request_id']);
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

        return [
            'id' => $request->id,
            'status' => $request->status,
            'requested_at' => $request->requested_at?->toDateTimeString(),
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'review_notes' => $request->review_notes,
            'instant_sales_count' => $request->instant_sales_count,
            'profit_sales_count' => $request->profit_sales_count,
            'cash_counts' => $request->cash_counts,
            'transfers' => $request->transfers,
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
