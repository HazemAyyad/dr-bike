<?php

namespace App\Http\Controllers\API\Store;

use App\Events\Support\SupportConversationRead;
use App\Events\Support\SupportMessageCreated;
use App\Events\Support\SupportTypingUpdated;
use App\Http\Resources\OnlineStore\StorefrontListingResource;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\OnlineStore\StorefrontCatalogService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\Support\StoreSupportNotificationService;
use App\Services\Support\SupportMessageManager;
use App\Services\Support\SupportPayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StoreSupportConversationController extends StoreBaseController
{
    private const IMAGE_MAX_KB = 15360;

    public function __construct(
        private readonly StoreIdentityService $identity,
        private readonly StorefrontCatalogService $catalog,
        private readonly SupportMessageManager $messages,
        private readonly SupportPayloadService $payloads,
        private readonly StoreSupportNotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(SupportConversation::STATUSES)],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $query = SupportConversation::query()
            ->withCount('messages')
            ->where('source', SupportConversation::SOURCE_ONLINE_STORE)
            ->where('requester_user_id', $actor->getKey())
            ->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($validated['before_id'] ?? null, fn ($builder, $id) => $builder->whereKey('<', $id))
            ->orderByDesc('id');
        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;

        return response()->json([
            'status' => 'success',
            'conversations' => $rows->take($perPage)->map(fn ($row) => $this->payloads->conversation($row))->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function store(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'context_type' => ['required', Rule::in(SupportConversation::CONTEXT_TYPES)],
            'listing_id' => ['nullable', 'integer', 'min:1'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'client_message_id' => ['required', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['file', 'max:'.self::IMAGE_MAX_KB, 'mimes:jpg,jpeg,png,webp,heic,heif'],
        ]);
        abort_if(! $request->filled('message') && ! $request->hasFile('attachments'), 422, 'message or attachments are required');

        $listing = null;
        $snapshot = null;
        if ($validated['context_type'] === SupportConversation::CONTEXT_PRODUCT) {
            abort_unless(isset($validated['listing_id']), 422, 'listing_id is required for product support');
            $listing = OnlineStoreListing::query()
                ->with(['product', 'mediaPresentations'])
                ->findOrFail((int) $validated['listing_id']);
            abort_unless($this->catalog->isEligible($listing), 404);
            $snapshot = $this->productSnapshot($listing, $request);

            $existing = SupportConversation::query()
                ->withCount('messages')
                ->where('source', SupportConversation::SOURCE_ONLINE_STORE)
                ->where('requester_user_id', $actor->getKey())
                ->where('online_store_listing_id', $listing->getKey())
                ->whereIn('status', [SupportConversation::STATUS_OPEN, SupportConversation::STATUS_PENDING])
                ->latest('id')
                ->first();
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'resumed' => true,
                    'conversation' => $this->payloads->conversation($existing),
                ]);
            }
        }

        [$conversation, $message] = DB::transaction(function () use ($request, $validated, $actor, $listing, $snapshot) {
            $conversation = SupportConversation::query()->create([
                'source' => SupportConversation::SOURCE_ONLINE_STORE,
                'employee_id' => null,
                'created_by_user_id' => $actor->getKey(),
                'requester_user_id' => $actor->getKey(),
                'context_type' => $validated['context_type'],
                'online_store_listing_id' => $listing?->getKey(),
                'context_snapshot' => $snapshot,
                'subject' => filled($validated['subject'] ?? null)
                    ? trim((string) $validated['subject'])
                    : ($snapshot['name_ar'] ?? 'محادثة دعم عامة'),
                'status' => SupportConversation::STATUS_OPEN,
                'priority' => SupportConversation::PRIORITY_NORMAL,
            ]);
            $message = $this->messages->create(
                $conversation,
                $actor,
                SupportMessage::SENDER_STORE_CUSTOMER,
                $validated['message'] ?? null,
                $request->file('attachments', []),
                $validated['client_message_id'],
            );

            return [$conversation, $message];
        }, 3);

        $conversation = $conversation->fresh()->loadCount('messages');
        $messagePayload = $this->payloads->message($message, (int) $actor->getKey());
        $conversationPayload = $this->payloads->conversation($conversation);
        event(SupportMessageCreated::fromPayloads($conversation, $messagePayload, $conversationPayload));
        $this->notifications->afterMessage($conversation->load('requester'), $message);

        return response()->json([
            'status' => 'success',
            'resumed' => false,
            'conversation' => $conversationPayload,
            'support_message' => $messagePayload,
        ], 201);
    }

    public function show(Request $request, SupportConversation $conversation)
    {
        $actor = $this->actor($request);
        $this->authorizeOwner($conversation, $actor);
        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 40);
        $query = $conversation->messages()
            ->with(['attachments', 'senderUser:id,name', 'reactions.user:id,name'])
            ->when($validated['before_id'] ?? null, fn ($builder, $id) => $builder->whereKey('<', $id))
            ->when($validated['after_id'] ?? null, fn ($builder, $id) => $builder->whereKey('>', $id))
            ->orderByDesc('id');
        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->reverse()->values();

        return response()->json([
            'status' => 'success',
            'conversation' => $this->payloads->conversation($conversation->loadCount('messages')),
            'messages' => $rows->map(fn ($message) => $this->payloads->message($message, (int) $actor->getKey()))->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function sendMessage(Request $request, SupportConversation $conversation)
    {
        $actor = $this->actor($request);
        $this->authorizeOwner($conversation, $actor);
        abort_if($conversation->status === SupportConversation::STATUS_CLOSED, 422, 'conversation is closed');
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'client_message_id' => ['required', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['file', 'max:'.self::IMAGE_MAX_KB, 'mimes:jpg,jpeg,png,webp,heic,heif'],
        ]);
        abort_if(! $request->filled('message') && ! $request->hasFile('attachments'), 422, 'message or attachments are required');

        $existing = SupportMessage::query()
            ->where('support_conversation_id', $conversation->getKey())
            ->where('client_message_id', $validated['client_message_id'])
            ->first();
        $message = DB::transaction(fn () => $this->messages->create(
            $conversation,
            $actor,
            SupportMessage::SENDER_STORE_CUSTOMER,
            $validated['message'] ?? null,
            $request->file('attachments', []),
            $validated['client_message_id'],
        ), 3);
        $conversation = $conversation->fresh()->loadCount('messages');
        $messagePayload = $this->payloads->message($message, (int) $actor->getKey());
        $conversationPayload = $this->payloads->conversation($conversation);
        if (! $existing) {
            event(SupportMessageCreated::fromPayloads($conversation, $messagePayload, $conversationPayload));
            $this->notifications->afterMessage($conversation->load('requester'), $message);
        }

        return response()->json([
            'status' => 'success',
            'support_message' => $messagePayload,
            'conversation' => $conversationPayload,
        ], $existing ? 200 : 201);
    }

    public function markRead(Request $request, SupportConversation $conversation)
    {
        $actor = $this->actor($request);
        $this->authorizeOwner($conversation, $actor);
        $conversation->update([
            'requester_unread_count' => 0,
            'employee_unread_count' => $conversation->source === SupportConversation::SOURCE_EMPLOYEE
                ? 0
                : $conversation->employee_unread_count,
        ]);
        $payload = $this->payloads->conversation($conversation->fresh()->loadCount('messages'));
        event(new SupportConversationRead((int) $conversation->getKey(), $payload));

        return response()->json(['status' => 'success', 'conversation' => $payload]);
    }

    public function typing(Request $request, SupportConversation $conversation)
    {
        $actor = $this->actor($request);
        $this->authorizeOwner($conversation, $actor);
        $validated = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ]);

        event(new SupportTypingUpdated(
            (int) $conversation->getKey(),
            (string) $conversation->source,
            SupportMessage::SENDER_STORE_CUSTOMER,
            (string) ($actor->name ?? ''),
            (bool) $validated['is_typing'],
        ));

        return response()->json(['status' => 'success']);
    }

    public function unreadCount(Request $request)
    {
        $actor = $this->actor($request);
        $query = SupportConversation::query()
            ->where('source', SupportConversation::SOURCE_ONLINE_STORE)
            ->where('requester_user_id', $actor->getKey());

        return response()->json([
            'status' => 'success',
            'unread_conversations' => (clone $query)->where('requester_unread_count', '>', 0)->count(),
            'unread_messages' => (int) (clone $query)->sum('requester_unread_count'),
        ]);
    }

    private function actor(Request $request): User
    {
        $storeUser = $this->storeUserFromRequest($request);
        $actor = $storeUser ? User::query()->find($storeUser->getKey()) : null;
        if (! $actor
            || (method_exists($actor, 'trashed') && $actor->trashed())
            || $actor->is_blocked
            || $this->identity->activeLinks($actor)->isEmpty()) {
            abort(401, 'Unauthenticated.');
        }

        return $actor;
    }

    private function authorizeOwner(SupportConversation $conversation, User $actor): void
    {
        abort_unless(
            $conversation->source === SupportConversation::SOURCE_ONLINE_STORE
            && (int) $conversation->requester_user_id === (int) $actor->getKey(),
            404
        );
    }

    private function productSnapshot(OnlineStoreListing $listing, Request $request): array
    {
        $storefront = (new StorefrontListingResource($listing))->toArray($request);
        $names = (array) $listing->name_translations;
        $media = collect($storefront['media'] ?? [])->first();
        $primaryImage = is_array($media) ? ($media['path'] ?? null) : null;
        if (filled($primaryImage) && ! str_starts_with((string) $primaryImage, 'http://')
            && ! str_starts_with((string) $primaryImage, 'https://')) {
            $primaryImage = url(ltrim((string) $primaryImage, '/'));
        }

        return [
            'listing_id' => (int) $listing->getKey(),
            'product_id' => (int) $listing->product_id,
            'name_ar' => (string) ($names['ar'] ?? $listing->product?->nameAr ?? $storefront['display']['name'] ?? ''),
            'name_en' => (string) ($names['en'] ?? $listing->product?->nameEng ?? ''),
            'name_he' => (string) ($names['he'] ?? $listing->product?->nameAbree ?? ''),
            'primary_image' => $primaryImage,
            'model' => (string) ($listing->product?->model ?? ''),
        ];
    }
}
