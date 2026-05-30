<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SmsTestWebController extends Controller
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

        return view('sms-test', [
            'token' => $request->query('token', ''),
            'tokenRequired' => $this->expectedToken() !== '',
            'result' => session('result'),
            'twilio' => $this->twilioStatus(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $this->authorizeRequest($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:1500'],
        ]);

        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $from = config('services.twilio.from');

        if (! $accountSid || ! $authToken || ! $from) {
            return redirect()
                ->route('test.sms', $this->authQueryParams($request))
                ->withInput()
                ->with('result', [
                    'ok' => false,
                    'message' => 'إعدادات Twilio ناقصة. تأكد من TWILIO_ACCOUNT_SID و TWILIO_AUTH_TOKEN و TWILIO_FROM.',
                ]);
        }

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                    'From' => $from,
                    'To' => trim($validated['phone']),
                    'Body' => $validated['message'],
                ]);

            $json = $response->json();
            $sid = is_array($json) ? ($json['sid'] ?? null) : null;
            $status = is_array($json) ? ($json['status'] ?? null) : null;
            $errorMessage = is_array($json) ? ($json['message'] ?? null) : null;

            return redirect()
                ->route('test.sms', $this->authQueryParams($request))
                ->withInput(['phone' => $validated['phone']])
                ->with('result', [
                    'ok' => $response->successful(),
                    'message' => $response->successful()
                        ? 'تم قبول الرسالة من Twilio. الوصول النهائي يعتمد على حالة الرقم والشبكة.'
                        : 'فشل إرسال الرسالة من Twilio.',
                    'http_status' => $response->status(),
                    'twilio_sid' => $sid,
                    'twilio_status' => $status,
                    'twilio_error' => $errorMessage,
                    'response' => Str::limit($response->body(), 2000, ''),
                ]);
        } catch (\Throwable $e) {
            return redirect()
                ->route('test.sms', $this->authQueryParams($request))
                ->withInput()
                ->with('result', [
                    'ok' => false,
                    'message' => 'تعذر الاتصال بـ Twilio.',
                    'twilio_error' => $e->getMessage(),
                ]);
        }
    }

    protected function authQueryParams(Request $request): array
    {
        $webToken = (string) $request->query('token', $request->input('token', ''));

        return $webToken !== '' ? ['token' => $webToken] : [];
    }

    protected function twilioStatus(): array
    {
        return [
            'account_sid' => config('services.twilio.account_sid') ? 'موجود' : 'ناقص',
            'auth_token' => config('services.twilio.auth_token') ? 'موجود' : 'ناقص',
            'from' => config('services.twilio.from') ?: 'ناقص',
        ];
    }
}
