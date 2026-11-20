<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryLocationService
{
    private int $redistributing = 0;

    public function redistribute(callable $callback): mixed
    {
        $this->redistributing++;
        try {
            return $callback();
        } finally {
            $this->redistributing--;
        }
    }

    public function key(int $product, ?int $variant): string
    {
        return 'product:'.$product.':variant:'.($variant ?: 'main');
    }

    /** Only identities explicitly distributed by an approved count have location stock. */
    public function mirrorMovement(int $product, ?int $variant, int $quantity, int $before, int $after, string $type): void
    {
        if ($this->redistributing || $quantity === 0 || ! Schema::hasTable('inventory_location_balances')) {
            return;
        }
        $balance = DB::table('inventory_location_balances')->where('identity_key', $this->key($product, $variant))->lockForUpdate()->first();
        if (! $balance) {
            return;
        }
        if ($balance->showroom + $balance->warehouse !== $before) {
            throw ValidationException::withMessages(['inventory' => ['رصيد المعرض والمستودع لا يطابق الرصيد العام؛ راجع الجرد قبل هذه الحركة.']]);
        }
        // Purchases and their reversals enter/leave warehouse; sales, returns, maintenance and other legacy movements use showroom.
        $place = str_starts_with($type, 'purchase') ? 'warehouse' : 'showroom';
        $next = $balance->$place + $quantity;
        if ($next < 0) {
            throw ValidationException::withMessages(['inventory' => ['رصيد '.($place === 'showroom' ? 'المعرض' : 'المستودع').' غير كافٍ؛ نفّذ تحويل مخزون من المكان الآخر أولاً.']]);
        }
        DB::table('inventory_location_balances')->where('id', $balance->id)->update([$place => $next, 'version' => $balance->version + 1, 'updated_at' => now()]);
    }
}
