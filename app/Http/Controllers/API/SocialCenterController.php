<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Customer;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Meta\MetaMessagingService;
use App\Services\Social\LinkPreviewService;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Services\WhatsApp\WhatsAppCommerceMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialCenterController extends Controller
{
    public function dashboard()
    {
        return $this->ok([
            'total_contacts' => $this->activeWhatsAppConversations()->distinct()->count('whatsapp_contact_id')
                + DB::table('social_contacts')->count(),
            'total_conversations' => $this->activeWhatsAppConversations()->count() + SocialConversation::query()->count(),
            'open_conversations' => $this->activeWhatsAppConversations()->where('status', 'open')->count()
                + SocialConversation::query()->where('status', 'open')->count(),
            'unread_conversations' => $this->activeWhatsAppConversations()->where('unread_count', '>', 0)->count()
                + SocialConversation::query()->where('unread_count', '>', 0)->count(),
            'messages_today' => $this->activeWhatsAppMessages()->whereDate('created_at', today())->count()
                + SocialMessage::query()->whereDate('created_at', today())->count(),
            'failed_messages_today' => $this->activeWhatsAppMessages()->where('status', 'failed')->whereDate('created_at', today())->count()
                + SocialMessage::query()->where('status', 'failed')->whereDate('created_at', today())->count(),
            'channel_stats' => [
                $this->channelStats('whatsapp'),
                $this->channelStats('facebook'),
                $this->channelStats('instagram'),
            ],
        ], 'dashboard');
    }

    public function conversations(Request $request)
    {
        $status = $request->input('status');
        if (filled($status)) {
            $request->validate(['status' => 'in:open,pending,closed']);
        }
        $channel = $request->input('channel', 'all');
        $request->validate(['channel' => 'nullable|in:all,whatsapp,facebook,instagram']);
        $allowedChannels = $this->allowedChannels($request);
        abort_if($channel !== 'all' && ! in_array($channel, $allowedChannels, true), 403);
        $quickFilter = $request->input('quick_filter', 'all');
        $request->validate(['quick_filter' => 'nullable|in:all,unread,failed,linked,needs_reply,assigned_me']);
        $search = trim((string) $request->input('search'));

        $items = collect();
        if (in_array('whatsapp', $allowedChannels, true) && in_array($channel, ['all', 'whatsapp'], true)) {
            $query = $this->activeWhatsAppConversations()
                ->with(['contact', 'whatsappAccount', 'latestMessage', 'assignedAdmin'])
                ->withCount(['messages as failed_count' => fn ($messages) => $messages->where('status', 'failed')]);
            if (filled($status)) $query->where('status', $status);
            $this->applyQuickFilter($query, $quickFilter, $request->user()->id);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('phone', 'like', "%{$search}%")
                        ->orWhere('last_message', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$search}%"));
                });
            }
            if ($channel === 'whatsapp') {
                $paginator = $query
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->paginate($this->perPage($request, 20))
                    ->through(fn ($item) => $this->serializeWhatsAppConversation($item));

                return $this->ok($paginator, 'conversations');
            }
            $items = $items->merge($query->latest('last_message_at')->limit(80)->get()->map(fn ($item) => $this->serializeWhatsAppConversation($item)));
        }

        if (array_intersect(['facebook', 'instagram'], $allowedChannels) && in_array($channel, ['all', 'facebook', 'instagram'], true)) {
            $query = SocialConversation::query()
                ->with(['contact', 'latestMessage', 'assignedAdmin'])
                ->withCount(['messages as failed_count' => fn ($messages) => $messages->where('status', 'failed')]);
            if ($channel !== 'all') $query->where('channel', $channel);
            else $query->whereIn('channel', $allowedChannels);
            if (filled($status)) $query->where('status', $status);
            $this->applyQuickFilter($query, $quickFilter, $request->user()->id);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('last_message', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn ($contact) => $contact
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('external_id', 'like', "%{$search}%"));
                });
            }
            $items = $items->merge($query->latest('last_message_at')->limit(80)->get()->map(fn ($item) => $this->serializeSocialConversation($item)));
        }

        $sorted = $items
            ->sortByDesc(fn ($item) => (string) ($item['last_message_at'] ?? ''))
            ->values()
            ->take($this->perPage($request, 50));

        return $this->ok([
            'data' => $sorted,
            'current_page' => 1,
            'per_page' => $sorted->count(),
            'total' => $sorted->count(),
        ], 'conversations');
    }

    public function showConversation(Request $request, string $channel, int $id)
    {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);

        if ($channel === 'whatsapp') {
            $conversation = $this->activeWhatsAppConversations()->with(['contact', 'whatsappAccount'])->findOrFail($id);
            $hiddenIds = DB::table('whatsapp_message_user_hides')
                ->where('user_id', $request->user()->id)
                ->pluck('whatsapp_message_id');
            $messages = $conversation->messages()
                ->whereNotIn('id', $hiddenIds)
                ->with(['replyTo:id,message_type,body,direction,media_url', 'sender:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request, 30));
        } else {
            $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
            $messages = $conversation->messages()
                ->with(['sender:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request, 30));
        }
        $messages = $this->decorateMessagesPaginator(
            $messages,
            $channel,
            (int) $request->user()->id
        );

        $lastInboundAt = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->value('created_at');
        $windowExpiresAt = $lastInboundAt ? \Carbon\Carbon::parse($lastInboundAt)->addHours(24) : null;
        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'status' => 'success',
            'conversation' => $channel === 'whatsapp'
                ? $this->serializeWhatsAppConversation($conversation->fresh('contact'))
                : $this->serializeSocialConversation($conversation->fresh('contact')),
            'messages' => $messages,
            'customer_service_window' => [
                'open' => $windowExpiresAt?->isFuture() === true,
                'last_inbound_at' => $lastInboundAt,
                'expires_at' => $windowExpiresAt?->toIso8601String(),
            ],
            'assignees' => $this->messageAssignees(),
            'available_tags' => $this->availableTags(),
            'meta_app_status' => $this->metaAppStatus(),
        ]);
    }

    public function resendMessage(
        Request $request,
        string $channel,
        int $id,
        int $messageId,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);

        try {
            if ($channel === 'whatsapp') {
                $conversation = $this->activeWhatsAppConversations()->with('whatsappAccount')->findOrFail($id);
                $message = WhatsAppMessage::query()
                    ->where('whatsapp_conversation_id', $conversation->id)
                    ->where('id', $messageId)
                    ->firstOrFail();
                $this->ensureResendableText($message->direction, $message->status, $message->message_type, $message->body);
                $this->ensureCustomerServiceWindow($conversation);
                $whatsApp = $this->whatsAppForConversation($whatsApp, $conversation);
                $result = $whatsApp->sendText($conversation->phone, (string) $message->body, $request->user()->id);
            } else {
                $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
                $message = SocialMessage::query()
                    ->where('social_conversation_id', $conversation->id)
                    ->where('id', $messageId)
                    ->firstOrFail();
                $this->ensureResendableText($message->direction, $message->status, $message->message_type, $message->body);
                $this->ensureCustomerServiceWindow($conversation);
                $result = $meta->sendText($conversation, (string) $message->body, $request->user()->id);
            }

            $this->claimAfterReply($conversation, (int) $request->user()->id);
            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function messageAction(
        Request $request,
        string $channel,
        int $id,
        int $messageId,
        WhatsAppCloudApiService $whatsApp
    )
    {
        $this->authorizeChannel($request, $channel);
        $message = $this->conversationMessage($channel, $id, $messageId);
        $data = $request->validate([
            'action' => 'required|in:pin,unpin,star,unstar,react,report',
            'reaction' => 'nullable|string|max:16',
            'reason' => 'nullable|string|max:500',
        ]);
        $userId = (int) $request->user()->id;

        if ($data['action'] === 'pin' || $data['action'] === 'unpin') {
            $message->update($data['action'] === 'pin'
                ? ['pinned_at' => now(), 'pinned_by' => $userId]
                : ['pinned_at' => null, 'pinned_by' => null]);
        } elseif ($data['action'] === 'react' && $channel === 'whatsapp') {
            abort_if(blank($message->meta_message_id), 422, 'لا يمكن إرسال تفاعل لهذه الرسالة لأنها غير مرتبطة برسالة واتساب.');
            $conversation = $this->activeWhatsAppConversations()
                ->with('whatsappAccount')
                ->findOrFail($id);
            $emoji = filled($data['reaction'] ?? null) ? $data['reaction'] : '';
            try {
                $this->whatsAppForConversation($whatsApp, $conversation)
                    ->sendReaction($conversation->phone, $message->meta_message_id, $emoji);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['reaction' => [$e->getMessage()]]);
            }
            $message->update(['reaction' => $emoji !== '' ? $emoji : null]);
        } elseif (in_array($data['action'], ['star', 'unstar', 'react'], true)) {
            $values = ['updated_at' => now()];
            if ($data['action'] === 'star') {
                $values['starred'] = true;
            }
            if ($data['action'] === 'unstar') {
                $values['starred'] = false;
            }
            if ($data['action'] === 'react') {
                $values['reaction'] = filled($data['reaction'] ?? null) ? $data['reaction'] : null;
            }
            DB::table('social_message_user_states')->updateOrInsert(
                ['user_id' => $userId, 'channel' => $channel, 'message_id' => $messageId],
                ['created_at' => now()] + $values
            );
        } else {
            DB::table('social_message_reports')->updateOrInsert(
                ['reported_by' => $userId, 'channel' => $channel, 'message_id' => $messageId],
                ['reason' => $data['reason'] ?? null, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->ok($this->decorateMessage($channel, $message->fresh(), $userId), 'message');
    }

    public function forwardMessage(
        Request $request,
        string $channel,
        int $id,
        int $messageId,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        $this->authorizeChannel($request, $channel);
        $message = $this->conversationMessage($channel, $id, $messageId);
        $data = $request->validate([
            'target_channel' => 'required|in:whatsapp,facebook,instagram',
            'target_conversation_id' => 'required|integer|min:1',
        ]);
        $targetChannel = $data['target_channel'];
        $this->authorizeChannel($request, $targetChannel);
        $text = trim((string) ($message->body ?: $message->media_url));
        abort_if($text === '', 422, 'لا يمكن تحويل هذه الرسالة لأنها لا تحتوي نصًا أو رابط وسائط.');

        if ($targetChannel === 'whatsapp') {
            $target = $this->activeWhatsAppConversations()->with('whatsappAccount')->findOrFail($data['target_conversation_id']);
            $this->ensureCustomerServiceWindow($target);
            $result = $this->whatsAppForConversation($whatsApp, $target)
                ->sendText($target->phone, $text, $request->user()->id);
        } else {
            $target = SocialConversation::query()->with('contact')
                ->where('channel', $targetChannel)->findOrFail($data['target_conversation_id']);
            $this->ensureCustomerServiceWindow($target);
            $result = $meta->sendText($target, $text, $request->user()->id);
        }
        $this->claimAfterReply($target, (int) $request->user()->id);
        return $this->sendResult($targetChannel, $result);
    }

    public function assignConversation(Request $request, string $channel, int $id)
    {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'employee_id' => 'nullable|integer|exists:employee_details,id',
        ]);

        $conversation = $this->conversationForChannel($channel, $id);
        $employee = filled($data['employee_id'] ?? null)
            ? EmployeeDetail::query()->with('user:id,name')->findOrFail($data['employee_id'])
            : null;

        $conversation->update(['assigned_admin_id' => $employee?->user_id]);

        return response()->json([
            'status' => 'success',
            'conversation' => $this->serializeConversationForChannel($channel, $conversation->fresh(['contact', 'assignedAdmin'])),
        ]);
    }

    public function updateTags(Request $request, string $channel, int $id)
    {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'tags' => 'present|array|max:10',
            'tags.*' => 'nullable|string|max:40',
        ]);

        $conversation = $this->conversationForChannel($channel, $id);
        $tagIds = collect($data['tags'])
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn ($tag) => mb_strtolower($tag))
            ->take(10)
            ->map(fn ($tag) => $this->findOrCreateTagId($tag))
            ->filter()
            ->values();

        DB::transaction(function () use ($channel, $conversation, $tagIds) {
            DB::table('conversation_taggables')
                ->where('channel', $channel)
                ->where('conversation_id', $conversation->id)
                ->delete();

            foreach ($tagIds as $tagId) {
                DB::table('conversation_taggables')->insert([
                    'tag_id' => $tagId,
                    'channel' => $channel,
                    'conversation_id' => $conversation->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'conversation' => $this->serializeConversationForChannel($channel, $conversation->fresh(['contact', 'assignedAdmin'])),
            'available_tags' => $this->availableTags(),
        ]);
    }

    public function sendToConversation(
        Request $request,
        string $channel,
        int $id,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'message' => 'required|string|max:4096',
            'reply_to_message_id' => 'nullable|integer',
            'client_message_id' => 'nullable|string|max:100',
        ]);

        try {
            if ($channel === 'whatsapp') {
                $conversation = $this->activeWhatsAppConversations()->with('whatsappAccount')->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $whatsApp = $this->whatsAppForConversation($whatsApp, $conversation);
                $replyTo = isset($data['reply_to_message_id'])
                    ? $conversation->messages()->findOrFail($data['reply_to_message_id'])
                    : null;
                $result = $whatsApp->sendText(
                    $conversation->phone,
                    $data['message'],
                    $request->user()->id,
                    $replyTo,
                    false,
                    $data['client_message_id'] ?? null
                );
            } else {
                abort_unless(in_array($channel, ['facebook', 'instagram'], true), 404);
                $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $result = $meta->sendText(
                    $conversation,
                    $data['message'],
                    $request->user()->id,
                    $data['client_message_id'] ?? null
                );
            }

            $this->claimAfterReply($conversation, (int) $request->user()->id);
            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function sendMediaToConversation(
        Request $request,
        string $channel,
        int $id,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'file' => 'required|file|max:16384|mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,mp3,m4a,ogg,wav,mp4,mov',
            'caption' => 'nullable|string|max:1024',
            'media_kind' => 'nullable|in:image,audio,video,document',
            'duration_seconds' => 'nullable|integer|min:1|max:7200',
            'voice_note' => 'nullable|boolean',
        ]);

        try {
            if ($channel === 'whatsapp') {
                $conversation = $this->activeWhatsAppConversations()->with('whatsappAccount')->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $whatsApp = $this->whatsAppForConversation($whatsApp, $conversation);
                $result = $whatsApp->sendMedia(
                    $conversation->phone,
                    $data['file'],
                    $data['caption'] ?? null,
                    $request->user()->id,
                    $data['media_kind'] ?? null,
                    $data['duration_seconds'] ?? null,
                    (bool) ($data['voice_note'] ?? false)
                );
            } else {
                $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $result = $meta->sendMedia(
                    $conversation,
                    $data['file'],
                    $data['caption'] ?? null,
                    $request->user()->id,
                    $data['media_kind'] ?? null
                );
            }

            $this->claimAfterReply($conversation, (int) $request->user()->id);
            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function sendProductsToConversation(
        Request $request,
        string $channel,
        int $id,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        $this->authorizeChannel($request, $channel);
        $data = $request->validate([
            'product_ids' => 'required|array|min:1|max:30',
            'product_ids.*' => 'required|string',
            'quantities' => 'nullable|array',
            'quantities.*' => 'required|integer|min:1|max:999',
            'items' => 'nullable|array|min:1|max:30',
            'items.*.product_id' => 'required|string',
            'items.*.size_color_id' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ]);

        if ($channel === 'whatsapp') {
            return app(WhatsAppController::class)->sendProducts($request, $id, $whatsApp);
        }

        try {
            $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
            $this->ensureCustomerServiceWindow($conversation);
            $result = $this->sendSocialProducts($meta, $conversation, $data['product_ids'], $request->user()->id);
            $this->claimAfterReply($conversation, (int) $request->user()->id);
            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function prepareWhatsAppCommerceDraft(
        Request $request,
        int $id,
        int $messageId,
        WhatsAppCommerceMessageService $commerce
    ) {
        $this->authorizeChannel($request, 'whatsapp');
        $data = $request->validate(['target' => 'required|in:instant_sale,sales_order']);
        $conversation = $this->activeWhatsAppConversations()
            ->with(['contact.customer'])
            ->findOrFail($id);
        $message = $conversation->messages()->findOrFail($messageId);
        $details = $commerce->details($message);

        if (! $details || $details['kind'] !== 'order') {
            throw ValidationException::withMessages([
                'message' => ['هذه الرسالة ليست سلة منتجات مرسلة من الزبون.'],
            ]);
        }
        if (! $details['can_convert']) {
            throw ValidationException::withMessages([
                'message' => ['تعذر ربط منتج أو أكثر من السلة بمنتجات المخزون. راجع مزامنة كتالوج Meta.'],
            ]);
        }

        $customer = DB::transaction(function () use ($conversation) {
            if ($conversation->contact?->customer) {
                return $conversation->contact->customer;
            }

            $digits = preg_replace('/\D+/', '', (string) $conversation->phone) ?: '';
            $suffix = substr($digits, -9);
            $customer = $suffix === '' ? null : Customer::query()
                ->where('phone', 'like', '%'.$suffix)
                ->limit(20)
                ->get()
                ->first(function (Customer $item) use ($suffix) {
                    $candidate = preg_replace('/\D+/', '', (string) $item->phone) ?: '';

                    return $candidate !== '' && substr($candidate, -9) === $suffix;
                });
            $name = trim((string) ($conversation->contact?->name ?: 'زبون واتساب '.substr($digits, -4)));
            $customer ??= Customer::query()->create([
                'name' => $name,
                'phone' => $this->erpWhatsAppPhone($digits),
                'type' => 'retail',
            ]);
            $conversation->contact?->update([
                'name' => $conversation->contact?->name ?: $customer->name,
                'customer_id' => $customer->id,
                'supplier_id' => null,
            ]);

            return $customer;
        });

        return response()->json([
            'status' => 'success',
            'draft' => [
                'source' => 'whatsapp_catalog_order',
                'target' => $data['target'],
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer' => [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'items' => collect($details['items'])->map(fn ($item) => [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'size_color_id' => $item['size_color_id'],
                    'size_id' => $item['size_id'],
                    'size_label' => $item['size_label'],
                    'color_label' => $item['color_label'],
                    'variant_label' => $item['variant_label'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'stock' => $item['stock'],
                    'image' => $item['image'],
                ])->values(),
            ],
        ]);
    }

    private function serializeWhatsAppConversation(WhatsAppConversation $conversation): array
    {
        $latestMessage = $conversation->latestMessage;
        return [
            'id' => $conversation->id,
            'channel' => 'whatsapp',
            'phone' => $conversation->phone,
            'status' => $conversation->status,
            'last_message' => $conversation->last_message,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $conversation->unread_count,
            'failed_count' => (int) ($conversation->failed_count ?? $conversation->messages()->where('status', 'failed')->count()),
            'last_message_type' => $latestMessage?->message_type,
            'last_message_id' => $latestMessage?->id,
            'last_message_direction' => $latestMessage?->direction,
            'last_message_status' => $latestMessage?->status,
            'last_message_media' => $latestMessage
                ? $this->messageMedia($latestMessage->message_type, $latestMessage->media_url, $latestMessage->body, $latestMessage->raw_payload)
                : null,
            'needs_reply' => $this->needsReply($conversation, 'whatsapp'),
            'assigned_employee' => $this->assignedEmployee($conversation->assignedAdmin),
            'tags' => $this->conversationTags('whatsapp', $conversation->id),
            'whatsapp_account' => $conversation->whatsappAccount ? [
                'id' => $conversation->whatsappAccount->id,
                'name' => $conversation->whatsappAccount->name,
                'display_phone_number' => $conversation->whatsappAccount->display_phone_number,
            ] : null,
            'contact' => $conversation->contact,
        ];
    }

    private function serializeSocialConversation(SocialConversation $conversation): array
    {
        $latestMessage = $conversation->latestMessage;
        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'phone' => $conversation->contact?->external_id ?: '',
            'status' => $conversation->status,
            'last_message' => $conversation->last_message,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $conversation->unread_count,
            'failed_count' => (int) ($conversation->failed_count ?? $conversation->messages()->where('status', 'failed')->count()),
            'last_message_type' => $latestMessage?->message_type,
            'last_message_id' => $latestMessage?->id,
            'last_message_direction' => $latestMessage?->direction,
            'last_message_status' => $latestMessage?->status,
            'last_message_media' => $latestMessage
                ? $this->messageMedia($latestMessage->message_type, $latestMessage->media_url, $latestMessage->body, $latestMessage->raw_payload)
                : null,
            'needs_reply' => $this->needsReply($conversation, $conversation->channel),
            'assigned_employee' => $this->assignedEmployee($conversation->assignedAdmin),
            'tags' => $this->conversationTags($conversation->channel, $conversation->id),
            'contact' => [
                'id' => $conversation->contact?->id,
                'name' => $conversation->contact?->name,
                'phone' => $conversation->contact?->external_id,
                'external_id' => $conversation->contact?->external_id,
                'profile_picture_url' => $conversation->contact?->profile_picture_url,
                'customer_id' => $conversation->contact?->customer_id,
                'supplier_id' => $conversation->contact?->supplier_id,
                'raw_profile' => $conversation->contact?->raw_profile,
            ],
        ];
    }

    private function serializeWhatsAppMessage(WhatsAppMessage $message): array
    {
        return array_merge($message->toArray(), [
            'channel' => 'whatsapp',
            'link_url' => LinkPreviewService::firstUrl($message->body),
            'media' => $this->messageMedia($message->message_type, $message->media_url, $message->body, $message->raw_payload),
            'commerce' => app(WhatsAppCommerceMessageService::class)->details($message),
        ]);
    }

    private function serializeSocialMessage(SocialMessage $message): array
    {
        return [
            'id' => $message->id,
            'channel' => $message->channel,
            'direction' => $message->direction,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'media_url' => $message->media_url,
            'meta_message_id' => $message->meta_message_id,
            'meta_status' => $message->meta_status,
            'status' => $message->status,
            'error_message' => $message->error_message,
            'client_message_id' => $message->client_message_id,
            'link_url' => LinkPreviewService::firstUrl($message->body),
            'media' => $this->messageMedia($message->message_type, $message->media_url, $message->body, $message->raw_payload),
            'sender' => $message->sender,
            'raw_payload' => $message->raw_payload,
            'response_payload' => $message->response_payload,
            'created_at' => $message->created_at,
        ];
    }

    private function conversationMessage(string $channel, int $conversationId, int $messageId)
    {
        abort_unless(in_array($channel, ['whatsapp', 'facebook', 'instagram'], true), 404);
        if ($channel === 'whatsapp') {
            $conversation = $this->activeWhatsAppConversations()->findOrFail($conversationId);
        } else {
            $conversation = SocialConversation::query()->where('channel', $channel)->findOrFail($conversationId);
        }
        return $conversation->messages()->findOrFail($messageId);
    }

    private function decorateMessage(string $channel, $message, int $userId): array
    {
        $payload = $channel === 'whatsapp'
            ? $this->serializeWhatsAppMessage($message)
            : $this->serializeSocialMessage($message);
        $state = DB::table('social_message_user_states')
            ->where(['user_id' => $userId, 'channel' => $channel, 'message_id' => $message->id])
            ->first();
        $payload['pinned'] = $message->pinned_at !== null;
        $payload['starred'] = (bool) ($state->starred ?? false);
        $payload['reaction'] = $channel === 'whatsapp'
            ? $message->reaction
            : ($state->reaction ?? null);
        $payload['reported'] = DB::table('social_message_reports')
            ->where(['reported_by' => $userId, 'channel' => $channel, 'message_id' => $message->id])
            ->exists();
        return $payload;
    }

    private function decorateMessagesPaginator($messages, string $channel, int $userId)
    {
        $ids = $messages->getCollection()->pluck('id')->all();
        $states = DB::table('social_message_user_states')
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->whereIn('message_id', $ids)
            ->get()
            ->keyBy('message_id');
        $reportedIds = DB::table('social_message_reports')
            ->where('reported_by', $userId)
            ->where('channel', $channel)
            ->whereIn('message_id', $ids)
            ->pluck('message_id')
            ->flip();

        return $messages->through(function ($message) use ($channel, $states, $reportedIds) {
            $payload = $channel === 'whatsapp'
                ? $this->serializeWhatsAppMessage($message)
                : $this->serializeSocialMessage($message);
            $state = $states->get($message->id);
            $payload['pinned'] = $message->pinned_at !== null;
            $payload['starred'] = (bool) ($state->starred ?? false);
            $payload['reaction'] = $channel === 'whatsapp'
                ? $message->reaction
                : ($state->reaction ?? null);
            $payload['reported'] = $reportedIds->has($message->id);
            return $payload;
        });
    }

    public function linkPreview(Request $request, LinkPreviewService $previews)
    {
        $data = $request->validate(['url' => 'required|url:http,https|max:2048']);

        return $this->ok($previews->preview($data['url']), 'preview');
    }

    private function messageMedia(string $type, ?string $url, ?string $body, ?array $rawPayload): ?array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true) || ! $url) {
            return null;
        }

        $payload = (array) data_get($rawPayload, $type, []);

        return array_filter([
            'kind' => $type,
            'url' => $url,
            'caption' => $body,
            'mime_type' => data_get($payload, 'mime_type'),
            'filename' => data_get($payload, 'filename') ?: ($type === 'document' ? $body : null),
            'file_size' => data_get($payload, 'file_size'),
            'duration_seconds' => data_get($payload, 'duration'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function ok($value, string $key) { return response()->json(['status' => 'success', $key => $value]); }

    private function claimAfterReply($conversation, int $userId): void
    {
        DB::table($conversation->getTable())
            ->where('id', $conversation->getKey())
            ->whereNull('assigned_admin_id')
            ->update([
                'assigned_admin_id' => $userId,
                'updated_at' => now(),
            ]);
    }

    private function perPage(Request $request, int $default = 20): int { return min(max((int) $request->input('per_page', $default), 1), 100); }

    private function erpWhatsAppPhone(string $digits): string
    {
        return $digits === '' ? '' : '+'.substr($digits, 0, 3).' '.substr($digits, 3);
    }

    private function channelStats(string $channel): array
    {
        if ($channel === 'whatsapp') {
            return [
                'channel' => 'whatsapp',
                'contacts' => $this->activeWhatsAppConversations()->distinct()->count('whatsapp_contact_id'),
                'conversations' => $this->activeWhatsAppConversations()->count(),
                'open' => $this->activeWhatsAppConversations()->where('status', 'open')->count(),
                'unread' => $this->activeWhatsAppConversations()->where('unread_count', '>', 0)->count(),
                'messages_today' => $this->activeWhatsAppMessages()->whereDate('created_at', today())->count(),
                'failed_today' => $this->activeWhatsAppMessages()->where('status', 'failed')->whereDate('created_at', today())->count(),
            ];
        }

        return [
            'channel' => $channel,
            'contacts' => DB::table('social_contacts')->where('channel', $channel)->count(),
            'conversations' => SocialConversation::query()->where('channel', $channel)->count(),
            'open' => SocialConversation::query()->where('channel', $channel)->where('status', 'open')->count(),
            'unread' => SocialConversation::query()->where('channel', $channel)->where('unread_count', '>', 0)->count(),
            'messages_today' => SocialMessage::query()->where('channel', $channel)->whereDate('created_at', today())->count(),
            'failed_today' => SocialMessage::query()->where('channel', $channel)->where('status', 'failed')->whereDate('created_at', today())->count(),
        ];
    }

    private function applyQuickFilter($query, string $quickFilter, int $userId): void
    {
        match ($quickFilter) {
            'unread' => $query->where('unread_count', '>', 0),
            'failed' => $query->whereHas('messages', fn ($messages) => $messages->where('status', 'failed')),
            'linked' => $query->whereHas('contact', fn ($contact) => $contact
                ->whereNotNull('customer_id')
                ->orWhereNotNull('supplier_id')),
            'needs_reply' => $this->applyNeedsReplyFilter($query),
            'assigned_me' => $query->where('assigned_admin_id', $userId),
            default => null,
        };
    }

    private function applyNeedsReplyFilter($query): void
    {
        $model = $query->getModel();
        $conversationTable = $model->getTable();
        $messageTable = $model instanceof WhatsAppConversation ? 'whatsapp_messages' : 'social_messages';
        $conversationKey = $model instanceof WhatsAppConversation ? 'whatsapp_conversation_id' : 'social_conversation_id';

        $query->whereRaw(
            "(SELECT MAX(created_at) FROM {$messageTable} WHERE {$messageTable}.{$conversationKey} = {$conversationTable}.id AND direction = ?) > COALESCE((SELECT MAX(created_at) FROM {$messageTable} WHERE {$messageTable}.{$conversationKey} = {$conversationTable}.id AND direction = ?), '1000-01-01')",
            ['inbound', 'outbound']
        );
    }

    private function sendResult(string $channel, array $result)
    {
        $message = data_get($result, 'message');
        if ($message instanceof WhatsAppMessage) {
            $result['message'] = $this->serializeWhatsAppMessage($message->loadMissing(['sender', 'replyTo.sender']));
        } elseif ($message instanceof SocialMessage) {
            $result['message'] = $this->serializeSocialMessage($message->loadMissing('sender'));
        }
        $failed = data_get($result, 'message.status') === 'failed';
        if ($failed) {
            return response()->json([
                'status' => 'error',
                'message' => $this->outboundFailureMessage($channel, (string) data_get($result, 'message.error_message')),
                'failed_message_id' => data_get($result, 'message.id'),
                'failed_message' => data_get($result, 'message'),
                'api_response' => data_get($result, 'api_response'),
            ], 422);
        }

        return response()->json(['status' => 'success'] + $result);
    }

    private function socialProductsMessage(array $productIds): string
    {
        $products = $this->socialProducts($productIds);
        abort_if($products->count() !== count(array_unique($productIds)), 422, 'Some products were not found.');

        $order = array_flip(array_map('strval', $productIds));
        $lines = $products->count() === 1
            ? ['تفاصيل المنتج من دكتور بايك:']
            : ['منتجات دكتور بايك:'];

        foreach ($products->sortBy(fn (Product $product) => $order[(string) $product->id] ?? PHP_INT_MAX) as $product) {
            $lines[] = $this->socialProductLine($product);
        }

        return implode("\n\n", $lines);
    }

    private function sendSocialProducts(MetaMessagingService $meta, SocialConversation $conversation, array $productIds, ?int $adminId): array
    {
        $products = $this->socialProducts($productIds);
        abort_if($products->count() !== count(array_unique($productIds)), 422, 'Some products were not found.');

        if ($products->count() === 1) {
            $product = $products->first();
            $imageUrl = $this->publicProductImageUrl($product);
            if ($imageUrl) {
                $imageResult = $meta->sendImageUrl(
                    $conversation,
                    $imageUrl,
                    $product->nameAr ?: $product->nameEng ?: 'صورة منتج',
                    $adminId
                );
                if (data_get($imageResult, 'message.status') === 'failed') {
                    return $imageResult;
                }
            }
        }

        return $meta->sendText($conversation, $this->socialProductsMessage($productIds), $adminId);
    }

    private function socialProducts(array $productIds)
    {
        return Product::query()
            ->with(['normalImages' => fn ($query) => $query->select('id', 'itemId', 'imageUrl')])
            ->whereIn('id', $productIds)
            ->get();
    }

    private function socialProductLine(Product $product): string
    {
        $name = $product->nameAr ?: $product->nameEng ?: 'منتج';
        $lines = ['- '.$name];
        if ($product->normailPrice !== null) {
            $lines[] = 'السعر: '.$product->normailPrice.' ₪';
        }
        if ($product->product_code) {
            $lines[] = 'الكود: '.$product->product_code;
        }
        if ($product->stock !== null) {
            $lines[] = 'المتوفر: '.$product->stock;
        }
        if ($product->model) {
            $lines[] = 'الموديل: '.$product->model;
        }
        $lines[] = 'الرابط: '.$this->productPublicUrl($product);

        return implode("\n", $lines);
    }

    private function productPublicUrl(Product $product): string
    {
        return rtrim((string) (config('meta_commerce.public_url') ?: config('app.url')), '/').'/product/'.$product->id;
    }

    private function publicProductImageUrl(Product $product): ?string
    {
        $image = trim((string) $product->normalImages->first()?->imageUrl);
        if ($image === '') return null;
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }

        return rtrim((string) (config('meta_commerce.public_url') ?: config('app.url')), '/').'/'.ltrim($image, '/');
    }

    private function outboundFailureMessage(string $channel, string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'error validating access token') || str_contains($lower, 'oauth')) {
            return match ($channel) {
                'facebook', 'instagram' => 'تعذر إرسال الرسالة لأن توكن Meta منتهي أو غير صالح. حدّث META_PAGE_ACCESS_TOKEN في ملف .env ثم امسح كاش الإعدادات.',
                default => 'تعذر إرسال الرسالة لأن توكن واتساب منتهي أو غير صالح. حدّث التوكن في ملف .env ثم امسح كاش الإعدادات.',
            };
        }

        if (str_contains($lower, 'unknown error')) {
            return 'Meta رفضت إرسال الرسالة بخطأ عام. تحقق من صلاحيات التوكن ونشر التطبيق، ثم راجع Laravel log لتفاصيل fbtrace.';
        }

        return $error !== '' ? $error : 'تعذر إرسال الرسالة. تحقق من إعدادات الربط ثم حاول مرة أخرى.';
    }

    private function ensureCustomerServiceWindow($conversation): void
    {
        $lastInboundAt = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->value('created_at');
        if (! $lastInboundAt || \Carbon\Carbon::parse($lastInboundAt)->addHours(24)->isPast()) {
            throw ValidationException::withMessages([
                'conversation' => 'انتهت نافذة خدمة العملاء (24 ساعة). يجب أن يرسل الزبون رسالة جديدة.',
            ]);
        }
    }

    private function ensureResendableText(string $direction, string $status, string $type, ?string $body): void
    {
        if ($direction !== 'outbound' || $status !== 'failed') {
            throw ValidationException::withMessages(['message' => 'إعادة الإرسال متاحة فقط للرسائل الصادرة الفاشلة.']);
        }
        if ($type !== 'text' || blank($body)) {
            throw ValidationException::withMessages(['message' => 'إعادة الإرسال حالياً متاحة للرسائل النصية فقط.']);
        }
    }

    private function conversationForChannel(string $channel, int $id)
    {
        if ($channel === 'whatsapp') {
            return $this->activeWhatsAppConversations()->with(['contact', 'assignedAdmin'])->findOrFail($id);
        }

        return SocialConversation::query()->with(['contact', 'assignedAdmin'])->where('channel', $channel)->findOrFail($id);
    }

    private function serializeConversationForChannel(string $channel, $conversation): array
    {
        return $channel === 'whatsapp'
            ? $this->serializeWhatsAppConversation($conversation)
            : $this->serializeSocialConversation($conversation);
    }

    private function needsReply($conversation, string $channel): bool
    {
        $lastInbound = $conversation->messages()->where('direction', 'inbound')->max('created_at');
        if (! $lastInbound) return false;
        $lastOutboundQuery = $conversation->messages()
            ->where('direction', 'outbound')
            ->whereIn('status', ['sent', 'delivered', 'read']);
        if ($channel === 'whatsapp') {
            $lastOutboundQuery->where('is_automatic', false);
        }
        $lastOutbound = $lastOutboundQuery->max('created_at');
        return ! $lastOutbound || \Carbon\Carbon::parse($lastInbound)->gt(\Carbon\Carbon::parse($lastOutbound));
    }

    private function conversationTags(string $channel, int $conversationId): array
    {
        if (! DB::getSchemaBuilder()->hasTable('conversation_taggables')) {
            return [];
        }

        return DB::table('conversation_taggables')
            ->join('conversation_tags', 'conversation_tags.id', '=', 'conversation_taggables.tag_id')
            ->where('conversation_taggables.channel', $channel)
            ->where('conversation_taggables.conversation_id', $conversationId)
            ->orderBy('conversation_tags.name')
            ->get(['conversation_tags.id', 'conversation_tags.name', 'conversation_tags.color'])
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])
            ->all();
    }

    private function availableTags(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('conversation_tags')) {
            return [];
        }

        return DB::table('conversation_tags')
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])
            ->all();
    }

    private function tagColor(string $name): string
    {
        $palette = ['#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#64748b'];
        return $palette[abs(crc32($name)) % count($palette)];
    }

    private function findOrCreateTagId(string $name): int
    {
        $existing = DB::table('conversation_tags')->where('name', $name)->value('id');
        if ($existing) return (int) $existing;

        return (int) DB::table('conversation_tags')->insertGetId([
            'name' => $name,
            'color' => $this->tagColor($name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignedEmployee($user): ?array
    {
        if (! $user) return null;
        $employee = EmployeeDetail::query()->where('user_id', $user->id)->first(['id', 'user_id', 'job_title']);
        return [
            'id' => $employee?->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'job_title' => $employee?->job_title,
        ];
    }

    private function whatsAppForConversation(
        WhatsAppCloudApiService $service,
        WhatsAppConversation $conversation
    ): WhatsAppCloudApiService {
        $conversation->loadMissing('whatsappAccount');
        return $conversation->whatsappAccount
            ? $service->forAccount($conversation->whatsappAccount)
            : $service;
    }

    private function activeWhatsAppConversations(): \Illuminate\Database\Eloquent\Builder
    {
        return WhatsAppConversation::query()
            ->whereHas('whatsappAccount', fn ($account) => $account->where('is_active', true));
    }

    private function activeWhatsAppMessages(): \Illuminate\Database\Eloquent\Builder
    {
        return WhatsAppMessage::query()
            ->whereHas('whatsappAccount', fn ($account) => $account->where('is_active', true));
    }

    private function messageAssignees(): array
    {
        $permissionId = Permission::query()->where('name_en', 'Messages Section')->value('id');

        return EmployeeDetail::query()
            ->with('user:id,name,phone')
            ->whereHas('user', fn ($query) => $query->where('type', 'employee'))
            ->when($permissionId, fn ($query) => $query->whereHas('permissions', fn ($permission) => $permission->where('permission_id', $permissionId)))
            ->orderBy('id')
            ->get(['id', 'user_id', 'job_title'])
            ->map(fn (EmployeeDetail $employee) => [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'name' => $employee->user?->name ?: 'موظف #'.$employee->id,
                'phone' => $employee->user?->phone,
                'job_title' => $employee->job_title,
            ])
            ->all();
    }

    private function allowedChannels(Request $request): array
    {
        if ($request->user()?->type === 'admin') {
            return ['whatsapp', 'facebook', 'instagram'];
        }

        $permissionNames = $request->user()?->employee?->permissions()
            ->whereHas('permission', fn ($query) => $query->whereIn('name_en', [
                'Social Center WhatsApp',
                'Social Center Facebook',
                'Social Center Instagram',
            ]))
            ->with('permission:id,name_en')
            ->get()
            ->pluck('permission.name_en')
            ->all() ?? [];

        return collect([
            'Social Center WhatsApp' => 'whatsapp',
            'Social Center Facebook' => 'facebook',
            'Social Center Instagram' => 'instagram',
        ])->only($permissionNames)->values()->all();
    }

    private function authorizeChannel(Request $request, string $channel): void
    {
        abort_unless(in_array($channel, $this->allowedChannels($request), true), 403);
    }

    private function metaAppStatus(): array
    {
        $published = (bool) config('meta_messaging.app_published', false);
        $mode = (string) config('meta_messaging.app_mode', $published ? 'live' : 'development');

        return [
            'published' => $published,
            'mode' => $mode,
            'message' => $published
                ? 'تطبيق Meta منشور ويستقبل رسائل الحسابات الحقيقية حسب الصلاحيات.'
                : 'تطبيق Meta غير منشور. الرسائل الحقيقية قد تصل فقط من admins/developers/testers إلى أن يتم نشر التطبيق.',
        ];
    }
}
