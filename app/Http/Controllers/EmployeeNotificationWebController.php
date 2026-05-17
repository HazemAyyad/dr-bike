<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDetail;
use App\Models\EmployeeNotification;
use App\Models\User;
use App\Services\EmployeeNotificationService;
use App\Services\FirebaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * صفحة ويب لإرسال إشعارات للموظفين (قاعدة البيانات + FCM).
 */
class EmployeeNotificationWebController extends Controller
{
    protected function expectedToken(): string
    {
        return (string) env('EMPLOYEE_NOTIFY_WEB_TOKEN', env('ADMIN_NOTIFY_WEB_TOKEN', ''));
    }

    protected function authorizeRequest(Request $request): void
    {
        $expected = $this->expectedToken();
        if ($expected === '') {
            return;
        }

        $given = (string) $request->input('token', $request->query('token', ''));
        if ($given === '' || ! hash_equals($expected, $given)) {
            abort(403, 'رمز الوصول غير صحيح. أضف ?token=... أو EMPLOYEE_NOTIFY_WEB_TOKEN في .env');
        }
    }

    public function show(Request $request, FirebaseService $firebaseService)
    {
        $this->authorizeRequest($request);

        $latestUser = $this->latestEmployeeWithFcm();
        $authQuery = $this->authQueryParams($request);

        return view('employee-notify-test', [
            'token' => $request->query('token', ''),
            'tokenRequired' => $this->expectedToken() !== '',
            'employeeTokenCount' => $this->employeesWithFcmCount(),
            'unreadCount' => EmployeeNotification::query()->where('is_read', false)->count(),
            'types' => $this->notificationTypes(),
            'employees' => $this->employeeOptions(),
            'result' => session('result'),
            'latestUser' => $latestUser,
            'fcmTestLatestUrl' => route('test.employee-notify.fcm-test', $authQuery),
            'fcmTestUrls' => $this->fcmTestUrlExamples($request, $latestUser),
            'firebaseDiagnostics' => $firebaseService->credentialsDiagnostics(),
            'flutterExpectedProjectId' => 'drbike-7fa3a',
        ]);
    }

    public function fcmTest(Request $request, FirebaseService $firebaseService): RedirectResponse
    {
        $this->authorizeRequest($request);

        $fcmToken = trim((string) $request->query('fcm_token', ''));

        return $this->runFcmTestAndRedirect(
            $request,
            $firebaseService,
            $fcmToken !== '' ? $fcmToken : null
        );
    }

    public function fcmTestWithToken(Request $request, FirebaseService $firebaseService): RedirectResponse
    {
        $this->authorizeRequest($request);

        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:512'],
        ]);

        return $this->runFcmTestAndRedirect($request, $firebaseService, $validated['fcm_token']);
    }

    protected function runFcmTestAndRedirect(
        Request $request,
        FirebaseService $firebaseService,
        ?string $fcmToken
    ): RedirectResponse {
        $usedLatest = false;
        $userId = null;

        if ($fcmToken === null || $fcmToken === '') {
            $user = $this->latestEmployeeWithFcm();
            if ($user === null) {
                return redirect()
                    ->route('test.employee-notify', $this->authQueryParams($request))
                    ->with('result', [
                        'ok' => false,
                        'message' => 'لا يوجد موظف بتوكن FCM. سجّل دخول موظف من التطبيق أولاً.',
                    ]);
            }
            $fcmToken = $user->fcm_token;
            $usedLatest = true;
            $userId = $user->id;
        }

        $result = $firebaseService->sendEmployeeFcmTest($fcmToken, $usedLatest, $userId);

        return redirect()
            ->route('test.employee-notify', $this->authQueryParams($request))
            ->with('result', array_merge($result, ['mode' => 'fcm_test']));
    }

    public function send(Request $request, EmployeeNotificationService $employeeNotificationService)
    {
        $this->authorizeRequest($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'string', 'max:64'],
            'send_push' => ['nullable', 'boolean'],
            'employee_id' => ['required_without:send_to_all', 'nullable', 'integer', 'exists:employee_details,id'],
            'send_to_all' => ['nullable', 'boolean'],
        ]);

        $sendPush = $request->input('send_push', '0') === '1';
        $sendToAll = $request->boolean('send_to_all');

        $targets = $sendToAll
            ? EmployeeDetail::query()
                ->whereHas('user', fn ($q) => $q->where('type', 'employee'))
                ->with('user:id,fcm_token')
                ->get()
            : collect([EmployeeDetail::query()->with('user:id,fcm_token')->findOrFail($validated['employee_id'])]);

        if ($targets->isEmpty()) {
            return redirect()
                ->route('test.employee-notify', $this->authQueryParams($request))
                ->with('result', [
                    'ok' => false,
                    'message' => 'لم يُحدد موظف.',
                ]);
        }

        $created = 0;
        foreach ($targets as $employee) {
            $employeeNotificationService->create(
                $employee,
                $validated['type'],
                $validated['title'],
                $validated['body'],
                ['source' => 'web_test_page', 'sent_at' => now()->toIso8601String()],
                null,
                null,
                $sendPush
            );
            $created++;
        }

        return redirect()
            ->route('test.employee-notify', $this->authQueryParams($request))
            ->with('result', [
                'ok' => true,
                'message' => $sendPush
                    ? "تم الحفظ وإرسال الإشعار لـ {$created} موظف/موظفين."
                    : "تم الحفظ في مركز إشعارات الموظفين لـ {$created} موظف/موظفين (بدون FCM).",
                'notification_count' => $created,
            ]);
    }

    protected function latestEmployeeWithFcm(): ?User
    {
        return User::query()
            ->where('type', 'employee')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->where('fcm_token', '!=', 'no_token')
            ->orderByDesc('updated_at')
            ->first(['id', 'name', 'email', 'fcm_token', 'updated_at']);
    }

    protected function employeesWithFcmCount(): int
    {
        return User::query()
            ->where('type', 'employee')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->where('fcm_token', '!=', 'no_token')
            ->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, has_fcm: bool}>
     */
    protected function employeeOptions()
    {
        return User::query()
            ->where('type', 'employee')
            ->whereHas('employee')
            ->with('employee:id,user_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'fcm_token'])
            ->map(fn (User $u) => [
                'id' => (int) $u->employee->id,
                'name' => $u->name,
                'email' => $u->email,
                'has_fcm' => $this->isValidFcm($u->fcm_token),
            ]);
    }

    protected function isValidFcm(?string $token): bool
    {
        $t = trim((string) $token);

        return $t !== '' && $t !== 'no_token';
    }

    /**
     * @return array<string, string>
     */
    protected function authQueryParams(Request $request): array
    {
        $webToken = (string) $request->query('token', $request->input('token', ''));

        return $webToken !== '' ? ['token' => $webToken] : [];
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    protected function fcmTestUrlExamples(Request $request, ?User $latestUser): array
    {
        $auth = $this->authQueryParams($request);
        $examples = [
            [
                'label' => 'أحدث موظف بتوكن FCM',
                'url' => route('test.employee-notify.fcm-test', $auth),
            ],
        ];

        if ($latestUser !== null) {
            $examples[] = [
                'label' => 'نفس التوكن في الرابط (للنسخ)',
                'url' => route('test.employee-notify.fcm-test', array_merge($auth, [
                    'fcm_token' => $latestUser->fcm_token,
                ])),
            ];
        }

        return $examples;
    }

    /**
     * @return array<string, string>
     */
    protected function notificationTypes(): array
    {
        return [
            'employee_manual' => 'إشعار يدوي (عام)',
            EmployeeNotificationService::TYPE_DAILY_TASKS => 'مهام اليوم',
        ];
    }
}
