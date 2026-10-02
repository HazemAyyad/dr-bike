<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\ManageAccountLinkRequest;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\User;
use App\Services\OnlineStore\StoreIdentityService;

class AccountLinkController extends Controller
{
    public function index(ManageAccountLinkRequest $request)
    {
        return OnlineStoreAccountLink::query()->with(['user', 'customer', 'seller', 'linkedBy', 'verifiedBy'])->orderByDesc('id')->paginate();
    }

    public function store(ManageAccountLinkRequest $request, StoreIdentityService $identity)
    {
        $user = User::withTrashed()->findOrFail($request->integer('user_id'));

        return response()->json(['data' => $identity->save($request->user(), $user, $request->validated())], 201);
    }

    public function update(ManageAccountLinkRequest $request, OnlineStoreAccountLink $link, StoreIdentityService $identity)
    {
        return ['data' => $identity->save($request->user(), $link->user, $request->validated(), $link)];
    }
}
