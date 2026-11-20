<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Support\ProductImageResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryWebService
{
    public const RELATIONS = ['sizes.colorSizes', 'viewImages', 'normalImages', 'image3d', 'storeSection'];

    public function __construct(private InventoryLocationService $locations, private ProductStockService $stock, private InventoryAdjustmentService $adjustments, private InventoryCostingService $costing) {}

    public function admin(User $user): void
    {
        abort_unless($user->inventory_web_role === 'admin', 403, 'هذه العملية للمسؤول فقط.');
    }

    public function decode(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    public function event(User $user, string $action, ?int $product, array $details): void
    {
        DB::table('inventory_web_events')->insert(['user_id' => $user->id, 'product_id' => $product, 'action' => $action, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function session(int $id): object
    {
        $session = DB::table('inventory_count_sessions')->find($id);
        abort_unless($session, 404);

        return $session;
    }

    public function openSession(int $id): object
    {
        $session = $this->session($id);
        abort_unless($session->status === 'open', 422, 'جلسة الجرد مغلقة.');

        return $session;
    }

    public function identities(Product $product, bool $withCost = false): array
    {
        $product->loadMissing(self::RELATIONS);
        $balances = DB::table('inventory_location_balances')->where('product_id', $product->id)->get()->keyBy('identity_key');
        $states = DB::table('inventory_web_variant_states')->get()->keyBy('size_color_id');
        $rows = [];
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $variant) {
                $rows[] = ['key' => (string) $variant->id, 'variant_id' => (int) $variant->id, 'size_id' => (int) $size->id, 'size' => (string) $size->size, 'color' => (string) $variant->colorAr,
                    'quantity' => (int) $variant->stock, 'retail' => (float) $variant->normailPrice, 'wholesale' => (float) $variant->wholesalePrice, 'image' => $variant->image_url ?: ProductImageResolver::preferredUrl($product), 'active' => (bool) ($states[$variant->id]->active ?? true)];
            }
        }
        if ($rows === []) {
            $rows[] = ['key' => 'main', 'variant_id' => null, 'size_id' => null, 'size' => '—', 'color' => 'المنتج نفسه', 'quantity' => (int) $product->stock, 'retail' => (float) $product->normailPrice, 'wholesale' => (float) $product->wholesalePrice, 'image' => ProductImageResolver::preferredUrl($product), 'active' => true];
        }
        foreach ($rows as &$row) {
            $balance = $balances[$this->locations->key((int) $product->id, $row['variant_id'])] ?? null;
            $row['showroom'] = $balance?->showroom;
            $row['warehouse'] = $balance?->warehouse;
            $row['balance_version'] = $balance?->version;
            $row['location_consistent'] = ! $balance || $balance->showroom + $balance->warehouse === $row['quantity'];
            if ($withCost) {
                $summary = $this->costing->identitySummary($product, $row['variant_id'], $row['size_id'], true, 0);
                $row['cost'] = $summary['cost_coverage_complete'] ? $summary['average_inventory_unit_cost'] : null;
                $row['cost_complete'] = $summary['cost_coverage_complete'];
                $row['inventory_value'] = $summary['inventory_value'];
                $row['reference_cost'] = $summary['reference_unit_cost'];
            }
        }

        return $rows;
    }

    public function snapshot(Product $product, array $rows): array
    {
        return ['identities' => collect($rows)->mapWithKeys(fn ($r) => [$r['key'] => ['quantity' => $r['quantity'], 'balance_version' => $r['balance_version']]])->all(),
            'movement_id' => (int) DB::table('product_stock_movements')->where('product_id', $product->id)->where('quantity', '!=', 0)->max('id')];
    }

    public function product(Product $product, int $session, User $user): array
    {
        $this->session($session);
        $counts = DB::table('inventory_web_counts')->where('session_id', $session)->where('product_id', $product->id)->get();
        $history = DB::table('inventory_web_events')->leftJoin('users', 'users.id', '=', 'inventory_web_events.user_id')->where('product_id', $product->id)->where('action', 'count_submitted')->orderByDesc('inventory_web_events.id')->get(['inventory_web_events.details', 'inventory_web_events.created_at', 'users.name'])->map(function ($event) {
            $details = $this->decode($event->details);

            return (object) ['place' => $details['place'], 'counted_at' => $event->created_at, 'name' => $event->name];
        });
        $rows = $this->identities($product, $user->inventory_web_role === 'admin');

        return ['id' => $product->id, 'name' => $product->nameAr, 'code' => $product->product_code, 'location' => $product->storeSection?->name, 'image' => ProductImageResolver::preferredUrl($product), 'updated_at' => $product->updated_at->toISOString(), 'retail' => (float) $product->normailPrice, 'wholesale' => (float) $product->wholesalePrice,
            'rows' => $rows, 'stock_token' => hash('sha256', json_encode($this->snapshot($product, $rows))),
            'counts' => $counts->mapWithKeys(fn ($c) => [$c->place => ['id' => $c->id, 'version' => $c->version, 'status' => $c->status, 'lines' => $this->decode($c->lines), 'counted_at' => $c->counted_at, 'approved_at' => $c->approved_at]])->all(),
            'history' => collect(['showroom', 'warehouse'])->mapWithKeys(fn ($place) => [$place => $history->firstWhere('place', $place)])->all()];
    }

    public function saveCount(User $user, Product $product, int $session, string $place, array $lines, bool $submit, int $version, string $stockToken): object
    {
        $this->openSession($session);

        return DB::transaction(function () use ($user, $product, $session, $place, $lines, $submit, $version, $stockToken) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $rows = $this->identities($product);
            $keys = array_column($rows, 'key');
            abort_unless(hash_equals(hash('sha256', json_encode($this->snapshot($product, $rows))), $stockToken), 409, 'حدثت حركة مخزون أثناء العدّ؛ أعد فتح المنتج وراجع الكميات.');
            if (array_diff(array_keys($lines), $keys)) {
                throw ValidationException::withMessages(['lines' => ['تغيرت ألوان المنتج؛ أعد فتحه.']]);
            }
            foreach ($keys as $key) {
                $value = $lines[$key] ?? null;
                if ($submit && $value === null) {
                    throw ValidationException::withMessages(['lines' => ['أكمل كمية كل لون ومقاس؛ الخانة الفارغة ليست صفراً.']]);
                }if ($value !== null && (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > 100000000)) {
                    throw ValidationException::withMessages(['lines' => ['الكمية يجب أن تكون عدداً صحيحاً من صفر فأكثر.']]);
                }$lines[$key] = $value === null ? null : (int) $value;
            }
            $query = DB::table('inventory_web_counts')->where(['session_id' => $session, 'product_id' => $product->id, 'place' => $place]);
            $existing = $query->lockForUpdate()->first();
            abort_if(($existing?->version ?? 0) !== $version, 409, 'حفظ مستخدم آخر هذا الجرد؛ حدّث الصفحة قبل الحفظ.');
            abort_if($existing?->status === 'approved', 422, 'تم اعتماد الجرد؛ افتح جلسة جرد جديدة.');
            $data = ['lines' => json_encode($lines), 'stock_snapshot' => json_encode($this->snapshot($product, $rows)), 'status' => $submit ? 'submitted' : 'draft', 'version' => $version + 1, 'counted_at' => $submit ? now() : null, 'counted_by' => $user->id, 'updated_at' => now()];
            if ($existing) {
                $query->update($data);
            } else {
                DB::table('inventory_web_counts')->insert($data + ['session_id' => $session, 'product_id' => $product->id, 'place' => $place, 'created_at' => now()]);
            }
            $this->event($user, $submit ? 'count_submitted' : 'count_draft', (int) $product->id, ['session' => $session, 'place' => $place, 'lines' => $lines]);

            return $query->first();
        });
    }

    private function replay(string $requestKey, string $hash, string $type, ?int $product): ?object
    {
        $doc = DB::table('inventory_web_documents')->where('request_key', $requestKey)->first();
        if ($doc) {
            abort_unless(hash_equals($doc->request_hash, $hash) && $doc->type === $type && $doc->product_id === $product, 409, 'معرّف العملية مستخدم لطلب آخر.');
        }

        return $doc;
    }

    private function document(User $user, string $type, string $key, string $hash, ?int $product, ?int $session, array $lines, string $reason, ?string $from = null, ?string $to = null, ?string $date = null): object
    {
        $id = DB::table('inventory_web_documents')->insertGetId(['request_key' => $key, 'request_hash' => $hash, 'type' => $type, 'session_id' => $session, 'product_id' => $product, 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'reason' => $reason, 'from_place' => $from, 'to_place' => $to, 'document_date' => $date ?: now()->toDateString(), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('inventory_web_documents')->find($id);
    }

    public function approve(User $user, Product $product, array $data): object
    {
        $this->admin($user);
        $hash = hash('sha256', json_encode($data));

        return DB::transaction(function () use ($user, $product, $data, $hash) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($doc = $this->replay($data['request_key'], $hash, 'count', (int) $product->id)) {
                return $doc;
            }
            $this->openSession($data['session_id']);
            $counts = DB::table('inventory_web_counts')->where('session_id', $data['session_id'])->where('product_id', $product->id)->lockForUpdate()->get()->keyBy('place');
            foreach (['showroom', 'warehouse'] as $place) {
                abort_unless(isset($counts[$place]) && $counts[$place]->status === 'submitted', 422, 'يجب إرسال جرد المعرض والمستودع للمراجعة أولاً.');
            }
            $rows = $this->identities($product, true);
            $current = $this->snapshot($product, $rows);
            foreach ($counts as $count) {
                abort_unless($this->decode($count->stock_snapshot) === $current, 409, 'تغير المخزون بعد العدّ ببيع أو شراء أو تحويل؛ أعد مراجعة وحفظ الجرد قبل اعتماده.');
            }
            $show = $this->decode($counts['showroom']->lines);
            $warehouse = $this->decode($counts['warehouse']->lines);
            $approved = [];
            $this->locations->redistribute(function () use ($rows, $show, $warehouse, $data, $user, $product, &$approved) {
                foreach ($rows as $row) {
                    $key = $row['key'];
                    $actual = $show[$key] + $warehouse[$key];
                    $difference = $actual - $row['quantity'];
                    $cost = $data['increase_costs'][$key] ?? null;
                    if ($difference > 0 && $cost === null) {
                        throw ValidationException::withMessages(['increase_costs' => ['تكلفة الزيادة مطلوبة للصنف '.$row['color']]]);
                    }
                    if ($difference !== 0) {
                        $this->adjustments->adjustQuantity($product, $actual, $data['reason'], 'جرد ويب — جلسة '.$data['session_id'], $cost === null ? null : (float) $cost, 'شيكل', $row['variant_id'], (int) $user->id);
                    }
                    $identity = $this->locations->key((int) $product->id, $row['variant_id']);
                    $balance = DB::table('inventory_location_balances')->where('identity_key', $identity)->lockForUpdate()->first();
                    DB::table('inventory_location_balances')->updateOrInsert(['identity_key' => $identity], ['product_id' => $product->id, 'size_color_id' => $row['variant_id'], 'showroom' => $show[$key], 'warehouse' => $warehouse[$key], 'version' => ($balance?->version ?? 0) + 1, 'created_at' => $balance?->created_at ?? now(), 'updated_at' => now()]);
                    $approved[] = ['key' => $key, 'color' => $row['color'], 'size' => $row['size'], 'before' => $row['quantity'], 'showroom' => $show[$key], 'warehouse' => $warehouse[$key], 'after' => $actual, 'difference' => $difference];
                }
            });
            DB::table('inventory_web_counts')->whereIn('id', $counts->pluck('id'))->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->event($user, 'count_approved', (int) $product->id, ['session' => $data['session_id']]);

            return $this->document($user, 'count', $data['request_key'], $hash, (int) $product->id, $data['session_id'], $approved, $data['reason']);
        });
    }

    public function transfer(User $user, array $data): object
    {
        $this->admin($user);
        $hash = hash('sha256', json_encode($data));

        return DB::transaction(function () use ($user, $data, $hash) {
            // Serialize this atomic multi-product document in deterministic product order.
            $ids = collect($data['lines'])->pluck('product_id')->unique()->sort()->values();
            $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($products->count() === $ids->count(), 422, 'أحد المنتجات غير متاح.');
            if ($doc = $this->replay($data['request_key'], $hash, 'transfer', null)) {
                return $doc;
            }
            $from = $data['from'];
            $to = $from === 'warehouse' ? 'showroom' : 'warehouse';
            $documentLines = [];
            $seen = [];
            foreach ($data['lines'] as $line) {
                $product = $products[$line['product_id']];
                $rows = collect($this->identities($product));
                $row = $rows->firstWhere('key', (string) $line['key']);
                abort_unless($row && $row['active'], 422, 'الصنف غير متاح للتحويل.');
                $identity = $this->locations->key((int) $product->id, $row['variant_id']);
                abort_if(isset($seen[$identity]), 422, 'كرر الصنف في سطر واحد فقط.');
                $seen[$identity] = true;
                $balance = DB::table('inventory_location_balances')->where('identity_key', $identity)->lockForUpdate()->first();
                abort_unless($balance, 422, 'اعتمد توزيع هذا المنتج بين المعرض والمستودع أولاً.');
                abort_unless($balance->version === $line['version'], 409, 'تغير رصيد أحد الأصناف؛ أعد فتح السند.');
                abort_unless($balance->showroom + $balance->warehouse === $row['quantity'], 409, 'الرصيد العام لا يطابق المكانين؛ راجع الجرد.');
                $q = (int) $line['quantity'];
                abort_if($q > $balance->$from, 422, 'رصيد المصدر غير كافٍ: '.$product->nameAr.' / '.$row['color']);
                DB::table('inventory_location_balances')->where('id', $balance->id)->update([$from => $balance->$from - $q, $to => $balance->$to + $q, 'version' => $balance->version + 1, 'updated_at' => now()]);
                $documentLines[] = ['product_id' => $product->id, 'product' => $product->nameAr, 'key' => $row['key'], 'color' => $row['color'], 'size' => $row['size'], 'quantity' => $q, 'source_before' => $balance->$from, 'source_after' => $balance->$from - $q, 'target_before' => $balance->$to, 'target_after' => $balance->$to + $q];
            }
            $this->event($user, 'transfer_posted', null, ['from' => $from, 'to' => $to, 'lines' => count($documentLines)]);

            return $this->document($user, 'transfer', $data['request_key'], $hash, null, null, $documentLines, $data['reason'], $from, $to, $data['date']);
        });
    }

    public function cost(User $user, Product $product, array $data): object
    {
        $this->admin($user);
        $hash = hash('sha256', json_encode($data));

        return DB::transaction(function () use ($user, $product, $data, $hash) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($doc = $this->replay($data['request_key'], $hash, 'cost', (int) $product->id)) {
                return $doc;
            }
            $row = collect($this->identities($product, true))->firstWhere('key', $data['key']);
            abort_unless($row, 422, 'الصنف غير موجود.');
            $costVersion = (int) DB::table('inventory_adjustments')->where('product_id', $product->id)->max('id');
            abort_unless($costVersion === $data['cost_version'] && $row['quantity'] === $data['expected_quantity'], 409, 'تغيرت الكمية أو التكلفة؛ حدّث المنتج وراجع الأثر مجدداً.');
            if ($row['quantity'] <= 0) {
                $adjustment = $this->adjustments->setReferenceCost($product, (float) $data['unit_cost'], $data['reason'], null, 'شيكل', $row['variant_id'], (int) $user->id);
            } elseif (! $row['cost_complete']) {
                $adjustment = $this->adjustments->initializeMissingCost($product, (float) $data['unit_cost'], $data['reason'], null, 'شيكل', $row['variant_id'], (int) $user->id);
            } else {
                $adjustment = $this->adjustments->revalue($product,(float) $data['unit_cost'],$data['reason'],null,'شيكل',$row['variant_id'],(int) $user->id);
            }
            $this->event($user,'cost_changed',(int) $product->id,['adjustment' => $adjustment->id]);

            return $this->document($user,'cost',$data['request_key'],$hash,(int) $product->id,null,[['adjustment' => $adjustment->id, 'key' => $row['key'], 'before' => $row['cost'], 'after' => $adjustment->new_unit_cost, 'quantity' => $row['quantity'], 'value_difference' => $adjustment->value_difference]],$data['reason']);
        });
    }
}
