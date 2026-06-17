<?php

namespace App\Http\Controllers;

use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use App\Services\AdminNotificationService;
use App\Services\FirebaseService;
use App\Services\SalesOrderNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * صفحة ويب بسيطة لإرسال إشعارات للأدمن (قاعدة البيانات + FCM).
 * للتجربة فقط — احمِ الرابط على الإنتاج (رمز في .env).
 */
class AdminNotificationWebController extends Controller
{
    protected function expectedToken(): string
    {
        return (string) env('ADMIN_NOTIFY_WEB_TOKEN', '');
    }

    protected function authorizeRequest(Request $request): void
    {
        $expected = $this->expectedToken();
        if ($expected === '') {
            return;
        }

        $given = (string) $request->input('token', $request->query('token', ''));
        if ($given === '' || ! hash_equals($expected, $given)) {
            abort(403, 'رمز الوصول غير صحيح. أضف ?token=... أو ADMIN_NOTIFY_WEB_TOKEN في .env');
        }
    }

    public function show(Request $request, FirebaseService $firebaseService)
    {
        $this->authorizeRequest($request);

        $latestDevice = AdminDeviceToken::query()->orderByDesc('id')->first();
        $authQuery = $this->authQueryParams($request);

        $firebaseDiagnostics = $firebaseService->credentialsDiagnostics();

        return view('admin-notify-test', [
            'token' => $request->query('token', ''),
            'tokenRequired' => $this->expectedToken() !== '',
            'deviceCount' => AdminDeviceToken::query()->count(),
            'unreadCount' => AdminNotification::query()->where('is_read', false)->count(),
            'types' => $this->notificationTypes(),
            'result' => session('result'),
            'latestDevice' => $latestDevice,
            'fcmTestLatestUrl' => route('test.admin-notify.fcm-test', $authQuery),
            'fcmTestUrls' => $this->fcmTestUrlExamples($request, $latestDevice),
            'firebaseDiagnostics' => $firebaseDiagnostics,
            'flutterExpectedProjectId' => 'drbike-7fa3a',
        ]);
    }

    /**
     * GET — same as `php artisan admin:fcm-test` (latest device).
     */
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

    /**
     * POST — same as `php artisan admin:fcm-test {token}`.
     */
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
        $deviceTokenId = null;

        if ($fcmToken === null || $fcmToken === '') {
            $row = AdminDeviceToken::query()->orderByDesc('id')->first();
            if ($row === null) {
                return redirect()
                    ->route('test.admin-notify', $this->authQueryParams($request))
                    ->with('result', [
                        'ok' => false,
                        'message' => 'لا يوجد جهاز أدمن مسجّل. سجّل دخول أدمن من التطبيق أولاً.',
                    ]);
            }
            $fcmToken = $row->fcm_token;
            $usedLatest = true;
            $deviceTokenId = $row->id;
        }

        $result = $firebaseService->sendAdminFcmTest($fcmToken, $usedLatest, $deviceTokenId);

        return redirect()
            ->route('test.admin-notify', $this->authQueryParams($request))
            ->with('result', array_merge($result, [
                'mode' => 'fcm_test',
            ]));
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
    protected function fcmTestUrlExamples(Request $request, ?AdminDeviceToken $latestDevice): array
    {
        $auth = $this->authQueryParams($request);
        $examples = [
            [
                'label' => 'أحدث جهاز (مثل: php artisan admin:fcm-test)',
                'url' => route('test.admin-notify.fcm-test', $auth),
            ],
        ];

        if ($latestDevice !== null) {
            $examples[] = [
                'label' => 'أحدث جهاز + عرض fcm_token في الرابط (للنسخ)',
                'url' => route('test.admin-notify.fcm-test', array_merge($auth, [
                    'fcm_token' => $latestDevice->fcm_token,
                ])),
            ];
        }

        return $examples;
    }

    public function send(Request $request, AdminNotificationService $adminNotificationService)
    {
        $this->authorizeRequest($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'string', 'max:64'],
            'send_push' => ['nullable', 'boolean'],
        ]);

        $sendPush = $request->input('send_push', '0') === '1';

        $notification = $adminNotificationService->create(
            $validated['type'],
            $validated['title'],
            $validated['body'],
            [
                'source' => 'web_test_page',
                'sent_at' => now()->toIso8601String(),
            ],
            null,
            null,
            null,
            $sendPush
        );

        $deviceCount = AdminDeviceToken::query()->count();

        return redirect()
            ->route('test.admin-notify', ['token' => $request->input('token')])
            ->with('result', [
                'ok' => true,
                'message' => $sendPush
                    ? "تم الحفظ وإرسال الإشعار (رقم #{$notification->id}). أجهزة مسجّلة: {$deviceCount}."
                    : "تم الحفظ في مركز الإشعارات فقط (رقم #{$notification->id})، بدون FCM.",
                'notification_id' => $notification->id,
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function notificationTypes(): array
    {
        return [
            'admin_manual' => 'إشعار يدوي (عام)',
            AdminNotificationService::TYPE_EMPLOYEE_LOGIN => 'دخول موظف',
            AdminNotificationService::TYPE_EMPLOYEE_LOGOUT => 'خروج موظف',
            AdminNotificationService::TYPE_EMPLOYEE_TASK_COMPLETED => 'مهمة مكتملة',
            AdminNotificationService::TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS => 'خروج بمهام معلقة',
            AdminNotificationService::TYPE_ATTENDANCE_AUTO_CHECKOUT => 'خروج تلقائي (نظام)',
            AdminNotificationService::TYPE_ATTENDANCE_ABSENT_REMINDER => 'موظفون لم يدوّموا',
            AdminNotificationService::TYPE_CHECK_DUE_REMINDER => 'شيك يستحق قريباً',
            AdminNotificationService::TYPE_CHECK_CASHED => 'صرف شيك صادر',
            AdminNotificationService::TYPE_CHECK_RETURNED => 'إرجاع شيك صادر',
            AdminNotificationService::TYPE_SUSPENDED_INSTANT_SALE_CREATED => 'فاتورة عالقة جديدة',
            AdminNotificationService::TYPE_SUSPENDED_INSTANT_SALE_COMPLETED => 'إتمام فاتورة عالقة',
            SalesOrderNotificationService::TYPE_SHIPLY_HANDOVER => 'تسليم طلبية لشبلي',
        ];
    }
}
