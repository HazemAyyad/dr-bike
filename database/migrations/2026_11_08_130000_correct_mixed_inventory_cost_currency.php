<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // All inventory costs are denominated in shekels. "mixed" was copied
        // from a summary label into the currency field by the stocktaking UI.
        // Query-builder updates preserve quantities, amounts and audit dates.
        DB::transaction(function () {
            foreach (['inventory_adjustments', 'inventory_cost_balances', 'inventory_cost_layers', 'purchase_price_histories'] as $table) {
                DB::table($table)
                    ->whereRaw('LOWER(TRIM(currency)) = ?', ['mixed'])
                    ->update(['currency' => 'شيكل']);
            }
        });
    }

    public function down(): void
    {
        // A code rollback must not restore an invalid accounting currency.
        // The pre-deployment database backup retains the original audit data.
    }
};
