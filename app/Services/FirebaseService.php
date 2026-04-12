<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Exception\FirebaseException;
use Illuminate\Support\Facades\Log;
use Exception;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $serviceAccountPath = storage_path('doctorbike-c4078-firebase-adminsdk-fbsvc-e68cb873ed.json');
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $this->messaging = $factory->createMessaging();
        } catch (Exception $e) {
            Log::error('Firebase initialization failed: ' . $e->getMessage());
            throw new Exception(__('messages.firebaseInitError'));
        }
    }

    public function sendNotification($token, $title, $body, $data = [])
    {
        try {
            $message = CloudMessage::fromArray([
                
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
           
            ]);

            $this->messaging->send($message);



            return true;
        } catch (FirebaseException $e) {
            Log::error('Firebase notification failed: ' . $e->getMessage());
            throw new Exception(__('messages.firebaseSendError'));
        } catch (Exception $e) {
            Log::error('Unexpected error while sending Firebase notification: ' . $e->getMessage());
            throw new Exception(__('messages.firebaseUnknownError'));
        }
    }
}
