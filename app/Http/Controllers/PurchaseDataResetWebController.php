<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PurchaseDataResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseDataResetWebController extends Controller
{
    public function index(Request $request, PurchaseDataResetService $reset): View
    {
        $request->session()->forget('purchase_reset_preview');
        $missing = $reset->missingTables();
        $preview = null;
        if ($missing === []) {
            $preview = $reset->preview();
            $preview['token'] = Str::random(64);
            $request->session()->put('purchase_reset_preview', [
                'token' => $preview['token'],
                'fingerprint' => $preview['fingerprint'],
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]);
        }

        return view('purchase-data-reset', compact('preview', 'missing'));
    }

    public function destroy(Request $request, PurchaseDataResetService $reset): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'confirmed' => ['required', 'accepted'],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);
        $preview = $request->session()->get('purchase_reset_preview');
        if (! is_array($preview) || ($preview['expires_at'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($preview['token'] ?? ''), $data['preview_token'])) {
            throw ValidationException::withMessages(['preview' => 'انتهت المعاينة أو تغيرت الصفحة. حدّث المعاينة قبل التصفير.']);
        }

        $actor = User::query()->where('email', $data['email'])->where('type', 'admin')->first();
        if (! $actor || $actor->is_blocked || ! Hash::check($data['password'], $actor->password)) {
            throw ValidationException::withMessages(['email' => 'بيانات حساب الأدمن غير صحيحة أو الحساب محظور.']);
        }

        // Existing accounting services use auth()->id() for their audit entries.
        $previousUser = Auth::user();
        Auth::setUser($actor);
        try {
            $result = $reset->reset($preview['fingerprint'], $actor, trim($data['reason']));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['reset' => 'تعذر إكمال الطلب. حدّث المعاينة للتحقق من الحالة قبل المحاولة مجددًا، وراجع سجل السيرفر.']);
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
        $request->session()->forget('purchase_reset_preview');

        return redirect()->route('security-center.purchase-reset.index')->with('purchase_reset_result', [
            'counts' => $result['counts'],
            'backup_reference' => $result['backup_reference'],
        ]);
    }
}
