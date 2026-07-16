<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSuggestion;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupportConversationController extends Controller
{
    public const PERMISSION = 'Technical Support';

    public function __construct(
        protected AdminNotificationService $adminNotifications,
        protected EmployeeNotificationService $employeeNotifications
    ) {}

    public function index(Request $request)
    {
        $query = SupportConversation::query()
            ->with(['employee.user:id,name', 'assignee:id,name', 'suggestion:id,title,category,is_anonymous'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (! $this->canManageSupport($request)) {
            $query->where('employee_id', $this->employeeId($request));
        }

        if ($request->filled('status') && in_array($request->input('status'), SupportConversation::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority') && in_array($request->input('priority'), SupportConversation::PRIORITIES, true)) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->boolean('unread_only')) {
            $this->canManageSupport($request)
                ? $query->where('support_unread_count', '>', 0)
                : $query->where('employee_unread_count', '>', 0);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('last_message', 'like', "%{$search}%")
                    ->orWhereHas('employee.user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'status' => 'success',
            'can_manage_support' => $this->canManageSupport($request),
            'conversations' => $query->paginate($perPage)->through(fn ($conversation) => $this->conversationPayload($conversation)),
        ]);
    }

    public function store(Request $request)
    {
        $employeeId = $this->canManageSupport($request)
            ? (int) $request->input('employee_id', $request->user()->employee?->id ?? 0)
            : $this->employeeId($request);

        abort_unless($employeeId > 0, 422, 'employee_id is required');

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employee_details,id'],
            'employee_suggestion_id' => ['nullable', 'integer', 'exists:employee_suggestions,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', Rule::in(SupportConversation::PRIORITIES)],
            'message' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:32768', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,mp3,m4a,ogg,wav,mp4,mov,webm'],
        ]);

        if (! empty($validated['employee_suggestion_id'])) {
            $suggestion = EmployeeSuggestion::query()->findOrFail($validated['employee_suggestion_id']);
            abort_unless((int) $suggestion->employee_id === $employeeId, 403);
        }

        abort_if(
            ! $request->filled('message') && ! $request->hasFile('attachments'),
            422,
            'message or attachments are required'
        );

        $conversation = DB::transaction(function () use ($request, $validated, $employeeId) {
            $conversation = SupportConversation::create([
                'employee_id' => $employeeId,
                'created_by_user_id' => $request->user()->id,
                'employee_suggestion_id' => $validated['employee_suggestion_id'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'priority' => $validated['priority'] ?? SupportConversation::PRIORITY_NORMAL,
                'status' => SupportConversation::STATUS_OPEN,
            ]);

            $message = $this->createMessage($request, $conversation, $validated['message'] ?? null);
            $this->touchConversationAfterMessage($conversation, $message, $request);

            return $conversation->fresh(['employee.user:id,name', 'assignee:id,name', 'suggestion:id,title,category,is_anonymous']);
        });

        $conversation->load(['messages.attachments', 'messages.senderUser:id,name']);
        $message = $conversation->messages->last();
        if ($message) {
            $this->notifyAfterMessage($conversation, $message);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم فتح محادثة الدعم الفني',
            'conversation' => $this->conversationPayload($conversation),
        ], 201);
    }

    public function show(Request $request, SupportConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        $messages = $conversation->messages()
            ->with(['attachments', 'senderUser:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 100))
            ->through(fn ($message) => $this->messagePayload($message));

        return response()->json([
            'status' => 'success',
            'conversation' => $this->conversationPayload(
                $conversation->fresh(['employee.user:id,name', 'assignee:id,name', 'suggestion:id,title,category,is_anonymous'])
            ),
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request, SupportConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);
        abort_if($conversation->status === SupportConversation::STATUS_CLOSED, 422, 'conversation is closed');

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:32768', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,mp3,m4a,ogg,wav,mp4,mov,webm'],
        ]);

        abort_if(
            ! $request->filled('message') && ! $request->hasFile('attachments'),
            422,
            'message or attachments are required'
        );

        $message = DB::transaction(function () use ($request, $conversation, $validated) {
            $message = $this->createMessage($request, $conversation, $validated['message'] ?? null);
            $this->touchConversationAfterMessage($conversation, $message, $request);

            return $message->fresh(['attachments', 'senderUser:id,name']);
        });

        $this->notifyAfterMessage($conversation->fresh(['employee.user']), $message);

        return response()->json([
            'status' => 'success',
            'message' => 'تم إرسال الرسالة',
            'support_message' => $this->messagePayload($message),
            'conversation' => $this->conversationPayload(
                $conversation->fresh(['employee.user:id,name', 'assignee:id,name', 'suggestion:id,title,category,is_anonymous'])
            ),
        ], 201);
    }

    public function markRead(Request $request, SupportConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        $this->canManageSupport($request)
            ? $conversation->update(['support_unread_count' => 0])
            : $conversation->update(['employee_unread_count' => 0]);

        return response()->json([
            'status' => 'success',
            'conversation' => $this->conversationPayload($conversation->fresh(['employee.user:id,name', 'assignee:id,name'])),
        ]);
    }

    public function updateStatus(Request $request, SupportConversation $conversation)
    {
        abort_unless($this->canManageSupport($request), 403);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(SupportConversation::STATUSES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $payload = [
            'status' => $validated['status'],
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? $conversation->assigned_to_user_id,
        ];

        if ($validated['status'] === SupportConversation::STATUS_CLOSED) {
            $payload['closed_by_user_id'] = $request->user()->id;
            $payload['closed_at'] = now();
        } else {
            $payload['closed_by_user_id'] = null;
            $payload['closed_at'] = null;
        }

        $conversation->update($payload);

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث حالة محادثة الدعم الفني',
            'conversation' => $this->conversationPayload($conversation->fresh(['employee.user:id,name', 'assignee:id,name'])),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $query = SupportConversation::query();

        if ($this->canManageSupport($request)) {
            $count = (clone $query)->where('support_unread_count', '>', 0)->count();
            $total = (clone $query)->sum('support_unread_count');
        } else {
            $query->where('employee_id', $this->employeeId($request));
            $count = (clone $query)->where('employee_unread_count', '>', 0)->count();
            $total = (clone $query)->sum('employee_unread_count');
        }

        return response()->json([
            'status' => 'success',
            'unread_conversations' => (int) $count,
            'unread_messages' => (int) $total,
        ]);
    }

    private function createMessage(Request $request, SupportConversation $conversation, ?string $body): SupportMessage
    {
        $isSupport = $this->canManageSupport($request);
        $attachments = $request->file('attachments', []);

        $message = SupportMessage::create([
            'support_conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'sender_employee_id' => $request->user()->employee?->id,
            'sender_type' => $isSupport ? SupportMessage::SENDER_SUPPORT : SupportMessage::SENDER_EMPLOYEE,
            'message_type' => $this->messageType($attachments),
            'body' => $body,
        ]);

        foreach ($attachments as $file) {
            if ($file instanceof UploadedFile) {
                $this->storeAttachment($conversation, $message, $file);
            }
        }

        return $message->fresh(['attachments', 'senderUser:id,name']);
    }

    private function storeAttachment(SupportConversation $conversation, SupportMessage $message, UploadedFile $file): void
    {
        $path = $file->store("support/attachments/{$conversation->id}", 'public');
        $type = $this->attachmentType((string) $file->getMimeType());

        $message->attachments()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'attachment_type' => $type,
        ]);
    }

    private function touchConversationAfterMessage(SupportConversation $conversation, SupportMessage $message, Request $request): void
    {
        $isSupport = $this->canManageSupport($request);
        $snippet = $message->body ?: $this->attachmentLabel($message->message_type);

        $updates = [
            'last_message' => mb_substr((string) $snippet, 0, 500),
            'last_message_at' => now(),
        ];

        if ($isSupport) {
            $updates['employee_unread_count'] = DB::raw('employee_unread_count + 1');
            $updates['support_unread_count'] = 0;
        } else {
            $updates['support_unread_count'] = DB::raw('support_unread_count + 1');
            $updates['employee_unread_count'] = 0;
        }

        $conversation->update($updates);
    }

    private function notifyAfterMessage(SupportConversation $conversation, SupportMessage $message): void
    {
        if ($message->sender_type === SupportMessage::SENDER_SUPPORT) {
            $this->notifyEmployeeOwner($conversation, $message);
            return;
        }

        $this->notifySupportTeam($conversation, $message);
    }

    private function notifySupportTeam(SupportConversation $conversation, SupportMessage $message): void
    {
        $conversation->loadMissing('employee.user');
        $employeeName = (string) ($conversation->employee?->user?->name ?? 'موظف');
        $body = $this->notificationBody($employeeName, $message);

        $this->adminNotifications->create(
            AdminNotificationService::TYPE_SUPPORT_MESSAGE,
            'رسالة دعم فني جديدة',
            $body,
            $this->notificationData($conversation, $message),
            $conversation->employee_id,
            'support_conversation',
            $conversation->id,
            true
        );

        $supportEmployees = EmployeeDetail::query()
            ->with('user:id,name,fcm_token')
            ->where('id', '!=', (int) $message->sender_employee_id)
            ->whereHas('permissions.permission', fn ($q) => $q->where('name_en', self::PERMISSION))
            ->get();

        foreach ($supportEmployees as $employee) {
            $this->employeeNotifications->create(
                $employee,
                EmployeeNotificationService::TYPE_SUPPORT_MESSAGE,
                'رسالة دعم فني جديدة',
                $body,
                $this->notificationData($conversation, $message),
                'support_conversation',
                $conversation->id,
                true
            );
        }
    }

    private function notifyEmployeeOwner(SupportConversation $conversation, SupportMessage $message): void
    {
        $conversation->loadMissing('employee.user');
        $employee = $conversation->employee;

        if (! $employee || (int) $employee->id === (int) $message->sender_employee_id) {
            return;
        }

        $senderName = (string) ($message->senderUser?->name ?? 'الدعم الفني');
        $this->employeeNotifications->create(
            $employee,
            EmployeeNotificationService::TYPE_SUPPORT_MESSAGE,
            'رد جديد من الدعم الفني',
            $this->notificationBody($senderName, $message),
            $this->notificationData($conversation, $message),
            'support_conversation',
            $conversation->id,
            true
        );
    }

    private function authorizeConversation(Request $request, SupportConversation $conversation): void
    {
        if ($this->canManageSupport($request)) {
            return;
        }

        abort_unless((int) $conversation->employee_id === $this->employeeId($request), 403);
    }

    private function canManageSupport(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->type === 'admin') {
            return true;
        }

        return (bool) $user->employee?->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', self::PERMISSION))
            ->exists();
    }

    private function employeeId(Request $request): int
    {
        return (int) ($request->user()->employee?->id ?? 0);
    }

    private function messageType(array $attachments): string
    {
        $first = $attachments[0] ?? null;
        if ($first instanceof UploadedFile) {
            return $this->attachmentType((string) $first->getMimeType());
        }

        return SupportMessage::TYPE_TEXT;
    }

    private function attachmentType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => SupportMessage::TYPE_IMAGE,
            str_starts_with($mime, 'video/') => SupportMessage::TYPE_VIDEO,
            str_starts_with($mime, 'audio/') => SupportMessage::TYPE_AUDIO,
            default => SupportMessage::TYPE_DOCUMENT,
        };
    }

    private function attachmentLabel(string $type): string
    {
        return match ($type) {
            SupportMessage::TYPE_IMAGE => 'أرسل صورة',
            SupportMessage::TYPE_VIDEO => 'أرسل فيديو',
            SupportMessage::TYPE_AUDIO => 'أرسل تسجيل صوتي',
            SupportMessage::TYPE_DOCUMENT => 'أرسل ملف',
            default => 'رسالة دعم فني',
        };
    }

    private function notificationBody(string $senderName, SupportMessage $message): string
    {
        $content = $message->body ?: $this->attachmentLabel($message->message_type);

        return $senderName.': '.mb_substr($content, 0, 120);
    }

    private function notificationData(SupportConversation $conversation, SupportMessage $message): array
    {
        return [
            'conversation_id' => (string) $conversation->id,
            'message_id' => (string) $message->id,
            'support_conversation_id' => (string) $conversation->id,
            'employee_id' => (string) $conversation->employee_id,
            'source' => 'technical_support',
        ];
    }

    private function conversationPayload(SupportConversation $conversation): array
    {
        $employeeName = (string) ($conversation->employee?->user?->name ?? '');
        $suggestion = $conversation->suggestion;

        return [
            'id' => $conversation->id,
            'employee_id' => $conversation->employee_id,
            'employee_name' => $employeeName,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'last_message' => $conversation->last_message,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'employee_unread_count' => (int) $conversation->employee_unread_count,
            'support_unread_count' => (int) $conversation->support_unread_count,
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'assigned_to_user_id' => $conversation->assigned_to_user_id,
            'assigned_to_name' => (string) ($conversation->assignee?->name ?? ''),
            'employee_suggestion_id' => $conversation->employee_suggestion_id,
            'suggestion_title' => $suggestion?->title,
            'suggestion_category' => $suggestion?->category,
            'created_at' => optional($conversation->created_at)->toIso8601String(),
            'closed_at' => optional($conversation->closed_at)->toIso8601String(),
        ];
    }

    private function messagePayload(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->support_conversation_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_employee_id' => $message->sender_employee_id,
            'sender_name' => (string) ($message->senderUser?->name ?? ''),
            'sender_type' => $message->sender_type,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->attachment_type,
                'url' => $attachment->url,
                'path' => $attachment->path,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
            ])->values(),
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
