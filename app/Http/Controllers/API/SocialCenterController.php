<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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
            'total_contacts' => DB::table('whatsapp_contacts')->count() + DB::table('social_contacts')->count(),
            'total_conversations' => WhatsAppConversation::query()->count() + SocialConversation::query()->count(),
            'open_conversations' => WhatsAppConversation::query()->where('status', 'open')->count()
                + SocialConversation::query()->where('status', 'open')->count(),
            'unread_conversations' => WhatsAppConversation::query()->where('unread_count', '>', 0)->count()
                + SocialConversation::query()->where('unread_count', '>', 0)->count(),
            'messages_today' => WhatsAppMessage::query()->whereDate('created_at', today())->count()
                + SocialMessage::query()->whereDate('created_at', today())->count(),
            'failed_messages_today' => WhatsAppMessage::query()->where('status', 'failed')->whereDate('created_at', today())->count()
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
        $quickFilter = $request->input('quick_filter', 'all');
        $request->validate(['quick_filter' => 'nullable|in:all,unread,failed,linked']);
        $search = trim((string) $request->input('search'));

        $items = collect();
        if (in_array($channel, ['all', 'whatsapp'], true)) {
            $query = WhatsAppConversation::query()->with('contact');
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

        if (in_array($channel, ['all', 'facebook', 'instagram'], true)) {
            $query = SocialConversation::query()->with('contact');
            if ($channel !== 'all') $query->where('channel', $channel);
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

        if ($channel === 'whatsapp') {
            $conversation = WhatsAppConversation::query()->with('contact')->findOrFail($id);
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
        ]);
    }

    public function sendToConversation(
        Request $request,
        string $channel,
        int $id,
        WhatsAppCloudApiService $whatsApp,
        MetaMessagingService $meta
    ) {
        $data = $request->validate(['message' => 'required|string|max:4096']);

        try {
            if ($channel === 'whatsapp') {
                $conversation = WhatsAppConversation::query()->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
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
        $data = $request->validate([
            'file' => 'required|file|max:16384|mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,mp3,m4a,ogg,wav,mp4,mov',
            'caption' => 'nullable|string|max:1024',
            'media_kind' => 'nullable|in:image,audio,video,document',
        ]);

        try {
            if ($channel === 'whatsapp') {
                $conversation = WhatsAppConversation::query()->findOrFail($id);
                $this->ensureCustomerServiceWindow($conversation);
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
            $result = $meta->sendText($conversation, $this->socialProductsMessage($data['product_ids']), $request->user()->id);
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
            'contact' => [
                'id' => $conversation->contact?->id,
                'name' => $conversation->contact?->name,
                'phone' => $conversation->contact?->external_id,
                'external_id' => $conversation->contact?->external_id,
                'profile_picture_url' => $conversation->contact?->profile_picture_url,
                'customer_id' => $conversation->contact?->customer_id,
                'supplier_id' => $conversation->contact?->supplier_id,
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
            'status' => $message->status,
            'error_message' => $message->error_message,
            'sender' => $message->sender,
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
                'contacts' => DB::table('whatsapp_contacts')->count(),
                'conversations' => WhatsAppConversation::query()->count(),
                'open' => WhatsAppConversation::query()->where('status', 'open')->count(),
                'unread' => WhatsAppConversation::query()->where('unread_count', '>', 0)->count(),
                'messages_today' => WhatsAppMessage::query()->whereDate('created_at', today())->count(),
                'failed_today' => WhatsAppMessage::query()->where('status', 'failed')->whereDate('created_at', today())->count(),
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
            default => null,
        };
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
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get();
        abort_if($products->count() !== count(array_unique($productIds)), 422, 'Some products were not found.');

        $order = array_flip(array_map('strval', $productIds));
        $base = rtrim((string) (config('meta_commerce.public_url') ?: config('app.url')), '/');
        $lines = ['منتجات دكتور بايك:'];

        foreach ($products->sortBy(fn (Product $product) => $order[(string) $product->id] ?? PHP_INT_MAX) as $product) {
            $name = $product->nameAr ?: $product->nameEng ?: 'منتج';
            $line = '- '.$name;
            if ($product->normailPrice !== null) {
                $line .= ' | '.$product->normailPrice.' ₪';
            }
            if ($product->product_code) {
                $line .= ' | كود: '.$product->product_code;
            }
            $line .= "\n".$base.'/product/'.$product->id;
            $lines[] = $line;
        }

        return implode("\n\n", $lines);
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
}
