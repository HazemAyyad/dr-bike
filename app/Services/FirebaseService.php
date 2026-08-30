<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

class FirebaseService
{
    /** Must match Flutter [kDrBikeAdminNotificationChannelId] and AndroidManifest. */
    public const ADMIN_CHANNEL_ID = 'dr_bike_admin_notifications';

    /** Employee task FCM — must match Flutter [kDrBikeTaskNotificationChannelId] and res/raw/task_sos_alert. */
    public const EMPLOYEE_TASK_CHANNEL_ID = 'dr_bike_task_notifications';

    /** Task completion / success — must match Flutter and res/raw/task_success. */
    public const TASK_SUCCESS_CHANNEL_ID = 'dr_bike_task_success_notifications';

    /** Admin login (check-in) — motivational loud tone, res/raw/admin_login_motivate. */
    public const ADMIN_LOGIN_CHANNEL_ID = 'dr_bike_admin_login_alerts';

    /** Admin logout — must match Flutter and res/raw/task_sos_alert. */
    public const ADMIN_ATTENDANCE_CHANNEL_ID = 'dr_bike_admin_attendance_alerts';

    /** Shiply delivered — coins + end whistle (res/raw/shiply_delivered). */
    public const SHIPLY_DELIVERED_CHANNEL_ID = 'dr_bike_shiply_delivered_finale';

    /** Shiply tracking — motorcycle rev (res/raw/shiply_motorcycle). */
    public const SHIPLY_MOTORCYCLE_CHANNEL_ID = 'dr_bike_shiply_motorcycle';

    /** Shiply pending/stuck — crash impact (res/raw/shiply_stuck). */
    public const SHIPLY_STUCK_CHANNEL_ID = 'dr_bike_shiply_stuck_alert';

    /** Shiply returned — ambulance siren (res/raw/shiply_returned). */
    public const SHIPLY_RETURNED_CHANNEL_ID = 'dr_bike_shiply_returned_ambulance';

    /** Sales order status change — church bell (res/raw/sales_order_church_bell). */
    public const SALES_ORDER_STATUS_CHANNEL_ID = 'dr_bike_sales_order_status';

    public const SALES_ORDER_STATUS_SOUND_ANDROID = 'sales_order_church_bell';

    public const SALES_ORDER_STATUS_SOUND_IOS = 'sales_order_church_bell.wav';

    public const EMPLOYEE_TASK_SOUND_ANDROID = 'task_sos_alert';

    public const EMPLOYEE_TASK_SOUND_IOS = 'task_sos_alert.mp3';

    public const TASK_SUCCESS_SOUND_ANDROID = 'task_success';

    public const TASK_SUCCESS_SOUND_IOS = 'task_success.wav';

    public const ADMIN_LOGIN_SOUND_ANDROID = 'admin_login_motivate';

    public const ADMIN_LOGIN_SOUND_IOS = 'admin_login_motivate.wav';

    /** Coins + end whistle — res/raw/shiply_delivered.wav */
    public const SHIPLY_DELIVERED_SOUND_ANDROID = 'shiply_delivered';

    public const SHIPLY_DELIVERED_SOUND_IOS = 'shiply_delivered.wav';

    public const SHIPLY_MOTORCYCLE_SOUND_ANDROID = 'shiply_motorcycle';

    public const SHIPLY_MOTORCYCLE_SOUND_IOS = 'shiply_motorcycle.wav';

    public const SHIPLY_STUCK_SOUND_ANDROID = 'shiply_stuck';

    public const SHIPLY_STUCK_SOUND_IOS = 'shiply_stuck.wav';

    public const SHIPLY_RETURNED_SOUND_ANDROID = 'shiply_returned';

    public const SHIPLY_RETURNED_SOUND_IOS = 'shiply_returned.wav';

    /** @var list<string> */
    private const SHIPLY_TRACKING_NOTIFICATION_TYPES = [
        'sales_order_shiply_handover',
        'sales_order_shiply_status',
    ];

    /** @var list<string> */
    private const SHIPLY_DELIVERED_NOTIFICATION_TYPES = [
        'sales_order_shiply_delivered',
    ];

    /** @var list<string> */
    private const SALES_ORDER_STATUS_NOTIFICATION_TYPES = [
        'sales_order_status',
    ];

    /** @var list<string> */
    private const EMPLOYEE_TASK_URGENT_NOTIFICATION_TYPES = [
        'employee_task_assigned',
        'employee_task_rejected',
        'employee_task_scheduled_reminder',
        'employee_daily_tasks',
        'employee_hourly_reminder',
        'employee_operational_reminder',
    ];

    /** @var list<string> */
    private const TASK_SUCCESS_NOTIFICATION_TYPES = [
        'employee_task_approved',
        'employee_task_co_subtask_done',
        'employee_task_co_main_done',
        'employee_task_co_main_completed',
        'employee_daily_tasks_complete',
        'employee_task_completed',
        'employee_task_submitted',
        'employee_subtask_completed',
    ];

    /** @var list<string> */
    private const ADMIN_LOGIN_NOTIFICATION_TYPES = [
        'employee_login',
    ];

    /** @var list<string> */
    private const ADMIN_LOGOUT_NOTIFICATION_TYPES = [
        'employee_logout',
        'employee_logout_pending_tasks',
        'attendance_auto_checkout',
        'attendance_absent_reminder',
        'attendance_overtime_request',
    ];

    protected ?Messaging $messaging = null;

    protected ?string $lastInitError = null;

    protected ?string $resolvedCredentialsPath = null;

    /**
     * @return array{
     *     env_path: string|null,
     *     resolved_path: string|null,
     *     project_id: string|null,
     *     readable: bool,
     *     messaging_ready: bool,
     *     last_error: string|null
     * }
     */
    public function credentialsDiagnostics(): array
    {
        $resolved = $this->resolveCredentialsFile();
        $readable = $resolved !== null && is_readable($resolved);

        return [
            'env_path' => $this->envCredentialsPath(),
            'resolved_path' => $resolved,
            'project_id' => $readable ? $this->readProjectIdFromFile($resolved) : null,
            'readable' => $readable,
            'messaging_ready' => $this->messaging() !== null,
            'last_error' => $this->lastInitError,
        ];
    }

    protected function envCredentialsPath(): ?string
    {
        $path = env('FIREBASE_CREDENTIALS');

        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        return $path !== '' ? $path : null;
    }

    /**
     * Resolve Firebase Admin SDK JSON (absolute path).
     */
    public function resolveCredentialsFile(): ?string
    {
        if ($this->resolvedCredentialsPath !== null) {
            return $this->resolvedCredentialsPath;
        }

        $candidates = [];

        $envPath = $this->envCredentialsPath();
        if ($envPath !== null) {
            $candidates[] = $envPath;
        }

        $candidates[] = storage_path('doctorbike-c4078-firebase-adminsdk-fbsvc-e68cb873ed.json');
        $candidates[] = storage_path('app/firebase-credentials.json');
        $candidates[] = base_path('firebase-credentials.json');

        foreach ($candidates as $candidate) {
            $resolved = $this->resolvePath($candidate);
            if ($resolved !== null) {
                $this->resolvedCredentialsPath = $resolved;

                return $resolved;
            }
        }

        return null;
    }

    protected function resolvePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $try = [$path];
        if (! $this->isAbsolutePath($path)) {
            $try[] = base_path($path);
            $try[] = storage_path($path);
            $try[] = storage_path('app/'.$path);
        }

        foreach ($try as $p) {
            if (is_file($p) && is_readable($p)) {
                $real = realpath($p);

                return $real !== false ? $real : $p;
            }
        }

        return null;
    }

    protected function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    protected function credentialsPath(): ?string
    {
        return $this->resolveCredentialsFile();
    }

    public function credentialsPathForDiagnostics(): ?string
    {
        return $this->resolveCredentialsFile();
    }

    public function serviceAccountProjectId(): ?string
    {
        $path = $this->resolveCredentialsFile();
        if ($path === null) {
            return null;
        }

        return $this->readProjectIdFromFile($path);
    }

    protected function readProjectIdFromFile(string $path): ?string
    {
        try {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return isset($json['project_id']) ? (string) $json['project_id'] : null;
        } catch (Throwable $e) {
            Log::warning('Could not read Firebase service account project_id: '.$e->getMessage());

            return null;
        }
    }

    public function getLastInitError(): ?string
    {
        return $this->lastInitError;
    }

    public function messaging(): ?Messaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        $path = $this->resolveCredentialsFile();
        if ($path === null) {
            $env = $this->envCredentialsPath();
            if ($env !== null) {
                $this->lastInitError = "ملف Firebase غير موجود أو غير قابل للقراءة: {$env} — تحقق من FIREBASE_CREDENTIALS في .env";
            } else {
                $this->lastInitError = 'ملف Firebase غير موجود. اضبط FIREBASE_CREDENTIALS في .env (مسار ملف Admin SDK JSON) '
                    .'أو ضع الملف في: storage/doctorbike-c4078-firebase-adminsdk-fbsvc-e68cb873ed.json';
            }
            Log::warning('Firebase credentials not configured', ['last_error' => $this->lastInitError]);

            return null;
        }

        try {
            $factory = (new Factory)->withServiceAccount($path);
            $this->messaging = $factory->createMessaging();
            $this->lastInitError = null;

            return $this->messaging;
        } catch (Throwable $e) {
            $this->lastInitError = 'Firebase init failed: '.$e->getMessage();
            Log::error($this->lastInitError, ['path' => $path]);

            return null;
        }
    }

    /**
     * Same payload as `php artisan admin:fcm-test` (diagnostics).
     *
     * @return array{ok: bool, message: string, firebase_response?: string, firebase_project_id?: string|null, channel_id: string, token_prefix: string, device_token_id?: int|null, used_latest: bool, credentials_diagnostics?: array}
     */
    /**
     * @return array{ok: bool, message: string, firebase_response?: string, firebase_project_id?: string|null, channel_id: string, token_prefix: string, user_id?: int|null, used_latest: bool, credentials_diagnostics?: array}
     */
    public function sendEmployeeFcmTest(string $fcmToken, bool $usedLatest = false, ?int $userId = null): array
    {
        $data = [
            'type' => 'employee_daily_tasks',
            'notification_id' => '0',
            'related_type' => '',
            'related_id' => '',
            'employee_id' => '',
            'source' => 'employee_fcm_test',
        ];

        $base = [
            'channel_id' => self::ADMIN_CHANNEL_ID,
            'token_prefix' => substr($fcmToken, 0, 20).'…',
            'firebase_project_id' => $this->serviceAccountProjectId(),
            'used_latest' => $usedLatest,
            'user_id' => $userId,
            'credentials_diagnostics' => $this->credentialsDiagnostics(),
        ];

        try {
            $response = $this->sendNotification(
                $fcmToken,
                'DoctorBike Test (موظف)',
                'اختبار إشعار الموظف — FCM',
                $data
            );

            return array_merge($base, [
                'ok' => true,
                'message' => 'تم إرسال FCM للموظف بنجاح.',
                'firebase_response' => $this->formatResponseForLog($response),
                'firebase_project_id' => $this->serviceAccountProjectId(),
            ]);
        } catch (Throwable $e) {
            Log::error('Employee FCM test failed', [
                'token_prefix' => $base['token_prefix'],
                'error' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'ok' => false,
                'message' => 'فشل إرسال FCM: '.$e->getMessage(),
                'credentials_diagnostics' => $this->credentialsDiagnostics(),
            ]);
        }
    }

    public function sendAdminFcmTest(string $fcmToken, bool $usedLatest = false, ?int $deviceTokenId = null): array
    {
        $data = [
            'type' => 'admin_manual',
            'notification_id' => '0',
            'related_type' => '',
            'related_id' => '',
            'employee_id' => '',
            'task_id' => '',
            'check_id' => '',
            'source' => 'admin_fcm_test',
        ];

        $base = [
            'channel_id' => self::ADMIN_CHANNEL_ID,
            'token_prefix' => substr($fcmToken, 0, 20).'…',
            'firebase_project_id' => $this->serviceAccountProjectId(),
            'used_latest' => $usedLatest,
            'device_token_id' => $deviceTokenId,
            'credentials_diagnostics' => $this->credentialsDiagnostics(),
        ];

        try {
            $response = $this->sendNotification(
                $fcmToken,
                'DoctorBike Test',
                'FCM visible notification test',
                $data
            );

            return array_merge($base, [
                'ok' => true,
                'message' => 'تم إرسال FCM بنجاح (DoctorBike Test).',
                'firebase_response' => $this->formatResponseForLog($response),
                'firebase_project_id' => $this->serviceAccountProjectId(),
            ]);
        } catch (Throwable $e) {
            Log::error('Admin FCM test failed', [
                'token_prefix' => $base['token_prefix'],
                'error' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'ok' => false,
                'message' => 'فشل إرسال FCM: '.$e->getMessage(),
                'credentials_diagnostics' => $this->credentialsDiagnostics(),
            ]);
        }
    }

    /**
     * @return mixed Kreait send() return value on success
     */
    public function sendNotification(string $token, string $title, string $body, array $data = []): mixed
    {
        $messaging = $this->messaging();
        if ($messaging === null) {
            throw new \RuntimeException(
                $this->lastInitError ?? __('messages.firebaseInitError')
            );
        }

        return $this->sendToTokenInternal($messaging, $token, $title, $body, $data, true);
    }

    /**
     * @return mixed|null Kreait response on success; null on failure
     */
    public function sendToTokenQuietly(string $token, string $title, string $body, array $data = []): mixed
    {
        $messaging = $this->messaging();
        if ($messaging === null) {
            Log::warning('FCM skipped: messaging not initialized', [
                'last_error' => $this->lastInitError,
            ]);

            return null;
        }

        return $this->sendToTokenInternal($messaging, $token, $title, $body, $data, false);
    }

    /**
     * @return mixed|null Kreait send() return value on success; null on quiet failure
     */
    protected function sendToTokenInternal(
        Messaging $messaging,
        string $token,
        string $title,
        string $body,
        array $data,
        bool $throwOnFailure
    ): mixed {
        $dataWithText = array_merge($data, [
            'title' => $title,
            'body' => $body,
        ]);

        $androidMeta = $this->resolveNotificationDelivery($dataWithText);
        $displayTitle = (string) ($dataWithText['notification_safe_title'] ?? $title);
        $displayBody = (string) ($dataWithText['notification_safe_body'] ?? $body);

        Log::info('FCM send start', [
            'firebase_project_id' => $this->serviceAccountProjectId(),
            'credentials_path' => $this->resolveCredentialsFile(),
            'token_prefix' => substr($token, 0, 12).'…',
            'title' => $title,
            'body' => mb_substr($body, 0, 80),
            'channel_id' => $androidMeta['channel_id'],
            'sound' => $androidMeta['sound'],
            'priority' => $androidMeta['priority'],
            'data_keys' => array_keys($dataWithText),
        ]);

        try {
            $androidNotification = [
                'channel_id' => $androidMeta['channel_id'],
                'icon' => 'ic_notification',
                'color' => '#6B65BD',
                'visibility' => ($dataWithText['notification_lock_screen'] ?? '1') === '1'
                    ? 'public'
                    : 'private',
            ];
            if ($androidMeta['sound'] !== 'silent') {
                $androidNotification['sound'] = $androidMeta['sound'];
            }

            $aps = [
                'alert' => [
                    'title' => $displayTitle,
                    'body' => $displayBody,
                ],
            ];
            if ($androidMeta['ios_sound'] !== 'silent') {
                $aps['sound'] = $androidMeta['ios_sound'];
            }

            $message = CloudMessage::new()
                ->toToken($token)
                ->withNotification(Notification::create($displayTitle, $displayBody))
                ->withData($dataWithText)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => $androidMeta['priority'],
                        'notification' => $androidNotification,
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => $androidMeta['priority'] === 'high' ? '10' : '5',
                        ],
                        'payload' => [
                            'aps' => $aps,
                        ],
                    ])
                );

            $response = $messaging->send($message);

            Log::info('FCM send success', [
                'token_prefix' => substr($token, 0, 12).'…',
                'channel_id' => $androidMeta['channel_id'],
                'firebase_project_id' => $this->serviceAccountProjectId(),
                'response' => $this->formatResponseForLog($response),
                'response_type' => is_object($response) ? get_class($response) : gettype($response),
            ]);

            return $response;
        } catch (FirebaseException $e) {
            Log::error('FCM send failed (FirebaseException)', [
                'token_prefix' => substr($token, 0, 12).'…',
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->handleMessagingFailure($token, $e, $throwOnFailure);
        } catch (Throwable $e) {
            Log::error('FCM send failed (unexpected)', [
                'token_prefix' => substr($token, 0, 12).'…',
                'message' => $e->getMessage(),
            ]);
            if ($throwOnFailure) {
                throw new \RuntimeException(__('messages.firebaseUnknownError').' '.$e->getMessage());
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{channel_id: string, sound: string, ios_sound: string, priority: string}
     */
    protected function resolveNotificationDelivery(array $data): array
    {
        if (! empty($data['notification_channel_id'])) {
            $priority = in_array((string) ($data['notification_priority'] ?? 'normal'), ['high', 'critical'], true)
                ? 'high'
                : 'normal';

            return [
                'channel_id' => (string) $data['notification_channel_id'],
                'sound' => (string) ($data['notification_android_sound'] ?? 'default'),
                'ios_sound' => (string) ($data['notification_ios_sound'] ?? 'default'),
                'priority' => $priority,
            ];
        }

        $type = (string) ($data['type'] ?? '');

        if (in_array($type, self::ADMIN_LOGIN_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::ADMIN_LOGIN_CHANNEL_ID,
                'sound' => self::ADMIN_LOGIN_SOUND_ANDROID,
                'ios_sound' => self::ADMIN_LOGIN_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if (in_array($type, self::ADMIN_LOGOUT_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::ADMIN_ATTENDANCE_CHANNEL_ID,
                'sound' => self::EMPLOYEE_TASK_SOUND_ANDROID,
                'ios_sound' => self::EMPLOYEE_TASK_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if (in_array($type, self::TASK_SUCCESS_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::TASK_SUCCESS_CHANNEL_ID,
                'sound' => self::TASK_SUCCESS_SOUND_ANDROID,
                'ios_sound' => self::TASK_SUCCESS_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if (in_array($type, self::EMPLOYEE_TASK_URGENT_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::EMPLOYEE_TASK_CHANNEL_ID,
                'sound' => self::EMPLOYEE_TASK_SOUND_ANDROID,
                'ios_sound' => self::EMPLOYEE_TASK_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if (in_array($type, self::SHIPLY_DELIVERED_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::SHIPLY_DELIVERED_CHANNEL_ID,
                'sound' => self::SHIPLY_DELIVERED_SOUND_ANDROID,
                'ios_sound' => self::SHIPLY_DELIVERED_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if (in_array($type, self::SALES_ORDER_STATUS_NOTIFICATION_TYPES, true)) {
            return [
                'channel_id' => self::SALES_ORDER_STATUS_CHANNEL_ID,
                'sound' => self::SALES_ORDER_STATUS_SOUND_ANDROID,
                'ios_sound' => self::SALES_ORDER_STATUS_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        $shiplyTracking = $this->resolveShiplyTrackingDelivery($data);
        if ($shiplyTracking !== null) {
            return $shiplyTracking;
        }

        return [
            'channel_id' => self::ADMIN_CHANNEL_ID,
            'sound' => 'default',
            'ios_sound' => 'default',
            'priority' => 'high',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{channel_id: string, sound: string, ios_sound: string, priority: string}|null
     */
    protected function resolveShiplyTrackingDelivery(array $data): ?array
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, self::SHIPLY_TRACKING_NOTIFICATION_TYPES, true)) {
            return null;
        }

        $statusId = (int) ($data['parcel_status_id'] ?? 0);
        $pendingStatus = (int) config('shiply.parcel_status.pending', 5);
        $returnedStatus = (int) config('shiply.parcel_status.returned', 7);

        if ($statusId === $returnedStatus) {
            return [
                'channel_id' => self::SHIPLY_RETURNED_CHANNEL_ID,
                'sound' => self::SHIPLY_RETURNED_SOUND_ANDROID,
                'ios_sound' => self::SHIPLY_RETURNED_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        if ($statusId === $pendingStatus) {
            return [
                'channel_id' => self::SHIPLY_STUCK_CHANNEL_ID,
                'sound' => self::SHIPLY_STUCK_SOUND_ANDROID,
                'ios_sound' => self::SHIPLY_STUCK_SOUND_IOS,
                'priority' => 'high',
            ];
        }

        return [
            'channel_id' => self::SHIPLY_MOTORCYCLE_CHANNEL_ID,
            'sound' => self::SHIPLY_MOTORCYCLE_SOUND_ANDROID,
            'ios_sound' => self::SHIPLY_MOTORCYCLE_SOUND_IOS,
            'priority' => 'high',
        ];
    }

    protected function formatResponseForLog(mixed $response): string
    {
        if ($response === null) {
            return '(null)';
        }
        if (is_scalar($response)) {
            return (string) $response;
        }
        if (is_array($response)) {
            return json_encode($response, JSON_UNESCAPED_UNICODE) ?: '(array)';
        }
        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE) ?: get_debug_type($response);
    }

    protected function handleMessagingFailure(string $token, Throwable $e, bool $throwOnFailure): void
    {
        if ($this->isInvalidTokenError($e)) {
            AdminDeviceToken::query()->where('fcm_token', $token)->delete();
            Log::warning('FCM removed invalid admin device token', [
                'token_prefix' => substr($token, 0, 12).'…',
            ]);
        }

        if ($throwOnFailure) {
            throw new \RuntimeException(__('messages.firebaseSendError').' '.$e->getMessage());
        }
    }

    protected function isInvalidTokenError(Throwable $e): bool
    {
        $class = get_class($e);
        if (str_contains($class, 'NotFound') || str_contains($class, 'InvalidArgument')) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'not found')
            || str_contains($msg, 'requested entity was not found')
            || str_contains($msg, 'invalid registration')
            || str_contains($msg, 'registration-token-not-registered');
    }
}
