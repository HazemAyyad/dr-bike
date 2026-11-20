<?php

namespace App\Http\Controllers;

use App\Models\NormalImageProduct;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\User;
use App\Services\InventoryWebService;
use App\Support\ProductImageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class InventoryWebController extends Controller
{
    public function __construct(private InventoryWebService $portal) {}

    public function loginForm()
    {
        return view('inventory-web.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        $key = 'inventory-login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'محاولات كثيرة؛ انتظر دقيقة ثم حاول مجدداً.'], 429);
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user || $user->is_blocked || ! in_array($user->inventory_web_role, ['admin', 'counter'], true) || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'البريد أو كلمة المرور غير صحيحين.'], 422);
        }
        RateLimiter::clear($key);
        Auth::guard('inventory_web')->login($user);
        $request->session()->regenerate();

        return response()->json(['redirect' => route('inventory-web.index')]);
    }

    public function logout(Request $request)
    {
        Auth::guard('inventory_web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => route('inventory-web.login')]);
    }

    public function index(Request $request)
    {
        return view('inventory-web.index', ['portalUser' => $request->user()]);
    }

    public function sessions()
    {
        return response()->json(['sessions' => DB::table('inventory_count_sessions')->orderByDesc('id')->get(['id', 'name', 'status', 'created_at'])]);
    }

    public function createSession(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160']);
        $id = DB::table('inventory_count_sessions')->insertGetId(['name' => $data['name'], 'status' => 'open', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id]);
    }

    public function products(Request $request)
    {
        $data = $request->validate(['session_id' => 'required|integer', 'q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['pending', 'done', 'all'])], 'scope' => ['nullable', Rule::in(['both', 'showroom', 'warehouse'])]]);
        $session = (int) $data['session_id'];
        $this->portal->session($session);
        $status = $data['status'] ?? 'pending';
        $scope = $data['scope'] ?? 'both';
        $done = function ($query, string $place) use ($session) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_web_counts')->whereColumn('inventory_web_counts.product_id', 'products.id')->where('session_id', $session)->where('place', $place)->whereIn('status', ['submitted', 'approved']));
        };
        $base = Product::with(['viewImages', 'normalImages', 'image3d', 'storeSection'])->when($data['q'] ?? '', fn ($q, $s) => $q->where(fn ($q) => $q->where('nameAr', 'like', '%'.$s.'%')->orWhere('product_code', 'like', '%'.$s.'%')));
        $completed = clone $base;
        $places = $scope === 'both' ? ['showroom', 'warehouse'] : [$scope];
        foreach ($places as $place) {
            $done($completed, $place);
        }
        $total = (clone $base)->count();
        $doneTotal = (clone $completed)->count();
        if ($status === 'done') {
            $base = $completed;
        }
        if ($status === 'pending') {
            $base->whereNotIn('id', (clone $completed)->select('products.id'));
        }
        $page = $base->orderBy('nameAr')->paginate(25);
        $counts = DB::table('inventory_web_counts')->where('session_id', $session)->whereIn('product_id', $page->pluck('id'))->get()->groupBy('product_id');
        $latest = DB::table('inventory_web_counts')->whereIn('product_id', $page->pluck('id'))->whereNotNull('counted_at')->orderByDesc('counted_at')->get(['product_id', 'place', 'counted_at'])->groupBy('product_id');
        $rows = $page->getCollection()->map(function ($p) use ($counts, $latest) {
            $state = [];
            foreach (['showroom', 'warehouse'] as $place) {
                $c = ($counts[$p->id] ?? collect())->firstWhere('place', $place);
                $state[$place] = ['status' => $c?->status ?? 'pending', 'counted_at' => ($latest[$p->id] ?? collect())->firstWhere('place', $place)?->counted_at];
            }

return ['id' => $p->id, 'name' => $p->nameAr, 'code' => $p->product_code, 'quantity' => (int) $p->stock, 'location' => $p->storeSection?->name, 'image' => ProductImageResolver::preferredUrl($p), 'counts' => $state];
        });

        return response()->json(['products' => $rows, 'total' => $total, 'done' => $doneTotal, 'pending' => $total - $doneTotal, 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function product(Request $request, Product $product)
    {
        $request->validate(['session_id' => 'required|integer']);
        $result = $this->portal->product($product->load(InventoryWebService::RELATIONS), (int) $request->session_id, $request->user());
        if ($request->user()->inventory_web_role === 'admin') {
            $result['cost_version'] = (int) DB::table('inventory_adjustments')->where('product_id', $product->id)->max('id');
        }

        return response()->json($result);
    }

    public function saveCount(Request $request, Product $product)
    {
        $data = $request->validate(['session_id' => 'required|integer', 'place' => ['required', Rule::in(['showroom', 'warehouse'])], 'lines' => 'required|array', 'lines.*' => 'nullable|integer|min:0|max:100000000', 'submit' => 'required|boolean', 'version' => 'required|integer|min:0', 'stock_token' => 'required|string|size:64']);
        $count = $this->portal->saveCount($request->user(), $product, (int) $data['session_id'], $data['place'], $data['lines'], (bool) $data['submit'], (int) $data['version'], $data['stock_token']);

        return response()->json(['message' => $data['submit'] ? 'أُرسل الجرد لمراجعة المسؤول.' : 'حُفظت المسودة.', 'count' => ['version' => $count->version, 'status' => $count->status, 'counted_at' => $count->counted_at]]);
    }

    public function approve(Request $request, Product $product)
    {
        $data = $request->validate(['session_id' => 'required|integer', 'request_key' => 'required|uuid', 'reason' => 'required|string|max:120', 'increase_costs' => 'present|array', 'increase_costs.*' => 'nullable|numeric|min:0|max:100000000']);
        $doc = $this->portal->approve($request->user(), $product, $data);

        return response()->json(['message' => 'تم اعتماد الجرد وتوزيع الرصيد.', 'document_id' => $doc->id]);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'from' => ['required', Rule::in(['warehouse', 'showroom'])], 'date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:500', 'lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|integer', 'lines.*.key' => 'required|string|max:30', 'lines.*.quantity' => 'required|integer|min:1|max:100000000', 'lines.*.version' => 'required|integer|min:1']);
        $doc = $this->portal->transfer($request->user(), $data);

        return response()->json(['message' => 'تم اعتماد سند التحويل.', 'document_id' => $doc->id]);
    }

    public function cost(Request $request, Product $product)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'key' => 'required|string|max:30', 'unit_cost' => 'required|numeric|gt:0|max:100000000', 'reason' => 'required|string|max:120', 'cost_version' => 'required|integer|min:0', 'expected_quantity' => 'required|integer']);
        $doc = $this->portal->cost($request->user(), $product, $data);

        return response()->json(['message' => 'تم تسجيل تعديل التكلفة مع السبب.', 'document_id' => $doc->id]);
    }

    public function updateProduct(Request $request, Product $product)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'retail' => 'required|numeric|min:0|max:100000000', 'wholesale' => 'required|numeric|min:0|max:100000000', 'expected_updated_at' => 'required|string', 'rows' => 'present|array', 'rows.*.key' => 'required|string', 'rows.*.retail' => 'required|numeric|min:0|max:100000000', 'rows.*.wholesale' => 'required|numeric|min:0|max:100000000']);
        DB::transaction(function () use ($request, $product, $data) {
            $p = Product::lockForUpdate()->findOrFail($product->id);
            abort_unless($p->updated_at->toISOString() === $data['expected_updated_at'], 409, 'تغير المنتج؛ حدّثه قبل الحفظ.');
            $before = ['name' => $p->nameAr, 'retail' => $p->normailPrice, 'wholesale' => $p->wholesalePrice];
            $p->forceFill(['nameAr' => $data['name'], 'normailPrice' => $data['retail'], 'wholesalePrice' => $data['wholesale']])->save();
            $identities = collect($this->portal->identities($p));
            foreach ($data['rows'] as $row) {
                $identity = $identities->firstWhere('key', $row['key']);
                abort_unless($identity, 422, 'لون غير صالح.');
                if ($identity['variant_id']) {
                    SizeColor::whereKey($identity['variant_id'])->update(['normailPrice' => $row['retail'], 'wholesalePrice' => $row['wholesale']]);
                }
            }$this->portal->event($request->user(), 'product_prices_updated', (int) $p->id, ['before' => $before, 'after' => $data]);
        });

        return response()->json(['message' => 'حُفظت بيانات المنتج والأسعار.']);
    }

    public function addVariant(Request $request, Product $product)
    {
        $data = $request->validate(['size' => 'required|string|max:100', 'color' => 'required|string|max:100', 'retail' => 'required|numeric|min:0', 'wholesale' => 'required|numeric|min:0']);
        $id = DB::transaction(function () use ($request, $product, $data) {
            $p = Product::lockForUpdate()->findOrFail($product->id);
            $p->load('sizes.colorSizes');
            abort_unless($p->sizes->flatMap->colorSizes->isNotEmpty() || (int) $p->stock === 0, 422, 'توزيع رصيد منتج بسيط على ألوان يحتاج جرداً مستقلاً أولاً.');
            $size = $p->sizes->firstWhere('size', $data['size']);
            if (! $size) {
                $size = new Size(['size' => $data['size'], 'itemId' => $p->id]);
                $size->setIncrementing(true);
                $size->save();
            }abort_if(SizeColor::where('sizeId', $size->id)->where('colorAr', $data['color'])->exists(), 422, 'اللون والمقاس موجودان.');
            $v = new SizeColor(['sizeId' => $size->id, 'colorAr' => $data['color'], 'normailPrice' => $data['retail'], 'wholesalePrice' => $data['wholesale'], 'stock' => 0]);
            $v->setIncrementing(true);
            $v->save();
            $p->touch();
            $this->portal->event($request->user(), 'variant_added', (int) $p->id, ['variant_id' => $v->id, 'color' => $data['color'], 'size' => $data['size']]);

            return $v->id;
        });

        return response()->json(['message' => 'أضيف اللون بصفر كمية؛ أدخل عدده عن طريق الجرد.', 'id' => $id]);
    }

    public function variant(Request $request, Product $product, int $variant)
    {
        $data = $request->validate(['color' => 'required|string|max:100', 'size' => 'required|string|max:100', 'active' => 'required|boolean']);
        DB::transaction(function () use ($request, $product, $variant, $data) {
            $p = Product::lockForUpdate()->findOrFail($product->id);
            $v = SizeColor::whereKey($variant)->whereHas('size', fn ($q) => $q->where('itemId', $p->id))->lockForUpdate()->firstOrFail();
            if (! $data['active']) {
                abort_if((int) $v->stock !== 0, 422, 'لا يمكن إيقاف لون له رصيد.');
                $balance = DB::table('inventory_location_balances')->where('size_color_id', $variant)->first();
                abort_if($balance && ($balance->showroom !== 0 || $balance->warehouse !== 0), 422, 'راجع رصيد المكانين قبل إيقاف اللون.');
            }abort_if(SizeColor::where('id', '!=', $variant)->whereHas('size', fn ($q) => $q->where('itemId', $p->id)->where('size', $data['size']))->where('colorAr', $data['color'])->exists(), 422, 'هذا اللون والمقاس موجودان.');
            $size = Size::where('itemId', $p->id)->where('size', $data['size'])->first();
            if (! $size) {
                $size = new Size(['size' => $data['size'], 'itemId' => $p->id]);
                $size->setIncrementing(true);
                $size->save();
            }$v->forceFill(['colorAr' => $data['color'], 'sizeId' => $size->id])->save();
            DB::table('inventory_web_variant_states')->updateOrInsert(['size_color_id' => $variant], ['active' => $data['active'], 'created_at' => now(), 'updated_at' => now()]);
            $p->touch();
            $this->portal->event($request->user(), 'variant_updated', (int) $p->id, ['variant_id' => $variant] + $data);
        });

        return response()->json(['message' => 'حُفظ اللون والمقاس مع بقاء تاريخه.']);
    }

    public function image(Request $request, Product $product)
    {
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', 'key' => 'required|string|max:30']);
        $rows = collect($this->portal->identities($product));
        abort_unless($data['key'] === 'product' || $rows->firstWhere('key', $data['key']), 422, 'هدف الصورة غير صالح.');
        $path = $request->file('image')->store('inventory-web/'.$product->id, 'public');
        abort_unless($path, 500, 'تعذر حفظ الصورة.');
        DB::transaction(function () use ($request, $product, $data, $path) {
            $p = Product::lockForUpdate()->findOrFail($product->id);
            if ($data['key'] === 'product') {
                $p->forceFill(['inventory_web_image' => 'storage/'.$path])->save();
                $image = new NormalImageProduct(['itemId' => $p->id, 'imageUrl' => 'storage/'.$path]);
                $image->setIncrementing(true);
                $image->save();
            } else {
                $v = SizeColor::whereKey((int) $data['key'])->whereHas('size', fn ($q) => $q->where('itemId', $p->id))->firstOrFail();
                $v->image_url = 'storage/'.$path;
                $v->save();
            }$p->touch();
            $this->portal->event($request->user(), 'image_updated', (int) $p->id, ['key' => $data['key'], 'path' => $path]);
        });

        return response()->json(['message' => 'حُفظت الصورة الجديدة.']);
    }

    public function documents(Request $request)
    {
        $docs = DB::table('inventory_web_documents')->leftJoin('users', 'users.id', '=', 'inventory_web_documents.created_by')->orderByDesc('inventory_web_documents.id')->limit(50)->get(['inventory_web_documents.*', 'users.name']);

        return response()->json(['documents' => $docs->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'date' => $d->document_date, 'reason' => $d->reason, 'from' => $d->from_place, 'to' => $d->to_place, 'name' => $d->name, 'lines' => $this->portal->decode($d->lines), 'created_at' => $d->created_at])]);
    }

    public function users()
    {
        return response()->json(['users' => User::whereNotNull('inventory_web_role')->get(['id', 'name', 'email', 'inventory_web_role', 'is_blocked'])]);
    }

    public function saveUser(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:100', 'email' => ['required', 'email', Rule::unique('users')->ignore($request->id)], 'password' => [$request->id ? 'nullable' : 'required', 'string', 'min:12', 'max:255'], 'role' => ['required', Rule::in(['admin', 'counter'])], 'blocked' => 'required|boolean']);
        DB::transaction(function () use ($request, $data) {
            $admins = User::where('inventory_web_role', 'admin')->where('is_blocked', false)->orderBy('id')->lockForUpdate()->get();
            $user = isset($data['id']) ? User::whereNotNull('inventory_web_role')->findOrFail($data['id']) : new User;
            abort_if($user->id === $request->user()->id && ($data['blocked'] || $data['role'] !== 'admin'), 422, 'لا يمكن إيقاف حسابك أو إزالة صلاحيتك بنفسك.');
            abort_if($user->inventory_web_role === 'admin' && $admins->count() <= 1 && ($data['blocked'] || $data['role'] !== 'admin'), 422, 'يجب إبقاء مسؤول نشط واحد على الأقل.');
            $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'inventory_web_role' => $data['role'], 'is_blocked' => $data['blocked']]);
            if (! $user->exists) {
                $user->type = 'inventory';
            }if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                $user->remember_token = null;
            }$user->save();
            $this->portal->event($request->user(),'account_updated',null,['account' => $user->id, 'role' => $data['role'], 'blocked' => $data['blocked']]);
        });

        return response()->json(['message' => 'حُفظ الحساب.']);
    }
}
