<?php

namespace App\Http\Controllers\API;

use App\Events\Support\SupportConversationRead;
use App\Events\Support\SupportMessageCreated;
use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSuggestion;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageReaction;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use App\Services\Support\StoreSupportNotificationService;
use App\Services\Support\SupportAccessService;
use App\Services\Support\SupportMessageManager;
use App\Services\Support\SupportPayloadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupportConversationController extends Controller
{
    public const PERMISSION = 'Technical Support';

    private const ATTACHMENT_MAX_KB = 102400;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,heic,heif,pdf,doc,docx,xls,xlsx,txt,mp3,m4a,aac,ogg,wav,mp4,mov,webm,3gp,m4v,avi';

    private const ALLOWED_REACTIONS = ['👍', '😂', '✅', '❌', '👎', '❤️', '😮'];

    public function __construct(
        protected AdminNotificationService $adminNotifications,
        protected EmployeeNotificationService $employeeNotifications,
        private readonly SupportAccessService $supportAccess,
        private readonly SupportMessageManager $supportMessages,
        private readonly SupportPayloadService $supportPayloads,
        private readonly StoreSupportNotificationService $storeSupportNotifications,
    ) {}

    public function index(Request $request)
    {
        $query = SupportConversation::query()
            ->with([
                'employee.user:id,name',
                'requester:id,name,email,phone',
                'assignee:id,name',
                'suggestion:id,title,category,is_anonymous',
            ])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $canManageEmployee = $request->user() && $this->supportAccess->canManageEmployee($request->user());
        $canManageStore = $request->user() && $this->supportAccess->canManageStore($request->user());
        if (! $canManageEmployee && ! $canManageStore) {
            $query->where('employee_id', $this->employeeId($request));
        } elseif ($canManageEmployee xor $canManageStore) {
            $query->where('source', $canManageStore
                ? SupportConversation::SOURCE_ONLINE_STORE
                : SupportConversation::SOURCE_EMPLOYEE);
        }

        if ($request->filled('source') && in_array($request->input('source'), SupportConversation::SOURCES, true)) {
            $source = (string) $request->input('source');
            abort_if(
                ($source === SupportConversation::SOURCE_ONLINE_STORE && ! $canManageStore)
                || ($source === SupportConversation::SOURCE_EMPLOYEE && ! $canManageEmployee),
                403
            );
            $query->where('source', $source);
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

        if ($request->boolean('needs_reply')) {
            $query->whereNotNull('last_requester_message_at')
                ->where(function ($builder) {
                    $builder->whereNull('last_support_message_at')
                        ->orWhereColumn('last_requester_message_at', '>', 'last_support_message_at');
                });
        }

        if ($request->filled('assignment')) {
            match ($request->input('assignment')) {
                'mine' => $query->where('assigned_to_user_id', $request->user()->id),
                'unassigned' => $query->whereNull('assigned_to_user_id'),
                default => null,
            };
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('last_message', 'like', "%{$search}%")
                    ->orWhereHas('employee.user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('requester', function ($user) use ($search) {
                        $user->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'status' => 'success',
            'can_manage_support' => $this->canManageSupport($request),
            'can_manage_employee_support' => $canManageEmployee,
            'can_manage_store_support' => $canManageStore,
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
            'attachments.*' => ['file', 'max:'.self::ATTACHMENT_MAX_KB, 'mimes:'.self::ATTACHMENT_MIMES],
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
        $requesterUserId = EmployeeDetail::query()
            ->whereKey($employeeId)
            ->value('user_id');

        $conversation = DB::transaction(function () use ($request, $validated, $employeeId, $requesterUserId) {
            $conversation = SupportConversation::create([
                'source' => SupportConversation::SOURCE_EMPLOYEE,
                'employee_id' => $employeeId,
                'created_by_user_id' => $request->user()->id,
                'requester_user_id' => $requesterUserId,
                'context_type' => SupportConversation::CONTEXT_GENERAL,
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
            event(SupportMessageCreated::fromPayloads(
                $conversation,
                $this->messagePayload($message, (int) $request->user()->id),
                $this->conversationPayload($conversation)
            ));
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
            ->with(['attachments', 'senderUser:id,name', 'reactions.user:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 100))
            ->through(fn ($message) => $this->messagePayload($message, (int) $request->user()->id));

        return response()->json([
            'status' => 'success',
            'can_manage_support' => $this->canManageSupport($request),
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
            'client_message_id' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:'.self::ATTACHMENT_MAX_KB, 'mimes:'.self::ATTACHMENT_MIMES],
        ]);

        abort_if(
            ! $request->filled('message') && ! $request->hasFile('attachments'),
            422,
            'message or attachments are required'
        );

        if ($conversation->source === SupportConversation::SOURCE_ONLINE_STORE) {
            $existing = filled($validated['client_message_id'] ?? null)
                ? SupportMessage::query()
                    ->where('support_conversation_id', $conversation->getKey())
                    ->where('client_message_id', $validated['client_message_id'])
                    ->first()
                : null;
            $message = DB::transaction(fn () => $this->supportMessages->create(
                $conversation,
                $request->user(),
                SupportMessage::SENDER_SUPPORT,
                $validated['message'] ?? null,
                $request->file('attachments', []),
                $validated['client_message_id'] ?? null,
            ), 3);
            $conversation = $conversation->fresh()->loadCount('messages');
            $messagePayload = $this->supportPayloads->message($message, (int) $request->user()->id);
            $conversationPayload = $this->supportPayloads->conversation($conversation);
            if (! $existing) {
                event(SupportMessageCreated::fromPayloads($conversation, $messagePayload, $conversationPayload));
                $this->storeSupportNotifications->afterMessage($conversation->load('requester'), $message);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'تم إرسال الرسالة',
                'support_message' => $messagePayload,
                'conversation' => $conversationPayload,
            ], $existing ? 200 : 201);
        }

        $message = DB::transaction(function () use ($request, $conversation, $validated) {
            $message = $this->createMessage($request, $conversation, $validated['message'] ?? null);
            $this->touchConversationAfterMessage($conversation, $message, $request);

            return $message->fresh(['attachments', 'senderUser:id,name', 'reactions.user:id,name']);
        });

        $this->notifyAfterMessage($conversation->fresh(['employee.user']), $message);

        $conversationPayload = $this->conversationPayload(
            $conversation->fresh(['employee.user:id,name', 'assignee:id,name', 'suggestion:id,title,category,is_anonymous'])
        );
        event(SupportMessageCreated::fromPayloads(
            $conversation,
            $this->messagePayload($message, (int) $request->user()->id),
            $conversationPayload
        ));

        return response()->json([
            'status' => 'success',
            'message' => 'تم إرسال الرسالة',
            'support_message' => $this->messagePayload($message, (int) $request->user()->id),
            'conversation' => $conversationPayload,
        ], 201);
    }

    public function reactToMessage(Request $request, SupportConversation $conversation, SupportMessage $message)
    {
        $this->authorizeConversation($request, $conversation);
        abort_unless((int) $message->support_conversation_id === (int) $conversation->id, 404);

        $validated = $request->validate([
            'reaction' => ['nullable', 'string', Rule::in(self::ALLOWED_REACTIONS)],
        ]);

        $reaction = $validated['reaction'] ?? null;
        $userId = (int) $request->user()->id;

        if ($reaction === null || $reaction === '') {
            SupportMessageReaction::query()
                ->where('support_message_id', $message->id)
                ->where('user_id', $userId)
                ->delete();
        } else {
            SupportMessageReaction::updateOrCreate(
                [
                    'support_message_id' => $message->id,
                    'user_id' => $userId,
                ],
                [
                    'employee_detail_id' => $request->user()->employee?->id,
                    'reaction' => $reaction,
                ]
            );

            $this->notifyAfterReaction(
                $conversation->fresh(['employee.user:id,name']),
                $message->fresh(['senderUser:id,name,type', 'senderEmployee.user:id,name']),
                $request,
                $reaction
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث التفاعل',
            'support_message' => $this->messagePayload(
                $message->fresh(['attachments', 'senderUser:id,name', 'reactions.user:id,name']),
                $userId
            ),
        ]);
    }

    public function markRead(Request $request, SupportConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        $this->canManageSupport($request)
            ? $conversation->update(['support_unread_count' => 0])
            : $conversation->update(['employee_unread_count' => 0]);

        $payload = $this->conversationPayload($conversation->fresh(['employee.user:id,name', 'assignee:id,name']));
        event(new SupportConversationRead((int) $conversation->getKey(), $payload));

        return response()->json([
            'status' => 'success',
            'conversation' => $payload,
        ]);
    }

    public function updateStatus(Request $request, SupportConversation $conversation)
    {
        abort_unless(
            $conversation->source === SupportConversation::SOURCE_ONLINE_STORE
                ? $this->supportAccess->canManageStore($request->user())
                : $this->supportAccess->canManageEmployee($request->user()),
            403
        );

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(SupportConversation::STATUSES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assign_to_me' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'status' => $validated['status'],
            'assigned_to_user_id' => ($validated['assign_to_me'] ?? false)
                ? $request->user()->id
                : ($validated['assigned_to_user_id'] ?? $conversation->assigned_to_user_id),
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
            $canEmployee = $this->supportAccess->canManageEmployee($request->user());
            $canStore = $this->supportAccess->canManageStore($request->user());
            if ($canEmployee xor $canStore) {
                $query->where('source', $canStore
                    ? SupportConversation::SOURCE_ONLINE_STORE
                    : SupportConversation::SOURCE_EMPLOYEE);
            }
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
        $type = $this->attachmentType($file);

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
            $updates['requester_unread_count'] = DB::raw('requester_unread_count + 1');
            $updates['last_support_message_at'] = now();
            if (! $conversation->first_support_response_at) {
                $updates['first_support_response_at'] = now();
            }
        } else {
            $updates['support_unread_count'] = DB::raw('support_unread_count + 1');
            $updates['employee_unread_count'] = 0;
            $updates['requester_unread_count'] = 0;
            $updates['last_requester_message_at'] = now();
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

    private function notifyAfterReaction(SupportConversation $conversation, SupportMessage $message, Request $request, string $reaction): void
    {
        $reactor = $request->user();
        if (! $reactor || (int) $message->sender_user_id === (int) $reactor->id) {
            return;
        }

        $reactorName = (string) ($reactor->name ?? 'مستخدم');
        $body = "{$reactorName} تفاعل مع رسالتك {$reaction}";
        $data = array_merge($this->notificationData($conversation, $message), [
            'reaction' => $reaction,
            'reactor_user_id' => (string) $reactor->id,
            'reactor_name' => $reactorName,
            'source' => 'technical_support_reaction',
        ]);

        if ($message->sender_employee_id) {
            $targetEmployee = EmployeeDetail::query()
                ->with('user:id,name,fcm_token')
                ->find($message->sender_employee_id);

            if ($targetEmployee) {
                $this->employeeNotifications->create(
                    $targetEmployee,
                    EmployeeNotificationService::TYPE_SUPPORT_MESSAGE,
                    'تفاعل جديد في الدعم الفني',
                    $body,
                    $data,
                    'support_message_reaction',
                    $message->id,
                    true
                );
            }

            return;
        }

        if ($message->senderUser?->type === 'admin') {
            $this->adminNotifications->create(
                AdminNotificationService::TYPE_SUPPORT_MESSAGE,
                'تفاعل جديد في الدعم الفني',
                $body,
                $data,
                $conversation->employee_id,
                'support_message_reaction',
                $message->id,
                true
            );
        }
    }

    private function authorizeConversation(Request $request, SupportConversation $conversation): void
    {
        abort_unless($this->supportAccess->canAccess($request->user(), $conversation), 403);
    }

    private function canManageSupport(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        return $this->supportAccess->canManageInbox($user);
    }

    private function employeeId(Request $request): int
    {
        return (int) ($request->user()->employee?->id ?? 0);
    }

    private function messageType(array $attachments): string
    {
        $first = $attachments[0] ?? null;
        if ($first instanceof UploadedFile) {
            return $this->attachmentType($first);
        }

        return SupportMessage::TYPE_TEXT;
    }

    private function attachmentType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true) => SupportMessage::TYPE_IMAGE,
            in_array($extension, ['mp3', 'm4a', 'aac', 'ogg', 'wav'], true) => SupportMessage::TYPE_AUDIO,
            in_array($extension, ['mp4', 'mov', 'webm', '3gp', 'm4v', 'avi'], true) => SupportMessage::TYPE_VIDEO,
            str_starts_with($mime, 'image/') => SupportMessage::TYPE_IMAGE,
            str_starts_with($mime, 'audio/') => SupportMessage::TYPE_AUDIO,
            str_starts_with($mime, 'video/') => SupportMessage::TYPE_VIDEO,
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
        return $this->supportPayloads->conversation($conversation);
    }

    private function messagePayload(SupportMessage $message, ?int $viewerUserId = null): array
    {
        return $this->supportPayloads->message($message, $viewerUserId);
    }
}
