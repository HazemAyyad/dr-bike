<?php

namespace App\Console\Commands;

use App\Models\AdminDeviceToken;
use App\Services\FirebaseService;
use Illuminate\Console\Command;

class AdminFcmTest extends Command
{
    protected $signature = 'admin:fcm-test {token? : FCM device token; defaults to latest admin_device_tokens row}';

    protected $description = 'Send a test FCM push to an admin device (diagnostics)';

    public function handle(FirebaseService $firebaseService): int
    {
        $token = $this->argument('token');

        $usedLatest = false;
        $deviceTokenId = null;

        if ($token === null || $token === '') {
            $row = AdminDeviceToken::query()->orderByDesc('id')->first();
            if ($row === null) {
                $this->error('No admin_device_tokens rows found. Log in as admin on the app first.');

                return self::FAILURE;
            }
            $token = $row->fcm_token;
            $usedLatest = true;
            $deviceTokenId = $row->id;
            $this->line("Using latest admin_device_tokens id={$row->id} user_id={$row->user_id}");
        }

        $credentialsPath = $firebaseService->credentialsPathForDiagnostics();

        $this->info('Laravel FIREBASE_CREDENTIALS project_id: '.($firebaseService->serviceAccountProjectId() ?? '(unreadable)'));
        if ($credentialsPath !== null) {
            $this->line("Credentials file: {$credentialsPath}");
        } else {
            $this->warn('Firebase credentials file not found. Set FIREBASE_CREDENTIALS in .env');
        }

        $this->line('Channel id: '.FirebaseService::ADMIN_CHANNEL_ID);

        $result = $firebaseService->sendAdminFcmTest($token, $usedLatest, $deviceTokenId);

        if ($result['ok']) {
            $this->info($result['message']);
            $this->line('Firebase response: '.($result['firebase_response'] ?? '(none)'));

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
