<?php

namespace App\Http\Controllers;

use App\Services\UserSessionManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * إدارة جلسات الموظفين والمدراء من الويب (تطوير/إدارة محلية).
 */
class UserSessionsWebController extends Controller
{
    public function __construct(
        private readonly UserSessionManager $sessions
    ) {}

    private function ensureEnabled(): void
    {
        if (! config('user_sessions_manager.web_enabled') && ! config('app.debug')) {
            abort(403, 'User sessions web manager is disabled.');
        }
    }

    public function index(Request $request)
    {
        $this->ensureEnabled();

        $type = $request->string('type')->toString();
        $search = $request->string('search')->toString();

        $users = $this->sessions->listStaffUsers(
            $type !== '' ? $type : null,
            $search !== '' ? $search : null,
        );

        return view('user-sessions-manager', [
            'users' => $users,
            'filterType' => $type,
            'search' => $search,
            'flash' => session('flash'),
        ]);
    }

    public function show(int $userId)
    {
        $this->ensureEnabled();

        $data = $this->sessions->userWithSessions($userId);

        return view('user-sessions-detail', [
            'user' => $data['user'],
            'tokens' => $data['tokens'],
            'flash' => session('flash'),
        ]);
    }

    public function logoutAll(int $userId)
    {
        $this->ensureEnabled();

        $user = $this->sessions->findStaffUser($userId);
        $count = $this->sessions->revokeAllSessions($user);

        return redirect()
            ->route('test.user-sessions.show', $userId)
            ->with('flash', [
                'type' => 'success',
                'message' => "تم تسجيل الخروج من جميع الجلسات ({$count} رمز وصول).",
            ]);
    }

    public function revokeToken(int $tokenId)
    {
        $this->ensureEnabled();

        $token = \Laravel\Sanctum\PersonalAccessToken::query()->findOrFail($tokenId);
        $userId = (int) $token->tokenable_id;

        $this->sessions->revokeSession($tokenId);

        return redirect()
            ->route('test.user-sessions.show', $userId)
            ->with('flash', [
                'type' => 'success',
                'message' => 'تم إنهاء الجلسة المحددة.',
            ]);
    }

    public function changePassword(Request $request, int $userId)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(6)],
        ]);

        $user = $this->sessions->findStaffUser($userId);
        $this->sessions->changePassword($user, $validated['password']);

        return redirect()
            ->route('test.user-sessions.show', $userId)
            ->with('flash', [
                'type' => 'success',
                'message' => 'تم تحديث كلمة المرور بنجاح.',
            ]);
    }
}
