<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_signatures')) {
            Schema::create('employee_signatures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('name', 100);
                $table->string('source', 24);
                $table->string('original_path');
                $table->string('processed_path');
                $table->string('signature_hash', 64);
                $table->boolean('is_default')->default(false);
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['employee_id', 'is_default']);
            });
        }

        if (Schema::hasTable('salary_payment_items')) {
            Schema::table('salary_payment_items', function (Blueprint $table) {
                if (! Schema::hasColumn('salary_payment_items', 'employee_signature_id')) {
                    $table->unsignedBigInteger('employee_signature_id')->nullable()->after('employee_signature_path')->index();
                }
                if (! Schema::hasColumn('salary_payment_items', 'employee_signature_original_path')) {
                    $table->string('employee_signature_original_path')->nullable()->after('employee_signature_path');
                }
                if (! Schema::hasColumn('salary_payment_items', 'employee_signature_name')) {
                    $table->string('employee_signature_name', 100)->nullable()->after('employee_signature_id');
                }
                if (! Schema::hasColumn('salary_payment_items', 'employee_signature_source')) {
                    $table->string('employee_signature_source', 24)->nullable()->after('employee_signature_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('salary_payment_items')) {
            Schema::table('salary_payment_items', function (Blueprint $table) {
                foreach (['employee_signature_source', 'employee_signature_name', 'employee_signature_id', 'employee_signature_original_path'] as $column) {
                    if (Schema::hasColumn('salary_payment_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('employee_signatures');
    }
};
