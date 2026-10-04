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
        $filters = $request->validated();

        return OnlineStoreAccountLink::query()
            ->with(['user', 'customer', 'seller', 'linkedBy', 'verifiedBy'])
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['seller_id']), fn ($query) => $query->where('seller_id', $filters['seller_id']))
            ->when(isset($filters['role']), fn ($query) => $query->where('role', $filters['role']))
            ->when(isset($filters['account_source']), fn ($query) => $query->where('account_source', $filters['account_source']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $term = '%'.trim($filters['search']).'%';
                $query->where(function ($partyQuery) use ($term) {
                    $partyQuery->whereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', $term))
                        ->orWhereHas('seller', fn ($sellerQuery) => $sellerQuery->where('name', 'like', $term));
                });
            })
            ->orderByDesc('id')
            ->paginate();
    }

    public function store(ManageAccountLinkRequest $request, StoreIdentityService $identity)
    {
        $user = User::withTrashed()->findOrFail($request->integer('user_id'));

        return response()->json(['data' => $identity->save($request->user(), $user, $request->validated())], 201);
    }

    public function update(ManageAccountLinkRequest $request, OnlineStoreAccountLink $link, StoreIdentityService $identity)
    {
        $storeUser = $request->filled('user_id')
            ? User::withTrashed()->findOrFail($request->integer('user_id'))
            : $link->user;

        return ['data' => $identity->save($request->user(), $storeUser, $request->validated(), $link)];
    }
}
