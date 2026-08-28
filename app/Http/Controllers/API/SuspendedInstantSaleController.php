<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SuspendedInstantSaleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SuspendedInstantSaleController extends Controller
{
    public function __construct(
        protected SuspendedInstantSaleService $service
    ) {}

    public function store(Request $request)
    {
        try {
            $record = $this->service->store($request->user(), $request);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.suspended_instant_sale_saved'),
                'suspended_instant_sale' => $this->service->formatDetail($record),
                'suspended_count' => $this->service->suspendedCountForUser($request->user()),
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

    public function index(Request $request)
    {
        try {
            $filters = $request->validate([
                'search' => 'nullable|string|max:255',
                'created_by_user_id' => 'nullable|integer|exists:users,id',
                'save_type' => 'nullable|string|in:manual,auto',
            ]);

            $items = $this->service->listForUser($request->user(), $filters);

            return response()->json([
                'status' => 'success',
                'suspended_instant_sales' => $items,
                'suspended_count' => count($items),
                'can_view_all' => ($filters['save_type'] ?? 'manual') === 'manual'
                    && $this->service->canViewAllSuspendedSales($request->user()),
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

    public function show(Request $request)
    {
        try {
            $data = $request->validate([
                'suspended_instant_sale_id' => 'required|integer|exists:suspended_instant_sales,id',
            ]);

            $record = $this->service->show($request->user(), (int) $data['suspended_instant_sale_id']);

            return response()->json([
                'status' => 'success',
                'suspended_instant_sale' => $this->service->formatDetail($record),
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

    public function cancel(Request $request)
    {
        try {
            $data = $request->validate([
                'suspended_instant_sale_id' => 'required|integer|exists:suspended_instant_sales,id',
            ]);

            $record = $this->service->cancel(
                $request->user(),
                (int) $data['suspended_instant_sale_id']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.suspended_instant_sale_cancelled'),
                'suspended_instant_sale' => $this->service->formatDetail($record),
                'suspended_count' => $this->service->suspendedCountForUser($request->user()),
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

    public function addNote(Request $request)
    {
        try {
            $data = $request->validate([
                'suspended_instant_sale_id' => 'required|integer|exists:suspended_instant_sales,id',
                'note' => 'required|string|max:2000',
            ]);

            $record = $this->service->addNote(
                $request->user(),
                (int) $data['suspended_instant_sale_id'],
                (string) $data['note']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.suspended_instant_sale_note_saved'),
                'suspended_instant_sale' => $this->service->formatDetail($record),
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

    public function complete(Request $request)
    {
        try {
            $result = $this->service->complete($request->user(), $request);

            $body = json_decode($result['response']->getContent(), true) ?? [];
            if (($body['status'] ?? '') === 'success') {
                $body['suspended_count'] = $this->service->suspendedCountForUser($request->user());
            }

            return response()->json($body, 200);
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
            Log::error('SuspendedInstantSaleController::complete error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => optional($request->user())->id,
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function count(Request $request)
    {
        try {
            $data = $request->validate([
                'save_type' => 'nullable|string|in:manual,auto',
            ]);

            return response()->json([
                'status' => 'success',
                'suspended_count' => $this->service->suspendedCountForUser(
                    $request->user(),
                    $data['save_type'] ?? 'manual'
                ),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
