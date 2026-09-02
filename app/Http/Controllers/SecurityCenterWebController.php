<?php

namespace App\Http\Controllers;

use App\Models\SecurityAccessVisitor;
use App\Models\SecurityIpBlock;
use App\Services\IpGeolocationService;
use App\Services\SecurityAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class SecurityCenterWebController extends Controller
{
    public function loginForm(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('security_center_authenticated', false)) {
            return redirect()->route('security-center.index');
        }

        return view('security-center-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
        ]);
        $expected = (string) config('security_center.web_token', '');

        if ($expected === '') {
            return back()->withErrors([
                'token' => 'لم يتم ضبط SECURITY_CENTER_WEB_TOKEN على السيرفر.',
            ]);
        }

        if (! hash_equals($expected, (string) $data['token'])) {
            return back()->withErrors(['token' => 'رمز الدخول غير صحيح.']);
        }

        $request->session()->regenerate();
        $request->session()->put('security_center_authenticated', true);

        return redirect()->route('security-center.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('security_center_authenticated');
        $request->session()->regenerateToken();

        return redirect()->route('security-center.login');
    }

    public function index(Request $request, SecurityAccessService $security): View
    {
        $search = trim((string) $request->query('search', ''));
        $filter = (string) $request->query('filter', 'all');
        $tablesReady = $security->tablesReady();

        if (! $tablesReady) {
            return view('security-center', [
                'tablesReady' => false,
                'visitors' => new LengthAwarePaginator([], 0, 40, 1, [
                    'path' => $request->url(),
                ]),
                'blocks' => collect(),
                'search' => $search,
                'filter' => $filter,
                'currentIp' => (string) $request->ip(),
                'stats' => ['online' => 0, 'today' => 0, 'errors' => 0, 'blocked' => 0],
            ]);
        }

        $visitors = SecurityAccessVisitor::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('ip_address', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%")
                        ->orWhere('user_type', 'like', "%{$search}%")
                        ->orWhere('device_type', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('isp', 'like', "%{$search}%")
                        ->orWhere('last_route', 'like', "%{$search}%");
                });
            })
            ->when($filter === 'online', fn ($query) => $query->where('last_seen_at', '>=', now()->subMinutes(5)))
            ->when($filter === 'errors', fn ($query) => $query->where('last_status', '>=', 400))
            ->orderByDesc('last_seen_at')
            ->paginate(40)
            ->withQueryString();

        $blocks = SecurityIpBlock::query()
            ->orderByDesc('active')
            ->orderByDesc('blocked_at')
            ->limit(100)
            ->get();

        return view('security-center', [
            'tablesReady' => true,
            'visitors' => $visitors,
            'blocks' => $blocks,
            'search' => $search,
            'filter' => $filter,
            'currentIp' => (string) $request->ip(),
            'stats' => [
                'online' => SecurityAccessVisitor::query()->where('last_seen_at', '>=', now()->subMinutes(5))->count(),
                'today' => SecurityAccessVisitor::query()->where('last_seen_at', '>=', now()->subDay())->count(),
                'errors' => SecurityAccessVisitor::query()->where('last_status', '>=', 400)->count(),
                'blocked' => SecurityIpBlock::query()
                    ->where('active', true)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
            ],
        ]);
    }

    public function block(Request $request, SecurityAccessService $security): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ]);
        $ip = (string) $data['ip_address'];

        if ($ip === (string) $request->ip()) {
            return back()->withErrors(['ip_address' => 'لا يمكن حظر عنوانك الحالي من مركز الأمان.']);
        }

        SecurityIpBlock::query()->updateOrCreate(
            ['ip_address' => $ip],
            [
                'reason' => $data['reason'] ?: 'حظر يدوي من مركز الأمان',
                'active' => true,
                'blocked_at' => now(),
                'expires_at' => isset($data['duration_minutes'])
                    ? now()->addMinutes((int) $data['duration_minutes'])
                    : null,
                'created_by_ip' => (string) $request->ip(),
            ]
        );
        $security->forgetBlock($ip);

        return back()->with('flash', "تم حظر {$ip} بنجاح.");
    }

    public function unblock(SecurityIpBlock $block, SecurityAccessService $security): RedirectResponse
    {
        $block->update(['active' => false]);
        $security->forgetBlock($block->ip_address);

        return back()->with('flash', "تم إلغاء حظر {$block->ip_address}.");
    }

    public function refreshGeolocation(IpGeolocationService $geolocation): RedirectResponse
    {
        $result = $geolocation->refreshMissing();

        return back()->with(
            'flash',
            "تم فحص {$result['requested']} عنوان: نجح {$result['updated']} وفشل {$result['failed']}."
        );
    }
}
