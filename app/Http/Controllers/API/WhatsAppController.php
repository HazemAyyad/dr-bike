<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\Customer;
use App\Models\Seller;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
        $messages = $conversation->messages()->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request, 30));
        $conversation->update(['unread_count' => 0]);
        return response()->json(['status' => 'success', 'conversation' => $conversation->fresh('contact'), 'messages' => $messages]);
    }

    public function sendToConversation(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $data = $request->validate(['message' => 'required|string|max:4096']);
        return $this->sendSafely(fn () => $service->sendText($conversation->phone, $data['message'], $request->user()->id));
    }

    public function sendMediaToConversation(Request $request, int $id, WhatsAppCloudApiService $service)
    {
        $conversation = WhatsAppConversation::query()->findOrFail($id);
        $data = $request->validate([
            'file' => 'required|file|max:16384|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,mp3,m4a,ogg,wav,mp4',
            'caption' => 'nullable|string|max:1024',
        ]);
        return $this->sendSafely(fn () => $service->sendMedia(
            $conversation->phone, $data['file'], $data['caption'] ?? null, $request->user()->id
        ));
    }

    public function media(int $id, WhatsAppCloudApiService $service)
    {
        $message = WhatsAppMessage::query()->findOrFail($id);
        abort_unless($message->media_url, 404);
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
        $phone = $service->businessPhoneNumber();
        $svg = QrCode::format('svg')->size(700)->margin(2)->generate('https://wa.me/'.$phone);
        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function qrA4(WhatsAppCloudApiService $service)
    {
        $phone = $service->businessPhoneNumber();
        $svg = QrCode::format('svg')->size(900)->margin(2)->generate('https://wa.me/'.$phone);
        return Pdf::loadView('whatsapp.qr-a4', [
            'qr' => base64_encode($svg),
            'phone' => $phone,
        ])->setPaper('a4')->download('dr-bike-whatsapp-qr.pdf');
    }

    public function sendText(Request $request, WhatsAppCloudApiService $service)
    {
        $data = $request->validate(['phone' => 'required|string|max:32', 'message' => 'required|string|max:4096']);
        return $this->sendSafely(fn () => $service->sendText($data['phone'], $data['message'], $request->user()->id));
    }

    public function sendTemplate(Request $request, WhatsAppCloudApiService $service)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32', 'template_name' => 'required|string|max:255',
            'language' => 'nullable|string|max:16', 'components' => 'nullable|array',
        ]);
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
            return response()->json(['status' => $failed ? 'error' : 'success'] + $result, $failed ? 422 : 200);
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
}
