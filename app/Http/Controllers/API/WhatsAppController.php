<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\Customer;
use App\Models\Seller;
use App\Models\Product;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use ArPHP\I18N\Arabic;

class WhatsAppController extends Controller
{
    public function dashboard()
    {
        return $this->ok([
            'total_contacts' => WhatsAppContact::query()->count(),
            'total_conversations' => WhatsAppConversation::query()->count(),
            'open_conversations' => WhatsAppConversation::query()->where('status', 'open')->count(),
            'unread_conversations' => WhatsAppConversation::query()->where('unread_count', '>', 0)->count(),
            'messages_today' => WhatsAppMessage::query()->whereDate('created_at', today())->count(),
            'failed_messages_today' => WhatsAppMessage::query()->where('status', 'failed')->whereDate('created_at', today())->count(),
        ], 'dashboard');
    }

    public function conversations(Request $request)
    {
        $query = WhatsAppConversation::query()->with('contact')->orderByDesc('last_message_at')->orderByDesc('id');
        if ($request->filled('status')) {
            $request->validate(['status' => 'in:open,pending,closed']);
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('last_message', 'like', "%{$search}%")
                    ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$search}%"));
            });
        }
        return $this->ok($query->paginate($this->perPage($request)), 'conversations');
    }

    public function showConversation(Request $request, int $id)
    {
        $conversation = WhatsAppConversation::query()->with('contact')->findOrFail($id);
        $hiddenIds = DB::table('whatsapp_message_user_hides')
            ->where('user_id', $request->user()->id)
            ->pluck('whatsapp_message_id');
        $messages = $conversation->messages()
            ->whereNotIn('id', $hiddenIds)
            ->with([
            'replyTo:id,message_type,body,direction',
            'sender:id,name',
        ])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request, 30));
        $lastInboundAt = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->value('created_at');
        $windowExpiresAt = $lastInboundAt
            ? \Carbon\Carbon::parse($lastInboundAt)->addHours(24)
            : null;
        $conversation->update(['unread_count' => 0]);
        return response()->json([
            'status' => 'success',
            'conversation' => $conversation->fresh('contact'),
            'messages' => $messages,
            'customer_service_window' => [
                'open' => $windowExpiresAt?->isFuture() === true,
                'last_inbound_at' => $lastInboundAt,
                'expires_at' => $windowExpiresAt?->toIso8601String(),
            ],
        ]);
    }

    public function sendToConversation(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $this->ensureCustomerServiceWindow($conversation);
        $data = $request->validate([
            'message' => 'required|string|max:4096',
            'reply_to_message_id' => 'nullable|integer',
        ]);
        $replyTo = isset($data['reply_to_message_id'])
            ? $conversation->messages()->findOrFail($data['reply_to_message_id'])
            : null;
        $service = $this->serviceForConversation($service, $conversation);
        return $this->sendSafely(fn () => $service->sendText(
            $conversation->phone,
            $data['message'],
            $request->user()->id,
            $replyTo
        ));
    }

    public function requestContinuation(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);

        $service = $this->serviceForConversation($service, $conversation);
        return $this->sendSafely(fn () => $service->sendTemplate(
            $conversation->phone,
            (string) config('whatsapp.reengagement_template_name'),
            (string) config('whatsapp.reengagement_template_language', 'ar'),
            [],
            $request->user()->id
        ));
    }

    public function sendMediaToConversation(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $this->ensureCustomerServiceWindow($conversation);
        $data = $request->validate([
            'file' => 'required|file|max:16384|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,mp3,m4a,ogg,wav,mp4',
            'caption' => 'nullable|string|max:1024',
            'media_kind' => 'nullable|in:image,audio,video,document',
        ]);
        $service = $this->serviceForConversation($service, $conversation);
        return $this->sendSafely(fn () => $service->sendMedia(
            $conversation->phone,
            $data['file'],
            $data['caption'] ?? null,
            $request->user()->id,
            $data['media_kind'] ?? null
        ));
    }

    public function typing(int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $messageId = $conversation->messages()
            ->where('direction', 'inbound')
            ->whereNotNull('meta_message_id')
            ->latest('id')
            ->value('meta_message_id');

        if (! $messageId) {
            return response()->json([
                'status' => 'success',
                'typing_sent' => false,
                'message' => 'No inbound WhatsApp message is available for the typing indicator.',
            ]);
        }

        $service = $this->serviceForConversation($service, $conversation);
        $result = $service->sendTypingIndicator($messageId);

        return response()->json([
            'status' => $result['successful'] ? 'success' : 'error',
            'typing_sent' => $result['successful'],
            'api_response' => $result,
        ], $result['successful'] ? 200 : 422);
    }

    public function hideMessage(Request $request, int $id, int $messageId)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $message = $conversation->messages()->findOrFail($messageId);

        DB::table('whatsapp_message_user_hides')->insertOrIgnore([
            'whatsapp_message_id' => $message->id,
            'user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Message hidden for this user only.',
        ]);
    }

    public function products(Request $request)
    {
        $search = trim((string) $request->input('search'));
        $query = Product::query()
            ->with([
                'normalImages' => fn ($q) => $q->select('id', 'itemId', 'imageUrl'),
                'category:id,nameAr',
            ])
            ->where('isShow', 1);

        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('nameAr', 'like', "%{$search}%")
                ->orWhere('nameEng', 'like', "%{$search}%")
                ->orWhere('product_code', 'like', "%{$search}%"));
        }

        return $this->ok($query->orderBy('nameAr')->paginate($this->perPage($request, 40))
            ->through(fn (Product $product) => [
                'id' => (string) $product->id,
                'name' => $product->nameAr ?: $product->nameEng,
                'price' => $product->normailPrice,
                'image' => $product->normalImages->first()?->imageUrl,
                'code' => $product->product_code,
                'stock' => $product->stock,
                'model' => $product->model,
                'category' => $product->category?->nameAr,
                'retailer_id' => $product->meta_catalog_retailer_id,
                'catalog_synced' => $product->meta_catalog_sync_status === 'synced'
                    && filled($product->meta_catalog_retailer_id),
            ]), 'products');
    }

    public function sendProducts(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $this->ensureCustomerServiceWindow($conversation);
        $data = $request->validate([
            'product_ids' => 'required|array|min:1|max:30',
            'product_ids.*' => 'required|string',
        ]);
        $products = Product::query()
            ->with(['normalImages', 'category'])
            ->whereIn('id', $data['product_ids'])
            ->get();
        abort_if($products->count() !== count(array_unique($data['product_ids'])), 422, 'Some products were not found.');
        $order = array_flip(array_map('strval', $data['product_ids']));
        $products = $products->sortBy(fn (Product $product) => $order[(string) $product->id] ?? PHP_INT_MAX);
        $rows = $products->map(fn (Product $product) => [
            'name' => $product->nameAr ?: $product->nameEng ?: 'منتج',
            'price' => number_format((float) $product->normailPrice, 2),
            'code' => $product->product_code,
            'stock' => $product->stock ?? 0,
            'model' => $product->model,
            'category' => $product->category?->nameAr,
            'description' => $product->descriptionAr,
            'image' => $this->productImageDataUri($product->normalImages->first()?->imageUrl),
        ])->values()->all();

        $html = view('whatsapp.products-pdf', [
            'products' => $rows,
            'generatedAt' => now()->format('Y-m-d H:i'),
            'logo' => 'data:image/jpeg;base64,'.base64_encode(
                file_get_contents(public_path('appImages/logo.jpg'))
            ),
        ])->render();
        $html = $this->shapeArabicHtml($html);
        $path = storage_path('app/whatsapp-products-'.uniqid().'.pdf');
        Pdf::loadHTML($html)->setPaper('a4')->save($path);

        try {
            $file = new UploadedFile(
                $path,
                'doctor-bike-products.pdf',
                'application/pdf',
                null,
                true
            );
            $service = $this->serviceForConversation($service, $conversation);
            return $this->sendSafely(fn () => $service->sendMedia(
                $conversation->phone,
                $file,
                'تفاصيل المنتجات المختارة',
                $request->user()->id,
                'document'
            ));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function media(int $id, WhatsAppCloudApiService $service)
    {
        $message = WhatsAppMessage::query()->with('whatsappAccount')->findOrFail($id);
        abort_unless($message->media_url, 404);
        $service = $message->whatsappAccount
            ? $service->forAccount($message->whatsappAccount)
            : $service;
        $media = $service->downloadMedia($message->media_url);
        $extension = match ($media['mime_type']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg', 'video/mp4' => 'mp4', default => 'bin',
        };
        return response($media['body'], 200, [
            'Content-Type' => $media['mime_type'],
            'Content-Disposition' => 'inline; filename="whatsapp-'.$message->id.'.'.$extension.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function linkPerson(Request $request, int $id)
    {
        $conversation = WhatsAppConversation::query()->with('contact')->findOrFail($id);
        $data = $request->validate([
            'person_type' => 'required|in:customer,seller',
            'name' => 'required|string|max:255',
        ]);
        $phone = $this->erpPhone($conversation->phone);

        $person = DB::transaction(function () use ($data, $phone, $conversation) {
            $model = $data['person_type'] === 'customer' ? Customer::class : Seller::class;
            $person = $model::query()->firstOrCreate(['phone' => $phone], ['name' => $data['name']]);
            $conversation->contact->update([
                'name' => $data['name'],
                'customer_id' => $data['person_type'] === 'customer' ? $person->id : null,
                'supplier_id' => $data['person_type'] === 'seller' ? $person->id : null,
            ]);
            return $person;
        });

        return response()->json([
            'status' => 'success',
            'person_type' => $data['person_type'],
            'person_id' => $person->id,
            'contact' => $conversation->contact->fresh(),
        ]);
    }

    public function qr(WhatsAppCloudApiService $service)
    {
        $account = $this->accountFromRequest(request());
        if ($account) $service = $service->forAccount($account);
        $phone = $service->businessPhoneNumber();
        $svg = QrCode::format('svg')->size(700)->margin(2)->generate('https://wa.me/'.$phone);
        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function qrA4(WhatsAppCloudApiService $service)
    {
        $account = $this->accountFromRequest(request());
        if ($account) $service = $service->forAccount($account);
        $phone = $service->businessPhoneNumber();
        $svg = QrCode::format('svg')->size(900)->margin(2)->generate('https://wa.me/'.$phone);
        $html = view('whatsapp.qr-a4', [
            'qr' => base64_encode($svg),
            'phone' => $phone,
        ])->render();

        $arabic = new Arabic();
        $positions = $arabic->arIdentify($html);
        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $html = substr_replace(
                $html,
                $arabic->utf8Glyphs(substr($html, $positions[$i - 1], $positions[$i] - $positions[$i - 1])),
                $positions[$i - 1],
                $positions[$i] - $positions[$i - 1]
            );
        }

        return Pdf::loadHTML($html)->setPaper('a4')->download('dr-bike-whatsapp-qr.pdf');
    }

    public function sendText(Request $request, WhatsAppCloudApiService $service)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'message' => 'required|string|max:4096',
            'whatsapp_account_id' => 'nullable|integer|exists:whatsapp_accounts,id',
        ]);
        $account = $this->accountFromRequest($request);
        if ($account) $service = $service->forAccount($account);
        return $this->sendSafely(fn () => $service->sendText($data['phone'], $data['message'], $request->user()->id));
    }

    public function sendTemplate(Request $request, WhatsAppCloudApiService $service)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32', 'template_name' => 'required|string|max:255',
            'language' => 'nullable|string|max:16', 'components' => 'nullable|array',
            'whatsapp_account_id' => 'nullable|integer|exists:whatsapp_accounts,id',
        ]);
        $account = $this->accountFromRequest($request);
        if ($account) $service = $service->forAccount($account);
        return $this->sendSafely(fn () => $service->sendTemplate(
            $data['phone'], $data['template_name'], $data['language'] ?? 'ar', $data['components'] ?? [], $request->user()->id
        ));
    }

    public function messages(Request $request)
    {
        $query = WhatsAppMessage::query()->with(['contact:id,name,phone', 'conversation:id,status'])->orderByDesc('id');
        if ($request->filled('direction')) $query->where('direction', $request->input('direction'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('phone')) $query->where('phone', 'like', '%'.$request->input('phone').'%');
        return $this->ok($query->paginate($this->perPage($request)), 'messages');
    }

    public function testMessage(Request $request, WhatsAppCloudApiService $service)
    {
        return $this->sendText($request, $service);
    }

    private function sendSafely(callable $callback)
    {
        try {
            $result = $callback();
            $failed = data_get($result, 'message.status') === 'failed';
            if ($failed) {
                $error = (string) data_get($result, 'message.error_message', '');
                $message = match (true) {
                    str_contains($error, '132001') => 'قالب واتساب غير متاح بعد. تأكد أن اسمه ولغته مطابقان وأن حالته Approved في Meta.',
                    str_contains(strtolower($error), 'business eligibility payment issue') => 'تعذر إرسال القالب بسبب مشكلة دفع/أهلية في حساب واتساب على Meta. راجع Billing أو Payment method داخل Meta Business.',
                    default => $error ?: 'تعذر إرسال رسالة واتساب.',
                };

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'failed_message' => data_get($result, 'message'),
                    'api_response' => data_get($result, 'api_response'),
                ], 422);
            }

            return response()->json(['status' => 'success'] + $result);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function ok($value, string $key) { return response()->json(['status' => 'success', $key => $value]); }
    private function perPage(Request $request, int $default = 20): int { return min(max((int) $request->input('per_page', $default), 1), 100); }
    private function erpPhone(string $phone): string
    {
        return '+'.substr($phone, 0, 3).' '.substr($phone, 3);
    }

    private function productImageDataUri(?string $url): ?string
    {
        if (blank($url)) return null;
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $file = public_path(ltrim($path, '/'));
        if (! is_file($file) || filesize($file) > 8 * 1024 * 1024) {
            return null;
        }
        $mime = mime_content_type($file) ?: 'image/jpeg';
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file));
    }

    private function shapeArabicHtml(string $html): string
    {
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($html);
        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $html = substr_replace(
                $html,
                $arabic->utf8Glyphs(substr($html, $positions[$i - 1], $positions[$i] - $positions[$i - 1])),
                $positions[$i - 1],
                $positions[$i] - $positions[$i - 1]
            );
        }
        return $html;
    }

    private function ensureCustomerServiceWindow(WhatsAppConversation $conversation): void
    {
        $lastInboundAt = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->value('created_at');
        if (! $lastInboundAt || \Carbon\Carbon::parse($lastInboundAt)->addHours(24)->isPast()) {
            throw ValidationException::withMessages([
                'conversation' => 'انتهت نافذة خدمة العملاء (24 ساعة). يجب أن يرسل الزبون رسالة جديدة أو استخدام قالب Meta معتمد.',
            ]);
        }
    }

    private function serviceForConversation(WhatsAppCloudApiService $service, WhatsAppConversation $conversation): WhatsAppCloudApiService
    {
        $conversation->loadMissing('whatsappAccount');
        return $conversation->whatsappAccount
            ? $service->forAccount($conversation->whatsappAccount)
            : $service;
    }

    private function accountFromRequest(Request $request): ?WhatsAppAccount
    {
        $id = $request->input('whatsapp_account_id');
        return $id ? WhatsAppAccount::query()->findOrFail((int) $id) : null;
    }
}
