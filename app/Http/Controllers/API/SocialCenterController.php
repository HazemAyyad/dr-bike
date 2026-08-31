<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\Permission;
use App\Models\Product;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Meta\MetaMessagingService;
use App\Services\WhatsApp\WhatsAppCloudApiService;
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
        $request->validate(['quick_filter' => 'nullable|in:all,unread,failed,linked,needs_reply']);
        $search = trim((string) $request->input('search'));

        $items = collect();
        if (in_array('whatsapp', $allowedChannels, true) && in_array($channel, ['all', 'whatsapp'], true)) {
            $query = $this->activeWhatsAppConversations()->with(['contact', 'whatsappAccount']);
            if (filled($status)) $query->where('status', $status);
            $this->applyQuickFilter($query, $quickFilter);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('phone', 'like', "%{$search}%")
                        ->orWhere('last_message', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$search}%"));
                });
            }
            $items = $items->merge($query->latest('last_message_at')->limit(80)->get()->map(fn ($item) => $this->serializeWhatsAppConversation($item)));
        }

        if (array_intersect(['facebook', 'instagram'], $allowedChannels) && in_array($channel, ['all', 'facebook', 'instagram'], true)) {
            $query = SocialConversation::query()->with('contact');
            if ($channel !== 'all') $query->where('channel', $channel);
            else $query->whereIn('channel', $allowedChannels);
            if (filled($status)) $query->where('status', $status);
            $this->applyQuickFilter($query, $quickFilter);
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
                ->with(['replyTo:id,message_type,body,direction', 'sender:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request, 30))
                ->through(fn ($message) => $this->serializeWhatsAppMessage($message));
        } else {
            $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
            $messages = $conversation->messages()
                ->with(['sender:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request, 30))
                ->through(fn ($message) => $this->serializeSocialMessage($message));
        }

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

            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
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
        $data = $request->validate(['message' => 'required|string|max:4096']);

        try {
            if ($channel === 'whatsapp') {
                $conversation = $this->activeWhatsAppConversations()->with('whatsappAccount')->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $whatsApp = $this->whatsAppForConversation($whatsApp, $conversation);
                $result = $whatsApp->sendText($conversation->phone, $data['message'], $request->user()->id);
            } else {
                abort_unless(in_array($channel, ['facebook', 'instagram'], true), 404);
                $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
                $result = $meta->sendText($conversation, $data['message'], $request->user()->id);
            }

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
                    $data['media_kind'] ?? null
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
        ]);

        if ($channel === 'whatsapp') {
            return app(WhatsAppController::class)->sendProducts($request, $id, $whatsApp);
        }

        try {
            $conversation = SocialConversation::query()->with('contact')->where('channel', $channel)->findOrFail($id);
            $this->ensureCustomerServiceWindow($conversation);
            $result = $this->sendSocialProducts($meta, $conversation, $data['product_ids'], $request->user()->id);
            return $this->sendResult($channel, $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function serializeWhatsAppConversation(WhatsAppConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'channel' => 'whatsapp',
            'phone' => $conversation->phone,
            'status' => $conversation->status,
            'last_message' => $conversation->last_message,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $conversation->unread_count,
            'failed_count' => $conversation->messages()->where('status', 'failed')->count(),
            'last_message_type' => $conversation->messages()->latest('created_at')->value('message_type'),
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
        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'phone' => $conversation->contact?->external_id ?: '',
            'status' => $conversation->status,
            'last_message' => $conversation->last_message,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $conversation->unread_count,
            'failed_count' => $conversation->messages()->where('status', 'failed')->count(),
            'last_message_type' => $conversation->messages()->latest('created_at')->value('message_type'),
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
        return array_merge($message->toArray(), ['channel' => 'whatsapp']);
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
            'sender' => $message->sender,
            'raw_payload' => $message->raw_payload,
            'response_payload' => $message->response_payload,
            'created_at' => $message->created_at,
        ];
    }

    private function ok($value, string $key) { return response()->json(['status' => 'success', $key => $value]); }
    private function perPage(Request $request, int $default = 20): int { return min(max((int) $request->input('per_page', $default), 1), 100); }

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

    private function applyQuickFilter($query, string $quickFilter): void
    {
        match ($quickFilter) {
            'unread' => $query->where('unread_count', '>', 0),
            'failed' => $query->whereHas('messages', fn ($messages) => $messages->where('status', 'failed')),
            'linked' => $query->whereHas('contact', fn ($contact) => $contact
                ->whereNotNull('customer_id')
                ->orWhereNotNull('supplier_id')),
            'needs_reply' => $this->applyNeedsReplyFilter($query),
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
        $failed = data_get($result, 'message.status') === 'failed';
        if ($failed) {
            return response()->json([
                'status' => 'error',
                'message' => $this->outboundFailureMessage($channel, (string) data_get($result, 'message.error_message')),
                'failed_message_id' => data_get($result, 'message.id'),
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
        $lastOutbound = $conversation->messages()->where('direction', 'outbound')->max('created_at');
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
