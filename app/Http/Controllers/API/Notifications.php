<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class Notifications extends Controller
{
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function pushNotification(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
                'title' => 'required|string',
                'body' => 'required|string',
                'data' => 'nullable|array',
            ]);

            $this->firebaseService->sendNotification(
                $request->token,
                $request->title,
                $request->body,
                $request->data ?? []
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.notificationSent'),
            ]);

        } catch (Exception $e) {
            Log::error('Push notification failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: __('messages.notificationFailed'),
            ], 500);
        }
    }
}
