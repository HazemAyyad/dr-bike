<?php

namespace App\Console\Commands;

use App\Models\AdminDeviceToken;
use App\Services\FirebaseService;
use Illuminate\Console\Command;
use Throwable;

class AdminFcmTest extends Command
{
    protected $signature = 'admin:fcm-test {token? : FCM device token; defaults to latest admin_device_tokens row}';

    protected $description = 'Send a test FCM push to an admin device (diagnostics)';

    public function handle(FirebaseService $firebaseService): int
    {
        $token = $this->argument('token');

        if ($token === null || $token === '') {
            $row = AdminDeviceToken::query()->orderByDesc('id')->first();
            if ($row === null) {
                $this->error('No admin_device_tokens rows found. Log in as admin on the app first.');

                return self::FAILURE;
            }
            $token = $row->fcm_token;
            $this->line("Using latest admin_device_tokens id={$row->id} user_id={$row->user_id}");
        }

        $projectId = $firebaseService->serviceAccountProjectId();
        $credentialsPath = $firebaseService->credentialsPathForDiagnostics();

        $this->info('Laravel FIREBASE_CREDENTIALS project_id: '.($projectId ?? '(unreadable)'));
        if ($credentialsPath !== null) {
            $this->line("Credentials file: {$credentialsPath}");
        } else {
            $this->warn('Firebase credentials file not found. Set FIREBASE_CREDENTIALS in .env');
        }

        $this->line('Target token prefix: '.substr($token, 0, 20).'…');
        $this->line('Channel id: '.FirebaseService::ADMIN_CHANNEL_ID);

        $data = [
            'type' => 'admin_manual',
            'notification_id' => '0',
            'related_type' => '',
            'related_id' => '',
            'employee_id' => '',
            'task_id' => '',
            'check_id' => '',
            'source' => 'artisan_admin_fcm_test',
        ];

        try {
            $result = $firebaseService->sendNotification(
                $token,
                'DoctorBike Test',
                'FCM visible notification test',
                $data
            );

            $this->info('FCM send OK');
            $this->line('Firebase response: '.$this->formatSendResponse($result));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FCM send FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  mixed  $response
     */
    protected function formatSendResponse($response): string
    {
        if ($response === null) {
            return '(null)';
        }
        if (is_scalar($response)) {
            return (string) $response;
        }
        if (is_array($response)) {
            return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '(array)';
        }
        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: get_debug_type($response);
    }
}
