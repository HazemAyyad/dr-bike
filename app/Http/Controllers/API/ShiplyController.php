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
}
