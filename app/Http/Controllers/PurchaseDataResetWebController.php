<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\User;
use App\Services\PurchaseDataResetService;
use App\Services\PurchaseInvoicePurgeService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseDataResetWebController extends Controller
{
    public function index(Request $request, PurchaseDataResetService $reset): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $request->session()->forget('purchase_reset_preview');
        $missing = $reset->missingTables();
        $preview = null;
        $invoices = null;
        $search = trim((string) ($data['search'] ?? ''));
        if ($missing === []) {
            $preview = $reset->preview();
            $preview['token'] = Str::random(64);
            $request->session()->put('purchase_reset_preview', [
                'token' => $preview['token'],
                'fingerprint' => $preview['fingerprint'],
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]);
            $invoices = Bill::query()->with(['seller:id,name', 'customer:id,name'])->withCount('items')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $reference = preg_replace('/^PUR-/i', '', $search);
                        if (ctype_digit($reference)) {
                            $query->where('id', $reference);
                        } else {
                            $query->whereHas('seller', fn ($party) => $party->where('name', 'like', '%'.$search.'%'))
                                ->orWhereHas('customer', fn ($party) => $party->where('name', 'like', '%'.$search.'%'));
                        }
                    });
                })->latest('id')->paginate(20)->withQueryString();
        }

        return view('purchase-data-reset', compact('preview', 'missing', 'invoices', 'search'));
    }

    public function destroy(Request $request, PurchaseDataResetService $reset): RedirectResponse
    {
        $this->ensureSecurityCenterSession($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);
        $preview = $request->session()->get('purchase_reset_preview');
        if (! is_array($preview) || ($preview['expires_at'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($preview['token'] ?? ''), $data['preview_token'])) {
            throw ValidationException::withMessages(['preview' => 'انتهت المعاينة أو تغيرت الصفحة. حدّث المعاينة قبل التصفير.']);
        }

        $actor = $this->securityCenterActor($request);
        $reason = $this->auditReason($request, $data['reason']);
        $result = $this->execute(fn () => $reset->resetFromSecurityCenter($preview['fingerprint'], $actor, $reason));
        $request->session()->forget('purchase_reset_preview');

        return redirect()->route('security-center.purchase-reset.index')->with('purchase_reset_result', [
            'counts' => $result['counts'],
            'backup_reference' => $result['backup_reference'],
        ]);
    }

    public function destroyInvoice(Request $request, Bill $bill, PurchaseInvoicePurgeService $purge, PurchaseDataResetService $reset): RedirectResponse
    {
        $this->ensureSecurityCenterSession($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'bill_version' => ['required', 'string', 'size:64'],
        ]);
        if ($reset->missingTables() !== []) {
            throw ValidationException::withMessages(['schema' => 'جداول المشتريات والتدقيق غير مكتملة. طبّق ترحيلات السيرفر أولًا.']);
        }
        $actor = $this->securityCenterActor($request);
        $reason = $this->auditReason($request, $data['reason']);
        $result = $this->execute(fn () => $purge->purge(
            $bill, $actor, $reason, expectedVersion: $data['bill_version'],
        ));
        $request->session()->forget('purchase_reset_preview');

        return redirect()->route('security-center.purchase-reset.index')->with('purchase_invoice_deleted', [
            'bill_id' => $result['bill_id'],
            'backup_reference' => $result['backup_reference'],
        ]);
    }

    private function ensureSecurityCenterSession(Request $request): void
    {
        abort_unless($request->session()->get('security_center_authenticated', false), 403);
    }

    private function securityCenterActor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && ! $actor->is_blocked ? $actor : null;
    }

    private function auditReason(Request $request, string $reason): string
    {
        return trim($reason).' | حذف من مركز الأمان. IP: '.(string) $request->ip();
    }

    private function execute(Closure $action): array
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['reset' => 'تعذر إكمال الطلب. حدّث المعاينة للتحقق من الحالة قبل المحاولة مجددًا، وراجع سجل السيرفر.']);
        }
    }
}
