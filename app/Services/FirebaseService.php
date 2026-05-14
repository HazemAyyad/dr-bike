<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebaseService
{
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
    public function sendNotification(string $token, string $title, string $body, array $data = []): bool
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
    public function sendToTokenQuietly(string $token, string $title, string $body, array $data = []): bool
    {
        $messaging = $this->messaging();
        if ($messaging === null) {
            Log::warning('FCM skipped: messaging not initialized');

            return false;
        }

        return $this->sendToTokenInternal($messaging, $token, $title, $body, $data, false);
    }

    /**
     * @param  array<string, string>  $data
     */
    protected function sendToTokenInternal(
        Messaging $messaging,
        string $token,
        string $title,
        string $body,
        array $data,
        bool $throwOnFailure
    ): bool {
        try {
            $message = CloudMessage::fromArray([
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ]);
            $messaging->send($message);

            return true;
        } catch (FirebaseException $e) {
            $this->handleMessagingFailure($token, $e, $throwOnFailure);
        } catch (Throwable $e) {
            Log::error('Unexpected error while sending Firebase notification: '.$e->getMessage());
            if ($throwOnFailure) {
                throw new \RuntimeException(__('messages.firebaseUnknownError'));
            }
        }

        return false;
    }

    protected function handleMessagingFailure(string $token, Throwable $e, bool $throwOnFailure): void
    {
        Log::error('Firebase notification failed: '.$e->getMessage());

        if ($this->isInvalidTokenError($e)) {
            AdminDeviceToken::query()->where('fcm_token', $token)->delete();
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
