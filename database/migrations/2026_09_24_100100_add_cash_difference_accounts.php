<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_accounts')) {
            return;
        }

        $this->ensureAccount(
            'cash_overage_income',
            '4400',
            4400,
            4499,
            'إيرادات زيادة الصندوق',
            'Cash Overage Income',
            'revenue',
            'credit',
        );
        $this->ensureAccount(
            'cash_shortage_expense',
            '6600',
            6600,
            6699,
            'مصروف عجز الصندوق',
            'Cash Shortage Expense',
            'expense',
            'debit',
        );
    }

    private function ensureAccount(
        string $systemKey,
        string $preferredCode,
        int $rangeStart,
        int $rangeEnd,
        string $nameAr,
        string $nameEn,
        string $type,
        string $normalBalance,
    ): void {
        $existing = DB::table('accounting_accounts')->where('system_key', $systemKey)->first();
        if ($existing) {
            DB::table('accounting_accounts')->where('id', $existing->id)->update([
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'is_control' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        $code = $preferredCode;
        if (DB::table('accounting_accounts')->where('code', $code)->exists()) {
            $code = collect(range($rangeStart, $rangeEnd))
                ->map(fn (int $candidate) => (string) $candidate)
                ->first(fn (string $candidate) => ! DB::table('accounting_accounts')->where('code', $candidate)->exists());
        }
        if (! $code) {
            throw new RuntimeException("No available account code for {$systemKey}.");
        }

        DB::table('accounting_accounts')->insert([
            'code' => $code,
            'system_key' => $systemKey,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'parent_id' => null,
            'is_control' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Non-destructive: production journals may reference these accounts.
    }
};
