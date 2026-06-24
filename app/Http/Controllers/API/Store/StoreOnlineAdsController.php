<?php

namespace App\Http\Controllers\API\Store;

use Illuminate\Http\Request;

class StoreOnlineAdsController extends StoreBaseController
{
    public function getAllAds(Request $request)
    {
        return response()->json([
            'rows' => [],
            'total' => 0,
            'totalNotFiltered' => 0,
        ]);
    }
}
