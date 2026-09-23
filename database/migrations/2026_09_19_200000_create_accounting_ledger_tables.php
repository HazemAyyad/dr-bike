<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_accounts')) {
            Schema::create('accounting_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code', 24)->unique();
                $table->string('system_key', 80)->nullable()->unique();
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->string('type', 24)->index();
                $table->string('normal_balance', 8);
                $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
                $table->boolean('is_control')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('accounting_periods')) {
            Schema::create('accounting_periods', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->date('starts_at');
                $table->date('ends_at');
                $table->string('status', 16)->default('open')->index();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('closing_note')->nullable();
                $table->timestamps();
                $table->unique(['starts_at', 'ends_at'], 'accounting_period_range_unique');
            });
        }

        if (! Schema::hasTable('accounting_journal_entries')) {
            Schema::create('accounting_journal_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_number', 48)->unique();
                $table->string('source_key', 191)->unique();
                $table->string('source_type', 80)->nullable()->index();
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
                $table->date('entry_date')->index();
                $table->string('currency', 20)->default('شيكل')->index();
                $table->string('status', 16)->default('posted')->index();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('reverses_entry_id')->nullable()->constrained('accounting_journal_entries')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['source_type', 'source_id', 'currency'], 'accounting_journal_source_idx');
            });
        }

        if (! Schema::hasTable('accounting_journal_lines')) {
            Schema::create('accounting_journal_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('journal_entry_id')->constrained('accounting_journal_entries')->cascadeOnDelete();
                $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
                $table->decimal('debit', 18, 4)->default(0);
                $table->decimal('credit', 18, 4)->default(0);
                $table->string('description')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->unsignedBigInteger('seller_id')->nullable()->index();
                $table->unsignedBigInteger('delivery_company_id')->nullable()->index();
                $table->unsignedBigInteger('box_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->date('due_date')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'journal_entry_id'], 'accounting_line_account_entry_idx');
            });
        }

        if (! Schema::hasTable('accounting_projection_failures')) {
            Schema::create('accounting_projection_failures', function (Blueprint $table) {
                $table->id();
                $table->string('source_key', 191)->unique();
                $table->string('source_type', 80)->index();
                $table->unsignedBigInteger('source_id')->index();
                $table->text('error');
                $table->json('context')->nullable();
                $table->unsignedInteger('attempts')->default(1);
                $table->timestamp('last_failed_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        $now = now();
        $accounts = [
            ['code' => '1000', 'system_key' => 'cash', 'name_ar' => 'النقد والصناديق', 'name_en' => 'Cash and boxes', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1100', 'system_key' => 'accounts_receivable', 'name_ar' => 'ذمم مدينة', 'name_en' => 'Accounts receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1150', 'system_key' => 'checks_receivable', 'name_ar' => 'شيكات واردة', 'name_en' => 'Checks receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1200', 'system_key' => 'inventory', 'name_ar' => 'المخزون', 'name_en' => 'Inventory', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1300', 'system_key' => 'fixed_assets', 'name_ar' => 'الأصول الثابتة', 'name_en' => 'Fixed assets', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1390', 'system_key' => 'accumulated_depreciation', 'name_ar' => 'مجمع الإهلاك', 'name_en' => 'Accumulated depreciation', 'type' => 'contra_asset', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2000', 'system_key' => 'accounts_payable', 'name_ar' => 'ذمم دائنة', 'name_en' => 'Accounts payable', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2100', 'system_key' => 'checks_payable', 'name_ar' => 'شيكات صادرة', 'name_en' => 'Checks payable', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2150', 'system_key' => 'customer_deposits', 'name_ar' => 'دفعات زبائن مقدمة', 'name_en' => 'Customer deposits', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2200', 'system_key' => 'clearing', 'name_ar' => 'حسابات معلقة وتسوية', 'name_en' => 'Clearing and suspense', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2300', 'system_key' => 'vat_payable', 'name_ar' => 'ضريبة قيمة مضافة مستحقة', 'name_en' => 'VAT payable', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '2400', 'system_key' => 'salary_payable', 'name_ar' => 'رواتب مستحقة', 'name_en' => 'Salary payable', 'type' => 'liability', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '1250', 'system_key' => 'vat_receivable', 'name_ar' => 'ضريبة قيمة مضافة قابلة للاسترداد', 'name_en' => 'VAT receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '1270', 'system_key' => 'employee_advances', 'name_ar' => 'سلف الموظفين', 'name_en' => 'Employee advances', 'type' => 'asset', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '3000', 'system_key' => 'owner_equity', 'name_ar' => 'حقوق الملكية', 'name_en' => 'Owner equity', 'type' => 'equity', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '3100', 'system_key' => 'opening_balance', 'name_ar' => 'أرصدة افتتاحية', 'name_en' => 'Opening balances', 'type' => 'equity', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '3200', 'system_key' => 'inventory_revaluation_reserve', 'name_ar' => 'احتياطي إعادة تقييم المخزون', 'name_en' => 'Inventory revaluation reserve', 'type' => 'equity', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4000', 'system_key' => 'sales_revenue', 'name_ar' => 'إيرادات المبيعات', 'name_en' => 'Sales revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4100', 'system_key' => 'maintenance_revenue', 'name_ar' => 'إيرادات الصيانة', 'name_en' => 'Maintenance revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4150', 'system_key' => 'service_revenue', 'name_ar' => 'إيرادات الخدمات', 'name_en' => 'Service Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4200', 'system_key' => 'other_revenue', 'name_ar' => 'إيرادات أخرى', 'name_en' => 'Other revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4300', 'system_key' => 'inventory_gain', 'name_ar' => 'أرباح جرد المخزون', 'name_en' => 'Inventory count gains', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_control' => true],
            ['code' => '4900', 'system_key' => 'sales_returns', 'name_ar' => 'مردودات المبيعات', 'name_en' => 'Sales returns', 'type' => 'contra_revenue', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '5000', 'system_key' => 'cost_of_goods_sold', 'name_ar' => 'تكلفة البضاعة المباعة', 'name_en' => 'Cost of goods sold', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6000', 'system_key' => 'general_expense', 'name_ar' => 'مصاريف عمومية', 'name_en' => 'General expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6100', 'system_key' => 'salary_expense', 'name_ar' => 'مصاريف الرواتب', 'name_en' => 'Salary expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6200', 'system_key' => 'inventory_loss', 'name_ar' => 'خسائر وتسويات المخزون', 'name_en' => 'Inventory losses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6300', 'system_key' => 'depreciation_expense', 'name_ar' => 'مصروف الإهلاك', 'name_en' => 'Depreciation expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6400', 'system_key' => 'delivery_expense', 'name_ar' => 'مصاريف التوصيل', 'name_en' => 'Delivery expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
            ['code' => '6500', 'system_key' => 'project_expense', 'name_ar' => 'مصاريف المشاريع', 'name_en' => 'Project expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_control' => true],
        ];

        foreach ($accounts as $account) {
            DB::table('accounting_accounts')->updateOrInsert(
                ['code' => $account['code']],
                array_merge($account, ['is_active' => true, 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_projection_failures');
        Schema::dropIfExists('accounting_journal_lines');
        Schema::dropIfExists('accounting_journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounting_accounts');
    }
};
