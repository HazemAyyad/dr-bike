<?php

namespace App\Http\Controllers;

use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;

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

    public function show(Request $request)
    {
        $this->authorizeRequest($request);

        return view('admin-notify-test', [
            'token' => $request->query('token', ''),
            'tokenRequired' => $this->expectedToken() !== '',
            'deviceCount' => AdminDeviceToken::query()->count(),
            'unreadCount' => AdminNotification::query()->where('is_read', false)->count(),
            'types' => $this->notificationTypes(),
            'result' => session('result'),
        ]);
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
            AdminNotificationService::TYPE_EMPLOYEE_TASK_COMPLETED => 'مهمة مكتملة',
            AdminNotificationService::TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS => 'خروج بمهام معلقة',
            AdminNotificationService::TYPE_CHECK_DUE_REMINDER => 'شيك يستحق قريباً',
        ];
    }
}
