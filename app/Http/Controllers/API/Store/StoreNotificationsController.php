<?php

namespace App\Http\Controllers\API\Store;

use Illuminate\Http\Request;

class StoreNotificationsController extends StoreBaseController
{
    public function getNotifications(Request $request)
    {
        return response()->json([
            'rows' => [],
            'total' => 0,
            'totalNotFiltered' => 0,
        ]);
    }

    public function editNotification(Request $request)
    {
        return response()->json([
            'message' => 'success',
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ]);
    }
}
