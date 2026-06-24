<?php

namespace App\Http\Controllers\API\Store;

use Illuminate\Http\Request;

class StoreCommentsController extends StoreBaseController
{
    public function getAllCommentsToItem(Request $request)
    {
        return response()->json([
            'rows' => [],
            'total' => 0,
            'totalNotFiltered' => 0,
        ]);
    }

    public function manageComment(Request $request)
    {
        return response()->json([
            'message' => 'success',
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ]);
    }
}
