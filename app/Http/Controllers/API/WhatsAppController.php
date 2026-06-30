<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            return response()->json(['status' => 'success'] + $result, 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function ok($value, string $key) { return response()->json(['status' => 'success', $key => $value]); }
    private function perPage(Request $request, int $default = 20): int { return min(max((int) $request->input('per_page', $default), 1), 100); }
}
