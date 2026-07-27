<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\NoteCollaborator;
use App\Models\User;
use App\Services\NoteNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class NotesController extends Controller
{
    private const ATTACHMENT_MAX_KB = 102400;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,heic,heif,pdf,doc,docx,xls,xlsx,txt,zip,rar,mp3,m4a,aac,ogg,wav,mp4,mov,webm,3gp,m4v,avi';

    public function users(Request $request)
    {
        $query = User::query()
            ->with('employee:id,user_id,job_title,employee_img')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('is_blocked')->orWhere('is_blocked', false);
            })
            ->whereIn('type', ['admin', 'employee'])
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => 'success',
            'users' => $query->limit(100)->get(['id', 'name', 'email', 'phone', 'type']),
        ]);
    }

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $scope = (string) $request->input('scope', 'active');

        $query = Note::query()
            ->with([
                'owner:id,name,type',
                'owner.employee:id,user_id,job_title,employee_img',
                'collaborators.user:id,name,type',
                'collaborators.user.employee:id,user_id,job_title,employee_img',
            ])
            ->withCount('attachments')
            ->where(fn ($q) => $this->accessibleScope($q, $userId));

        if ($scope === 'mine') {
            $query->where('owner_user_id', $userId);
        } elseif ($scope === 'shared') {
            $query->whereHas('collaborators', fn ($q) => $q->where('user_id', $userId));
        } elseif ($scope === 'public') {
            $query->where('visibility', Note::VISIBILITY_PUBLIC);
        } elseif ($scope === 'archived') {
            $query->where('is_archived', true);
        } else {
            $query->where('is_archived', false);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('plain_text', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn ($owner) => $owner->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        return response()->json([
            'status' => 'success',
            'notes' => $query
                ->orderByDesc('is_pinned')
                ->orderByDesc('updated_at')
                ->paginate($perPage)
                ->through(fn (Note $note) => $this->notePayload($note, $userId)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateNote($request);
        $userId = (int) $request->user()->id;

        $note = DB::transaction(function () use ($validated, $userId) {
            $note = Note::create([
                'owner_user_id' => $userId,
                'title' => $validated['title'] ?? null,
                'body_json' => $validated['body_json'] ?? [],
                'plain_text' => $this->plainText($validated['body_json'] ?? [], $validated['title'] ?? null),
                'color' => $validated['color'] ?? null,
                'visibility' => $validated['visibility'] ?? Note::VISIBILITY_PRIVATE,
                'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
                'is_archived' => (bool) ($validated['is_archived'] ?? false),
                'reminder_at' => $validated['reminder_at'] ?? null,
                'reminder_label' => $validated['reminder_label'] ?? null,
            ]);

            $this->syncCollaborators($note, $validated['collaborators'] ?? [], $userId);

            return $note;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم إنشاء الملاحظة',
            'note' => $this->notePayload($this->freshNote($note), $userId, true),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $note = Note::query()
            ->with(['owner:id,name,type', 'collaborators.user:id,name,type', 'attachments'])
            ->findOrFail($id);

        $this->authorizeView($request, $note);

        return response()->json([
            'status' => 'success',
            'note' => $this->notePayload($note, (int) $request->user()->id, true),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $note = Note::query()->with('collaborators')->findOrFail($id);
        $this->authorizeEdit($request, $note);

        $validated = $this->validateNote($request, true);
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($note, $validated, $userId) {
            $payload = [];

            foreach (['title', 'body_json', 'color', 'visibility', 'is_pinned', 'is_archived', 'reminder_at', 'reminder_label'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('reminder_at', $payload)) {
                $payload['reminder_notified_at'] = null;
            }

            if (array_key_exists('title', $payload) || array_key_exists('body_json', $payload)) {
                $payload['plain_text'] = $this->plainText(
                    $payload['body_json'] ?? $note->body_json ?? [],
                    $payload['title'] ?? $note->title
                );
            }

            if (! empty($payload)) {
                $note->update($payload);
            }

            if ($this->isOwner($note, $userId) && array_key_exists('collaborators', $validated)) {
                $this->syncCollaborators($note, $validated['collaborators'] ?? [], $userId);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الملاحظة',
            'note' => $this->notePayload($this->freshNote($note), $userId, true),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $note = Note::query()->findOrFail($id);
        abort_unless($this->isOwner($note, (int) $request->user()->id), 403);

        $note->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الملاحظة',
        ]);
    }

    public function storeAttachment(Request $request, int $id)
    {
        $note = Note::query()->findOrFail($id);
        $this->authorizeEdit($request, $note);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::ATTACHMENT_MAX_KB, 'mimes:'.self::ATTACHMENT_MIMES],
            'attachment_type' => ['nullable', 'string', Rule::in(NoteAttachment::TYPES)],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $path = $file->store('notes/'.$note->id, 'public');
        $forcedType = $validated['attachment_type'] ?? null;

        $attachment = $note->attachments()->create([
            'uploaded_by_user_id' => $request->user()->id,
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'attachment_type' => in_array($forcedType, NoteAttachment::TYPES, true)
                ? $forcedType
                : $this->attachmentType($file),
        ]);

        $note->touch();

        return response()->json([
            'status' => 'success',
            'message' => 'تم رفع المرفق',
            'attachment' => $this->attachmentPayload($attachment),
        ], 201);
    }

    public function destroyAttachment(Request $request, int $id, int $attachmentId)
    {
        $note = Note::query()->findOrFail($id);
        $this->authorizeEdit($request, $note);

        $attachment = $note->attachments()->findOrFail($attachmentId);
        Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
        $attachment->delete();
        $note->touch();

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف المرفق',
        ]);
    }

    public function syncSharing(Request $request, int $id)
    {
        $note = Note::query()->findOrFail($id);
        $userId = (int) $request->user()->id;
        abort_unless($this->isOwner($note, $userId), 403);

        $validated = $request->validate([
            'visibility' => ['sometimes', 'required', 'string', Rule::in(Note::VISIBILITIES)],
            'collaborators' => ['nullable', 'array', 'max:100'],
            'collaborators.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'collaborators.*.permission' => ['required', 'string', Rule::in(NoteCollaborator::PERMISSIONS)],
        ]);

        DB::transaction(function () use ($note, $validated, $userId) {
            if (array_key_exists('visibility', $validated)) {
                $note->update(['visibility' => $validated['visibility']]);
            }

            if (array_key_exists('collaborators', $validated)) {
                $this->syncCollaborators($note, $validated['collaborators'] ?? [], $userId);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث المشاركة',
            'note' => $this->notePayload($this->freshNote($note), $userId, true),
        ]);
    }

    private function validateNote(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'title' => [$presence, 'nullable', 'string', 'max:255'],
            'body_json' => [$presence, 'nullable', 'array'],
            'color' => [$presence, 'nullable', 'string', 'max:32'],
            'visibility' => [$presence, 'nullable', 'string', Rule::in(Note::VISIBILITIES)],
            'is_pinned' => [$presence, 'boolean'],
            'is_archived' => [$presence, 'boolean'],
            'reminder_at' => [$presence, 'nullable', 'date'],
            'reminder_label' => [$presence, 'nullable', 'string', 'max:255'],
            'collaborators' => [$presence, 'nullable', 'array', 'max:100'],
            'collaborators.*.user_id' => ['required_with:collaborators', 'integer', 'exists:users,id'],
            'collaborators.*.permission' => ['required_with:collaborators', 'string', Rule::in(NoteCollaborator::PERMISSIONS)],
        ]);
    }

    private function accessibleScope(Builder $query, int $userId): void
    {
        $query->where('owner_user_id', $userId)
            ->orWhere('visibility', Note::VISIBILITY_PUBLIC)
            ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $userId));
    }

    private function authorizeView(Request $request, Note $note): void
    {
        $userId = (int) $request->user()->id;

        abort_unless(
            $this->isOwner($note, $userId)
            || $note->visibility === Note::VISIBILITY_PUBLIC
            || $note->collaborators()->where('user_id', $userId)->exists(),
            403
        );
    }

    private function authorizeEdit(Request $request, Note $note): void
    {
        $userId = (int) $request->user()->id;

        abort_unless(
            $this->isOwner($note, $userId)
            || $note->collaborators()
                ->where('user_id', $userId)
                ->where('permission', NoteCollaborator::PERMISSION_EDIT)
                ->exists(),
            403
        );
    }

    private function isOwner(Note $note, int $userId): bool
    {
        return (int) $note->owner_user_id === $userId;
    }

    private function syncCollaborators(Note $note, array $collaborators, int $ownerUserId): void
    {
        $existingUserIds = $note->collaborators()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = collect($collaborators)
            ->map(fn ($row) => [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'permission' => (string) ($row['permission'] ?? NoteCollaborator::PERMISSION_VIEW),
            ])
            ->filter(fn ($row) => $row['user_id'] > 0 && $row['user_id'] !== $ownerUserId)
            ->unique('user_id')
            ->values();

        $keepIds = [];
        foreach ($rows as $row) {
            $collaborator = $note->collaborators()->updateOrCreate(
                ['user_id' => $row['user_id']],
                ['permission' => $row['permission']]
            );
            $keepIds[] = (int) $collaborator->id;
        }

        $note->collaborators()
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();

        if ($rows->isNotEmpty() && $note->visibility === Note::VISIBILITY_PRIVATE) {
            $note->update(['visibility' => Note::VISIBILITY_SHARED]);
        }

        $newUserIds = $rows
            ->pluck('user_id')
            ->diff($existingUserIds)
            ->values()
            ->all();

        if ($newUserIds !== []) {
            DB::afterCommit(function () use ($note, $newUserIds, $ownerUserId) {
                $freshNote = $this->freshNote($note);
                $notifier = app(NoteNotificationService::class);

                foreach ($newUserIds as $userId) {
                    $notifier->notifyCollaboratorAdded($freshNote, (int) $userId, $ownerUserId);
                }
            });
        }
    }

    private function notePayload(Note $note, int $viewerUserId, bool $details = false): array
    {
        $note->loadMissing([
            'owner:id,name,type',
            'owner.employee:id,user_id,job_title,employee_img',
            'collaborators.user:id,name,type',
            'collaborators.user.employee:id,user_id,job_title,employee_img',
        ]);
        $owner = $this->isOwner($note, $viewerUserId);
        $collaborator = $note->collaborators->firstWhere('user_id', $viewerUserId);
        $permission = $owner ? 'owner' : ($collaborator?->permission ?? ($note->visibility === Note::VISIBILITY_PUBLIC ? 'view' : null));

        $payload = [
            'id' => $note->id,
            'title' => $note->title,
            'plain_text' => $note->plain_text,
            'color' => $note->color,
            'visibility' => $note->visibility,
            'is_pinned' => (bool) $note->is_pinned,
            'is_archived' => (bool) $note->is_archived,
            'reminder_at' => optional($note->reminder_at)->toIso8601String(),
            'reminder_label' => $note->reminder_label,
            'owner_user_id' => (int) $note->owner_user_id,
            'owner' => $note->owner,
            'collaborators' => $note->collaborators->map(fn (NoteCollaborator $collaborator) => [
                'id' => $collaborator->id,
                'user_id' => (int) $collaborator->user_id,
                'permission' => $collaborator->permission,
                'user' => $collaborator->user,
            ])->values(),
            'my_permission' => $permission,
            'can_edit' => $owner || $permission === NoteCollaborator::PERMISSION_EDIT,
            'can_manage_sharing' => $owner,
            'attachments_count' => (int) ($note->attachments_count ?? $note->attachments()->count()),
            'created_at' => $note->created_at,
            'updated_at' => $note->updated_at,
        ];

        if ($details) {
            $note->loadMissing('attachments');
            $payload['body_json'] = $note->body_json ?? [];
            $payload['attachments'] = $note->attachments->map(fn (NoteAttachment $attachment) => $this->attachmentPayload($attachment));
        }

        return $payload;
    }

    private function attachmentPayload(NoteAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'type' => $attachment->attachment_type,
            'url' => $attachment->url,
            'path' => $attachment->path,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'created_at' => $attachment->created_at,
        ];
    }

    private function freshNote(Note $note): Note
    {
        return $note->fresh([
            'owner:id,name,type',
            'owner.employee:id,user_id,job_title,employee_img',
            'collaborators.user:id,name,type',
            'collaborators.user.employee:id,user_id,job_title,employee_img',
            'attachments',
        ]);
    }

    private function attachmentType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['mp3', 'm4a', 'aac', 'ogg', 'wav'], true)) {
            return NoteAttachment::TYPE_AUDIO;
        }

        if (in_array($extension, ['mp4', 'mov', 'webm', '3gp', 'm4v', 'avi'], true)) {
            return NoteAttachment::TYPE_VIDEO;
        }

        return match (true) {
            str_starts_with($mime, 'image/') => NoteAttachment::TYPE_IMAGE,
            str_starts_with($mime, 'audio/') => NoteAttachment::TYPE_AUDIO,
            str_starts_with($mime, 'video/') => NoteAttachment::TYPE_VIDEO,
            default => NoteAttachment::TYPE_FILE,
        };
    }

    private function plainText(array $bodyJson, ?string $title): string
    {
        $parts = [];
        if ($title !== null && trim($title) !== '') {
            $parts[] = trim($title);
        }

        $walk = function ($value) use (&$walk, &$parts): void {
            if (is_string($value)) {
                $text = trim($value);
                if ($text !== '') {
                    $parts[] = $text;
                }
                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $key => $child) {
                if (in_array($key, ['text', 'title', 'caption'], true)) {
                    $walk($child);
                } elseif (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($bodyJson);

        return mb_substr(implode(' ', array_unique($parts)), 0, 10000);
    }
}
