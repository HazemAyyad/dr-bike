<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $renamedAssignments = false;
        $renamedColumn = false;

        if (Schema::hasTable('debt_contact_category_assignments') && ! Schema::hasTable('contact_category_assignments')) {
            $this->dropForeignKeys('debt_contact_category_assignments');
            Schema::rename('debt_contact_category_assignments', 'contact_category_assignments');
            $renamedAssignments = true;
        }

        if (Schema::hasTable('debt_contact_categories') && ! Schema::hasTable('contact_categories')) {
            Schema::rename('debt_contact_categories', 'contact_categories');
        }

        if (Schema::hasTable('contact_category_assignments') && Schema::hasColumn('contact_category_assignments', 'debt_contact_category_id')) {
            $this->dropIndexes('contact_category_assignments', ['debt_cat_customer_unique', 'debt_cat_seller_unique']);
            DB::statement('ALTER TABLE contact_category_assignments CHANGE debt_contact_category_id contact_category_id BIGINT UNSIGNED NOT NULL');
            $renamedColumn = true;
        }

        if (Schema::hasTable('contact_category_assignments') && ($renamedAssignments || $renamedColumn)) {
            DB::statement('ALTER TABLE contact_category_assignments ADD CONSTRAINT contact_cat_assign_cat_fk FOREIGN KEY (contact_category_id) REFERENCES contact_categories(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE contact_category_assignments ADD CONSTRAINT contact_cat_assign_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE contact_category_assignments ADD CONSTRAINT contact_cat_assign_seller_fk FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE contact_category_assignments ADD UNIQUE contact_cat_customer_unique (contact_category_id, customer_id)');
            DB::statement('ALTER TABLE contact_category_assignments ADD UNIQUE contact_cat_seller_unique (contact_category_id, seller_id)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_category_assignments')) {
            $this->dropForeignKeys('contact_category_assignments');
            $this->dropIndexes('contact_category_assignments', ['contact_cat_customer_unique', 'contact_cat_seller_unique']);

            if (Schema::hasColumn('contact_category_assignments', 'contact_category_id')) {
                DB::statement('ALTER TABLE contact_category_assignments CHANGE contact_category_id debt_contact_category_id BIGINT UNSIGNED NOT NULL');
            }

            if (! Schema::hasTable('debt_contact_category_assignments')) {
                Schema::rename('contact_category_assignments', 'debt_contact_category_assignments');
            }
        }

        if (Schema::hasTable('contact_categories') && ! Schema::hasTable('debt_contact_categories')) {
            Schema::rename('contact_categories', 'debt_contact_categories');
        }

        if (Schema::hasTable('debt_contact_category_assignments')) {
            DB::statement('ALTER TABLE debt_contact_category_assignments ADD CONSTRAINT debt_cat_assign_cat_fk FOREIGN KEY (debt_contact_category_id) REFERENCES debt_contact_categories(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE debt_contact_category_assignments ADD CONSTRAINT debt_cat_assign_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE debt_contact_category_assignments ADD CONSTRAINT debt_cat_assign_seller_fk FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE debt_contact_category_assignments ADD UNIQUE debt_cat_customer_unique (debt_contact_category_id, customer_id)');
            DB::statement('ALTER TABLE debt_contact_category_assignments ADD UNIQUE debt_cat_seller_unique (debt_contact_category_id, seller_id)');
        }
    }

    private function dropForeignKeys(string $table): void
    {
        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table]
        );

        foreach ($constraints as $constraint) {
            DB::statement(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint->CONSTRAINT_NAME));
        }
    }

    private function dropIndexes(string $table, array $names): void
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $indexes = DB::select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME IN ($placeholders)",
            array_merge([$table], $names)
        );

        foreach ($indexes as $index) {
            DB::statement(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $index->INDEX_NAME));
        }
    }
};
