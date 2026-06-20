<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Logs;
use App\Models\User;
use App\Services\UserSessionManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUsersController extends Controller
{
    public function __construct(
        private readonly UserSessionManager $sessions
    ) {}

    public function index(Request $request)
    {
        try {
            $search = $request->query('search');

            $admins = $this->sessions->listStaffUsers('admin', $search)
                ->map(fn (User $user) => $this->formatAdmin($user));

            return response()->json([
                'status' => 'success',
                'admins' => $admins,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', $this->uniqueActiveEmailRule()],
                'phone' => ['nullable', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'type' => 'admin',
                'is_blocked' => false,
            ]);

            Logs::createLog(
                'إضافة مدير',
                'تم إضافة مدير باسم '.$user->name,
                'admins'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.admin_created_successfully'),
                'admin' => $this->formatAdmin($user->fresh()),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $user = $this->findAdmin($id);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', $this->uniqueActiveEmailRule($user->id)],
                'phone' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            ]);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ];

            if (! empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
                $this->sessions->revokeAllSessions($user);
            }

            $user->update($payload);

            Logs::createLog(
                'تعديل مدير',
                'تم تعديل مدير باسم '.$user->name,
                'admins'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.admin_updated_successfully'),
                'admin' => $this->formatAdmin($user->fresh()),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.admin_not_found'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(int $id)
    {
        try {
            $user = $this->findAdmin($id);
            $this->guardSelfAction($user);

            $name = $user->name;
            $this->sessions->revokeAllSessions($user);
            $user->delete();

            Logs::createLog(
                'حذف مدير',
                'تم حذف مدير باسم '.$name,
                'admins'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.admin_deleted_successfully'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.admin_not_found'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function toggleBlock(int $id)
    {
        try {
            $user = $this->findAdmin($id);
            $this->guardSelfAction($user);

            $blocked = ! (bool) $user->is_blocked;
            $user->update(['is_blocked' => $blocked]);

            if ($blocked) {
                $this->sessions->revokeAllSessions($user);
            }

            Logs::createLog(
                $blocked ? 'حظر مدير' : 'إلغاء حظر مدير',
                ($blocked ? 'تم حظر ' : 'تم إلغاء حظر ').'المدير '.$user->name,
                'admins'
            );

            return response()->json([
                'status' => 'success',
                'message' => $blocked
                    ? __('messages.admin_blocked_successfully')
                    : __('messages.admin_unblocked_successfully'),
                'admin' => $this->formatAdmin($user->fresh()),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.admin_not_found'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function findAdmin(int $id): User
    {
        return User::query()
            ->where('type', 'admin')
            ->whereNull('deleted_at')
            ->findOrFail($id);
    }

    private function guardSelfAction(User $user): void
    {
        if ((int) auth()->id() === (int) $user->id) {
            throw ValidationException::withMessages([
                'admin_id' => [__('messages.cannot_manage_own_admin_account')],
            ]);
        }
    }

    private function uniqueActiveEmailRule(?int $ignoreUserId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('users', 'email')->where(fn ($query) => $query->whereNull('deleted_at'));

        return $ignoreUserId !== null ? $rule->ignore($ignoreUserId) : $rule;
    }

    private function formatAdmin(User $user): array
    {
        $user->loadCount([
            'activeSanctumTokens as active_sessions_count',
            'adminDeviceTokens as admin_fcm_devices_count',
        ]);

        $fcm = $this->sessions->fcmStatusForUser($user);
        $activeSessions = (int) ($user->active_sessions_count ?? 0);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_blocked' => (bool) $user->is_blocked,
            'is_online' => $activeSessions > 0,
            'active_sessions_count' => $activeSessions,
            'admin_fcm_devices_count' => (int) ($user->admin_fcm_devices_count ?? 0),
            'fcm_label' => $fcm['label'],
            'latest_token_at' => $user->latest_token_at ?? null,
            'created_at' => $user->created_at,
        ];
    }
}
