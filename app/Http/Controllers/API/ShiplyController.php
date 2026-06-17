<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShiplyController extends Controller
{
    public function addressOptions(Request $request, ShiplyService $shiply)
    {
        try {
            $mode = $request->query('mode');
            if ($mode !== null && ! in_array($mode, [ShiplySettings::MODE_TEST, ShiplySettings::MODE_LIVE], true)) {
                $mode = null;
            }

            return response()->json([
                'status' => 'success',
                'shiply_mode' => $mode ?? ShiplySettings::mode(),
                'cities' => $shiply->addressOptions($mode),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('shiply.address_options_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function calculateDeliveryFee(Request $request, ShiplyService $shiply)
    {
        try {
            $data = $request->validate([
                'village_id' => 'required|integer|min:1',
                'price' => 'nullable|numeric|min:0',
            ]);

            $fees = $shiply->calculateDeliveryCost(
                (int) $data['village_id'],
                (float) ($data['price'] ?? 0)
            );

            return response()->json([
                'status' => 'success',
                'fees' => $fees,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('shiply.calculate_fee_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.shiply_request_failed'),
            ], 200);
        }
    }
}
