<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\DiscoverStoreAccountsRequest;
use App\Http\Requests\OnlineStore\ManageAccountLinkRequest;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\User;
use App\Services\OnlineStore\StoreIdentityService;

class AccountLinkController extends Controller
{
    public function accounts(DiscoverStoreAccountsRequest $request)
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        return User::query()
            ->select(['id', 'name', 'email', 'phone', 'profile_image_path', 'is_blocked'])
            ->whereRaw('LOWER(type) = ?', ['user'])
            ->with(['onlineStoreAccountLinks' => function ($query) {
                $query->select([
                    'id', 'user_id', 'role', 'customer_id', 'seller_id',
                    'status', 'verified_at', 'account_source',
                ])->orderBy('role')->orderBy('id');
            }])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($identityQuery) use ($term) {
                    $identityQuery->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->through(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'profile_image_url' => $user->profile_image_path
                    ? asset('storage/'.ltrim($user->profile_image_path, '/'))
                    : null,
                'is_blocked' => (bool) $user->is_blocked,
                'is_linkable' => ! (bool) $user->is_blocked,
                'links' => $user->onlineStoreAccountLinks->map(fn (OnlineStoreAccountLink $link) => [
                    'id' => (int) $link->id,
                    'role' => $link->role,
                    'customer_id' => $link->customer_id !== null ? (int) $link->customer_id : null,
                    'seller_id' => $link->seller_id !== null ? (int) $link->seller_id : null,
                    'status' => $link->status,
                    'verified_at' => $link->verified_at?->toIso8601String(),
                    'account_source' => $link->account_source,
                ])->values()->all(),
            ]);
    }

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
