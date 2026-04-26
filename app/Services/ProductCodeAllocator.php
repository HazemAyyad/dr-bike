<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Thread-safe sequential product codes (6-digit zero-padded) via locked sequence row.
 */
class ProductCodeAllocator
{
    public function allocate(): string
    {
        return DB::transaction(function () {
            $row = DB::table('product_code_sequences')->where('id', 1)->lockForUpdate()->first();
            if ($row === null) {
                $max = (int) (DB::table('products')->max(DB::raw('CAST(product_code AS UNSIGNED)')) ?? 0);
                $next = $max + 1;
                DB::table('product_code_sequences')->insert([
                    'id' => 1,
                    'next_number' => $next + 1,
                ]);

                return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            }

            $n = (int) $row->next_number;
            $code = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            DB::table('product_code_sequences')->where('id', 1)->update(['next_number' => $n + 1]);

            return $code;
        });
    }
}
