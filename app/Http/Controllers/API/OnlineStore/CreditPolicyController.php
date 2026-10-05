<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\StoreCreditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditPolicyController extends Controller
{
    private const PERMISSION = 'Online Store Settings Manage';

    public function update(Request $request, OnlineStoreAccountLink $link, StoreCreditService $credit)
    {
        $this->authorizeCreditResource($request, $link);
        $data = $request->validate([
            'is_eligible' => 'required|boolean',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => ['required', Rule::in(StoreCreditService::CURRENCIES)],
            'expires_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        return ['data' => $credit->savePolicy($request->user(), $link, $data)];
    }

    public function show(Request $request, OnlineStoreAccountLink $link, StoreCreditService $credit)
    {
        $this->authorizeCreditResource($request, $link);
        $currency = $request->validate(['currency' => ['nullable', Rule::in(StoreCreditService::CURRENCIES)]])['currency'] ?? null;

        return ['data' => $credit->summary($link, $currency)];
    }

    private function authorizeCreditResource(Request $request, OnlineStoreAccountLink $link): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorizeResource($user, self::PERMISSION, $link->exists)->authorize();
    }
}
