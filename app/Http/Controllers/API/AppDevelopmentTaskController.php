<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppDevelopmentTask;
use App\Models\AppDevelopmentTaskAttachment;
use App\Models\AppDevelopmentTaskMessage;
use App\Models\AppDevelopmentTaskMessageReaction;
use App\Models\AppDevelopmentTaskStatusLog;
use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AppDevelopmentTaskController extends Controller
{
    private const ATTACHMENT_MAX_KB = 102400;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,heic,heif,pdf,doc,docx,xls,xlsx,txt,zip,rar,mp3,m4a,aac,ogg,wav,mp4,mov,webm,3gp,m4v,avi';

    private const ALLOWED_ATTACHMENT_TYPES = ['image', 'audio', 'video', 'document'];

    private const ALLOWED_REACTIONS = ['👍', '😂', '✅', '❌', '👎', '❤️', '😮'];

    public function __construct(
        private readonly AdminNotificationService $adminNotifications
    ) {}

    public function metadata(Request $request)
    {
        $this->authorizeDevelopment($request);

        $admins = User::query()
            ->where('type', 'admin')
            ->whereNull('deleted_at')
            ->where('is_blocked', false)
            ->whereIn('development_role', [
                User::DEVELOPMENT_ROLE_OWNER,
                User::DEVELOPMENT_ROLE_DEVELOPER,
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'development_role']);

        return response()->json([
            'status' => 'success',
            'current_development_role' => $request->user()->development_role ?? User::DEVELOPMENT_ROLE_NONE,
            'statuses' => AppDevelopmentTask::STATUSES,
            'priorities' => AppDevelopmentTask::PRIORITIES,
            'owners' => $admins->where('development_role', User::DEVELOPMENT_ROLE_OWNER)->values(),
            'developers' => $admins->where('development_role', User::DEVELOPMENT_ROLE_DEVELOPER)->values(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeDevelopment($request);

        $query = AppDevelopmentTask::query()
            ->with(['creator:id,name,development_role', 'assignee:id,name,development_role'])
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($q) => $q->whereIn('status', [
                    AppDevelopmentTask::STATUS_DONE,
                    AppDevelopmentTask::STATUS_CLOSED,
                ]),
                'messages',
                'attachments',
            ])
            ->whereNull('parent_id')
            ->latest('updated_at');

        $this->scopeForUser($query, $request->user());

        if ($request->filled('status') && in_array($request->input('status'), AppDevelopmentTask::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority') && in_array($request->input('priority'), AppDevelopmentTask::PRIORITIES, true)) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('assigned_to_user_id')) {
            $query->where('assigned_to_user_id', (int) $request->input('assigned_to_user_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $stats = $this->statsForUser($request->user());
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'status' => 'success',
            'stats' => $stats,
            'tasks' => $query->paginate($perPage)->through(fn ($task) => $this->taskPayload($task)),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeDevelopment($request);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:app_development_tasks,id'],
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', 'string', Rule::in(AppDevelopmentTask::PRIORITIES)],
            'manual_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:'.self::ATTACHMENT_MAX_KB, 'mimes:'.self::ATTACHMENT_MIMES],
            'attachment_types' => ['nullable', 'array', 'max:10'],
            'attachment_types.*' => ['nullable', 'string', Rule::in(self::ALLOWED_ATTACHMENT_TYPES)],
        ]);

        $assignee = User::query()
            ->where('type', 'admin')
            ->where('development_role', User::DEVELOPMENT_ROLE_DEVELOPER)
            ->findOrFail((int) $validated['assigned_to_user_id']);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = AppDevelopmentTask::query()->findOrFail((int) $validated['parent_id']);
            abort_unless($this->canAccessTask($request->user(), $parent), 403);
        }

        $task = DB::transaction(function () use ($request, $validated, $assignee, $parent) {
            $task = AppDevelopmentTask::create([
                'parent_id' => $parent?->id,
                'created_by_user_id' => $request->user()->id,
                'assigned_to_user_id' => $assignee->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => AppDevelopmentTask::STATUS_NEW,
                'priority' => $validated['priority'] ?? AppDevelopmentTask::PRIORITIES[1],
                'manual_progress' => $validated['manual_progress'] ?? null,
            ]);

            AppDevelopmentTaskStatusLog::create([
                'app_development_task_id' => $task->id,
                'changed_by_user_id' => $request->user()->id,
                'old_status' => null,
                'new_status' => AppDevelopmentTask::STATUS_NEW,
                'note' => 'created',
            ]);

            $message = null;
            if (! empty($validated['description'])) {
                $message = $this->createMessage($task, $request->user()->id, $validated['description']);
            }

            $this->storeAttachments($request, $task, $message);

            return $task;
        });

        $this->notifyOtherParty($task->fresh(), $request->user(), 'تمت إضافة مهمة تطوير', $task->title);

        return response()->json([
            'status' => 'success',
            'task' => $this->taskPayload($task->fresh()),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizeDevelopment($request);

        $task = AppDevelopmentTask::query()
            ->with([
                'creator:id,name,development_role',
                'assignee:id,name,development_role',
                'parent:id,title,status',
                'subtasks.creator:id,name,development_role',
                'subtasks.assignee:id,name,development_role',
                'attachments',
                'messages.sender:id,name,development_role',
                'messages.attachments',
                'messages.reactions.user:id,name',
                'statusLogs.changer:id,name',
            ])
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($q) => $q->whereIn('status', [
                    AppDevelopmentTask::STATUS_DONE,
                    AppDevelopmentTask::STATUS_CLOSED,
                ]),
                'messages',
                'attachments',
            ])
            ->findOrFail($id);

        abort_unless($this->canAccessTask($request->user(), $task), 403);

        return response()->json([
            'status' => 'success',
            'task' => $this->taskPayload($task, true),
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->authorizeDevelopment($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(AppDevelopmentTask::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'manual_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $task = AppDevelopmentTask::query()->findOrFail($id);
        abort_unless($this->canAccessTask($request->user(), $task), 403);

        $oldStatus = $task->status;
        $newStatus = $validated['status'];

        DB::transaction(function () use ($request, $validated, $task, $oldStatus, $newStatus) {
            $payload = [
                'status' => $newStatus,
                'manual_progress' => $validated['manual_progress'] ?? $task->manual_progress,
            ];

            if ($newStatus === AppDevelopmentTask::STATUS_IN_PROGRESS && $task->started_at === null) {
                $payload['started_at'] = now();
            }

            if ($newStatus === AppDevelopmentTask::STATUS_DONE) {
                $payload['completed_at'] = now();
            }

            if ($newStatus === AppDevelopmentTask::STATUS_CLOSED) {
                $payload['closed_at'] = now();
            }

            $task->update($payload);

            AppDevelopmentTaskStatusLog::create([
                'app_development_task_id' => $task->id,
                'changed_by_user_id' => $request->user()->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'note' => $validated['note'] ?? null,
            ]);

            if (! empty($validated['note'])) {
                $this->createMessage($task, $request->user()->id, $validated['note'], 'system');
            }
        });

        if ($oldStatus !== $newStatus) {
            $this->notifyOtherParty($task->fresh(), $request->user(), 'تم تغيير حالة مهمة تطوير', $task->title.' - '.$this->statusLabel($newStatus));
        }

        return response()->json([
            'status' => 'success',
            'task' => $this->taskPayload($task->fresh()),
        ]);
    }

    public function storeMessage(Request $request, int $id)
    {
        $this->authorizeDevelopment($request);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:'.self::ATTACHMENT_MAX_KB, 'mimes:'.self::ATTACHMENT_MIMES],
            'attachment_types' => ['nullable', 'array', 'max:10'],
            'attachment_types.*' => ['nullable', 'string', Rule::in(self::ALLOWED_ATTACHMENT_TYPES)],
        ]);

        abort_if(! $request->filled('body') && ! $request->hasFile('attachments'), 422, 'body or attachments are required');

        $task = AppDevelopmentTask::query()->findOrFail($id);
        abort_unless($this->canAccessTask($request->user(), $task), 403);

        $message = DB::transaction(function () use ($request, $validated, $task) {
            $message = $this->createMessage($task, $request->user()->id, $validated['body'] ?? null);
            $this->storeAttachments($request, $task, $message);

            return $message;
        });

        $this->notifyOtherParty($task->fresh(), $request->user(), 'تعليق جديد على مهمة تطوير', $task->title);

        return response()->json([
            'status' => 'success',
            'message' => $this->messagePayload($message->fresh(['sender', 'attachments', 'reactions.user'])),
        ]);
    }

    public function reactToMessage(Request $request, int $id, int $messageId)
    {
        $this->authorizeDevelopment($request);

        $validated = $request->validate([
            'reaction' => ['nullable', 'string', Rule::in(self::ALLOWED_REACTIONS)],
        ]);

        $task = AppDevelopmentTask::query()->findOrFail($id);
        abort_unless($this->canAccessTask($request->user(), $task), 403);

        $message = AppDevelopmentTaskMessage::query()
            ->where('app_development_task_id', $task->id)
            ->findOrFail($messageId);

        $reaction = $validated['reaction'] ?? null;
        $userId = (int) $request->user()->id;

        if ($reaction === null || $reaction === '') {
            AppDevelopmentTaskMessageReaction::query()
                ->where('app_development_task_message_id', $message->id)
                ->where('user_id', $userId)
                ->delete();
        } else {
            AppDevelopmentTaskMessageReaction::updateOrCreate(
                [
                    'app_development_task_message_id' => $message->id,
                    'user_id' => $userId,
                ],
                ['reaction' => $reaction]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => $this->messagePayload(
                $message->fresh(['sender:id,name,development_role', 'attachments', 'reactions.user:id,name'])
            ),
        ]);
    }

    private function authorizeDevelopment(Request $request): void
    {
        abort_unless($request->user()?->type === 'admin', 403);
        abort_unless(in_array($request->user()->development_role ?? User::DEVELOPMENT_ROLE_NONE, [
            User::DEVELOPMENT_ROLE_OWNER,
            User::DEVELOPMENT_ROLE_DEVELOPER,
        ], true), 403);
    }

    private function scopeForUser($query, User $user): void
    {
        if (($user->development_role ?? null) === User::DEVELOPMENT_ROLE_OWNER) {
            return;
        }

        $query->where(function ($q) use ($user) {
            $q->where('assigned_to_user_id', $user->id)
                ->orWhere('created_by_user_id', $user->id);
        });
    }

    private function canAccessTask(User $user, AppDevelopmentTask $task): bool
    {
        if (($user->development_role ?? null) === User::DEVELOPMENT_ROLE_OWNER) {
            return true;
        }

        return (int) $task->assigned_to_user_id === (int) $user->id
            || (int) $task->created_by_user_id === (int) $user->id;
    }

    private function createMessage(AppDevelopmentTask $task, int $senderUserId, ?string $body, string $type = 'text'): AppDevelopmentTaskMessage
    {
        $message = AppDevelopmentTaskMessage::create([
            'app_development_task_id' => $task->id,
            'sender_user_id' => $senderUserId,
            'message_type' => $type,
            'body' => $body,
        ]);

        $task->touch();

        return $message;
    }

    private function storeAttachments(Request $request, AppDevelopmentTask $task, ?AppDevelopmentTaskMessage $message = null): void
    {
        $attachmentTypes = $request->input('attachment_types', []);

        foreach ($request->file('attachments', []) as $index => $file) {
            if ($file instanceof UploadedFile) {
                $path = $file->store('app-development-tasks/'.$task->id, 'public');
                $forcedType = is_array($attachmentTypes) ? ($attachmentTypes[$index] ?? null) : null;

                AppDevelopmentTaskAttachment::create([
                    'app_development_task_id' => $task->id,
                    'app_development_task_message_id' => $message?->id,
                    'disk' => 'public',
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize() ?: 0,
                    'attachment_type' => in_array($forcedType, self::ALLOWED_ATTACHMENT_TYPES, true)
                        ? $forcedType
                        : $this->attachmentType($file),
                ]);
            }
        }
    }

    private function notifyOtherParty(AppDevelopmentTask $task, User $actor, string $title, string $body): void
    {
        $recipientId = (int) $task->assigned_to_user_id === (int) $actor->id
            ? (int) $task->created_by_user_id
            : (int) $task->assigned_to_user_id;

        if ($recipientId <= 0 || $recipientId === (int) $actor->id) {
            return;
        }

        $this->adminNotifications->create(
            AdminNotificationService::TYPE_APP_DEVELOPMENT_TASK,
            $title,
            $body,
            [
                'screen' => 'app_development_task',
                'app_development_task_id' => (string) $task->id,
                'task_id' => (string) $task->id,
                'actor_user_id' => (string) $actor->id,
                'actor_name' => (string) $actor->name,
            ],
            null,
            'app_development_task',
            (int) $task->id,
            true,
            $recipientId
        );
    }

    private function statsForUser(User $user): array
    {
        $query = AppDevelopmentTask::query()->whereNull('parent_id');
        $this->scopeForUser($query, $user);

        $rows = $query->get(['status']);
        $total = $rows->count();
        $done = $rows->whereIn('status', [AppDevelopmentTask::STATUS_DONE, AppDevelopmentTask::STATUS_CLOSED])->count();

        return [
            'total' => $total,
            'new' => $rows->where('status', AppDevelopmentTask::STATUS_NEW)->count(),
            'in_progress' => $rows->where('status', AppDevelopmentTask::STATUS_IN_PROGRESS)->count(),
            'waiting_owner' => $rows->where('status', AppDevelopmentTask::STATUS_WAITING_OWNER)->count(),
            'done' => $done,
            'progress_percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    }

    private function taskPayload(AppDevelopmentTask $task, bool $details = false): array
    {
        $task->loadMissing(['creator:id,name,development_role', 'assignee:id,name,development_role']);

        $payload = [
            'id' => $task->id,
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'status_label' => $this->statusLabel($task->status),
            'priority' => $task->priority,
            'priority_label' => $this->priorityLabel($task->priority),
            'progress' => $this->progress($task),
            'created_by_user_id' => $task->created_by_user_id,
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'creator' => $task->creator,
            'assignee' => $task->assignee,
            'subtasks_count' => (int) ($task->subtasks_count ?? $task->subtasks()->count()),
            'completed_subtasks_count' => (int) ($task->completed_subtasks_count ?? 0),
            'messages_count' => (int) ($task->messages_count ?? $task->messages()->count()),
            'attachments_count' => (int) ($task->attachments_count ?? $task->attachments()->count()),
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'closed_at' => $task->closed_at,
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];

        if ($details) {
            $payload['parent'] = $task->parent;
            $payload['subtasks'] = $task->subtasks->map(fn ($subtask) => $this->taskPayload($subtask));
            $payload['attachments'] = $task->attachments->map(fn ($attachment) => $this->attachmentPayload($attachment));
            $payload['messages'] = $task->messages->sortBy('id')->values()->map(fn ($message) => $this->messagePayload($message));
            $payload['status_logs'] = $task->statusLogs->sortByDesc('id')->values()->map(fn ($log) => [
                'id' => $log->id,
                'old_status' => $log->old_status,
                'new_status' => $log->new_status,
                'new_status_label' => $this->statusLabel($log->new_status),
                'note' => $log->note,
                'changed_by_user_id' => $log->changed_by_user_id,
                'changer' => $log->changer,
                'created_at' => $log->created_at,
            ]);
        }

        return $payload;
    }

    private function messagePayload(AppDevelopmentTaskMessage $message): array
    {
        $message->loadMissing(['sender:id,name,development_role', 'attachments', 'reactions.user:id,name']);
        $viewerUserId = auth()->id();
        $reactions = $message->reactions;

        return [
            'id' => $message->id,
            'sender_user_id' => $message->sender_user_id,
            'sender' => $message->sender,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'attachments' => $message->attachments->map(fn ($attachment) => $this->attachmentPayload($attachment)),
            'reactions' => $reactions
                ->groupBy('reaction')
                ->map(fn ($items, $reaction) => [
                    'reaction' => (string) $reaction,
                    'count' => $items->count(),
                    'reacted' => $viewerUserId !== null && $items->contains(fn ($item) => (int) $item->user_id === (int) $viewerUserId),
                    'users' => $items
                        ->map(fn ($item) => $item->user?->name)
                        ->filter()
                        ->values(),
                ])
                ->values(),
            'my_reaction' => $viewerUserId === null
                ? null
                : optional($reactions->first(fn ($item) => (int) $item->user_id === (int) $viewerUserId))->reaction,
            'created_at' => $message->created_at,
        ];
    }

    private function attachmentPayload(AppDevelopmentTaskAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => $attachment->url,
            'path' => $attachment->path,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'attachment_type' => $attachment->attachment_type,
            'created_at' => $attachment->created_at,
        ];
    }

    private function progress(AppDevelopmentTask $task): int
    {
        $total = (int) ($task->subtasks_count ?? $task->subtasks()->count());
        if ($total > 0) {
            $done = (int) ($task->completed_subtasks_count ?? $task->subtasks()
                ->whereIn('status', [AppDevelopmentTask::STATUS_DONE, AppDevelopmentTask::STATUS_CLOSED])
                ->count());

            return (int) round(($done / $total) * 100);
        }

        if ($task->manual_progress !== null) {
            return max(0, min(100, (int) $task->manual_progress));
        }

        return match ($task->status) {
            AppDevelopmentTask::STATUS_NEW => 0,
            AppDevelopmentTask::STATUS_REVIEW => 10,
            AppDevelopmentTask::STATUS_IN_PROGRESS => 50,
            AppDevelopmentTask::STATUS_WAITING_OWNER => 80,
            AppDevelopmentTask::STATUS_DONE,
            AppDevelopmentTask::STATUS_CLOSED => 100,
            default => 0,
        };
    }

    private function attachmentType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['mp3', 'm4a', 'aac', 'ogg', 'wav'], true)) {
            return 'audio';
        }

        if (in_array($extension, ['mp4', 'mov', 'webm', '3gp', 'm4v', 'avi'], true)) {
            return 'video';
        }

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }

    private function statusLabel(string $status): string
    {
        return [
            AppDevelopmentTask::STATUS_NEW => 'جديدة',
            AppDevelopmentTask::STATUS_REVIEW => 'قيد المراجعة',
            AppDevelopmentTask::STATUS_IN_PROGRESS => 'قيد العمل',
            AppDevelopmentTask::STATUS_WAITING_OWNER => 'بانتظار صاحب التطبيق',
            AppDevelopmentTask::STATUS_DONE => 'منجزة',
            AppDevelopmentTask::STATUS_CLOSED => 'مغلقة',
            AppDevelopmentTask::STATUS_CANCELED => 'ملغاة',
        ][$status] ?? $status;
    }

    private function priorityLabel(string $priority): string
    {
        return [
            'low' => 'منخفضة',
            'normal' => 'عادية',
            'high' => 'عالية',
            'urgent' => 'عاجلة',
        ][$priority] ?? $priority;
    }
}
