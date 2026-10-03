<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\OnlineStoreReviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    private const PERMISSION = 'Online Store Reviews Manage';

    public function index(Request $request, OnlineStoreReviewService $reviews)
    {
        $actor = $this->authorizeIndex($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(OnlineStoreReview::STATUSES)],
            'product_id' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|integer|min:1',
        ]);
        $paginator = OnlineStoreReview::query()->with(['product', 'customer', 'user', 'salesOrder', 'moderator'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($filters['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate();
        $paginator->getCollection()->transform(fn (OnlineStoreReview $review) => $reviews->adminPayload($review));

        return $paginator;
    }

    public function show(Request $request, OnlineStoreReview $review, OnlineStoreReviewService $reviews)
    {
        $this->authorizeReview($request, $review);

        return ['data' => $reviews->adminPayload($review)];
    }

    public function moderate(Request $request, OnlineStoreReview $review, OnlineStoreReviewService $reviews)
    {
        $actor = $this->authorizeReview($request, $review);
        $data = $request->validate([
            'status' => ['required', Rule::in([OnlineStoreReview::STATUS_PUBLISHED, OnlineStoreReview::STATUS_REJECTED])],
            'reason' => 'nullable|string|max:1000',
        ]);

        return ['data' => $reviews->adminPayload(
            $reviews->moderate($actor, $review, $data['status'], $data['reason'] ?? null)
        )];
    }

    private function authorizeIndex(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($user, self::PERMISSION)->authorize();

        return $user;
    }

    private function authorizeReview(Request $request, OnlineStoreReview $review): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorizeResource($user, self::PERMISSION, $review->exists)->authorize();

        return $user;
    }
}
