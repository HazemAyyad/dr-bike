<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE sales_order_items soi
            INNER JOIN sales_orders so ON so.id = soi.sales_order_id
            SET soi.reserved_qty = soi.quantity
            WHERE so.status = 'unconfirmed'
              AND soi.is_hidden = 0
              AND soi.reserved_qty = 0
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE sales_order_items soi
            INNER JOIN sales_orders so ON so.id = soi.sales_order_id
            SET soi.reserved_qty = 0
            WHERE so.status = 'unconfirmed'
              AND soi.is_hidden = 0
              AND soi.reserved_qty = soi.quantity
        ");
    }
};
