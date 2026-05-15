<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebaseService
{
    /** Must match Flutter [kDrBikeAdminNotificationChannelId] and AndroidManifest. */
    public const ADMIN_CHANNEL_ID = 'dr_bike_admin_notifications';

    protected ?Messaging $messaging = null;

    protected function credentialsPath(): ?string
    {
        $path = env('FIREBASE_CREDENTIALS');
        if ($path && is_string($path) && $path !== '') {
            return $path;
        }

        $legacy = storage_path('doctorbike-c4078-firebase-adminsdk-fbsvc-e68cb873ed.json');

        return is_file($legacy) ? $legacy : null;
    }

    public function credentialsPathForDiagnostics(): ?string
    {
        $path = $this->credentialsPath();
        if ($path === null) {
            return null;
        }

        return is_readable($path) ? $path : null;
    }

    public function serviceAccountProjectId(): ?string
    {
        $path = $this->credentialsPathForDiagnostics();
        if ($path === null) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return isset($json['project_id']) ? (string) $json['project_id'] : null;
        } catch (Throwable $e) {
            Log::warning('Could not read Firebase service account project_id: '.$e->getMessage());

            return null;
        }
    }

    public function messaging(): ?Messaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        $path = $this->credentialsPath();
        if ($path === null || ! is_readable($path)) {
            Log::warning('Firebase credentials not configured or unreadable. Set FIREBASE_CREDENTIALS in .env');

            return null;
        }

        try {
            $factory = (new Factory)->withServiceAccount($path);
            $this->messaging = $factory->createMessaging();

            return $this->messaging;
        } catch (Throwable $e) {
            Log::error('Firebase initialization failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, string>  $data  FCM data keys/values must be strings
     */
    /**
     * @return mixed FCM HTTP response (message name / array) from Kreait
     */
    /**
     * Same payload as `php artisan admin:fcm-test` (diagnostics).
     *
     * @return array{ok: bool, message: string, firebase_response?: string, firebase_project_id?: string|null, channel_id: string, token_prefix: string, device_token_id?: int|null, used_latest: bool}
     */
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
            ]);
        } catch (Throwable $e) {
            Log::error('Admin FCM test failed', [
                'token_prefix' => $base['token_prefix'],
                'error' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'ok' => false,
                'message' => 'فشل إرسال FCM: '.$e->getMessage(),
            ]);
        }
    }

    public function sendNotification(string $token, string $title, string $body, array $data = []): mixed
    {
        $messaging = $this->messaging();
        if ($messaging === null) {
            throw new \RuntimeException(__('messages.firebaseInitError'));
        }

        return $this->sendToTokenInternal($messaging, $token, $title, $body, $data, true);
    }

    /**
     * Send without throwing; used by admin broadcast. Removes invalid tokens from admin_device_tokens.
     *
     * @param  array<string, string>  $data
     */
    /**
     * @return mixed|null Kreait response on success; null on failure
     */
    public function sendToTokenQuietly(string $token, string $title, string $body, array $data = []): mixed
    {
        $messaging = $this->messaging();
        if ($messaging === null) {
            Log::warning('FCM skipped: messaging not initialized');

            return null;
        }

        return $this->sendToTokenInternal($messaging, $token, $title, $body, $data, false);
    }

    /**
     * @param  array<string, string>  $data
     */
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

        Log::info('FCM send start', [
            'firebase_project_id' => $this->serviceAccountProjectId(),
            'token_prefix' => substr($token, 0, 12).'…',
            'title' => $title,
            'body' => mb_substr($body, 0, 80),
            'channel_id' => self::ADMIN_CHANNEL_ID,
            'data_keys' => array_keys($dataWithText),
        ]);

        try {
            $message = CloudMessage::new()
                ->withToken($token)
                ->withNotification(Notification::create($title, $body))
                ->withData($dataWithText)
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => self::ADMIN_CHANNEL_ID,
                            'sound' => 'default',
                            'icon' => 'ic_notification',
                            'color' => '#6B65BD',
                        ],
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body,
                                ],
                                'sound' => 'default',
                            ],
                        ],
                    ])
                );

            $response = $messaging->send($message);

            Log::info('FCM send success', [
                'token_prefix' => substr($token, 0, 12).'…',
                'channel_id' => self::ADMIN_CHANNEL_ID,
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
                throw new \RuntimeException(__('messages.firebaseUnknownError'));
            }
        }

        return null;
    }

    /**
     * @param  mixed  $response
     */
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
            throw new \RuntimeException(__('messages.firebaseSendError'));
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
