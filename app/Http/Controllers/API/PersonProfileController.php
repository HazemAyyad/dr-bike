<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PersonProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PersonProfileController extends Controller
{
    public function show(Request $request, PersonProfileService $service)
    {
        try {
            $data = $request->validate([
                'person_type' => 'required|string|in:customer,seller',
                'person_id' => 'required|integer|min:1',
            ]);

            return response()->json([
                'status' => 'success',
                'profile' => $service->getProfile(
                    $data['person_type'],
                    (int) $data['person_id']
                ),
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
            Log::error('PersonProfileController::show', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function productHistory(Request $request, PersonProfileService $service)
    {
        try {
            $data = $request->validate([
                'person_type' => 'required|string|in:customer,seller',
                'person_id' => 'required|integer|min:1',
                'product_id' => 'required|integer|exists:products,id',
                'size_color_id' => 'nullable|integer|min:1',
            ]);

            return response()->json([
                'status' => 'success',
                ...$service->getProductHistory(
                    $data['person_type'],
                    (int) $data['person_id'],
                    (int) $data['product_id'],
                    isset($data['size_color_id']) ? (int) $data['size_color_id'] : null
                ),
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
            Log::error('PersonProfileController::productHistory', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
