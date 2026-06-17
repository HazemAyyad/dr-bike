<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shiply_cities')) {
            Schema::create('shiply_cities', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('shiply_id');
                $table->string('name');
                $table->string('mode', 10)->default('test');
                $table->timestamp('deleted_at_remote')->nullable();
                $table->timestamps();

                $table->unique(['shiply_id', 'mode']);
                $table->index('mode');
            });
        }

        if (! Schema::hasTable('shiply_villages')) {
            Schema::create('shiply_villages', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('shiply_id');
                $table->unsignedInteger('shiply_city_id');
                $table->string('name');
                $table->unsignedInteger('region_id')->nullable();
                $table->unsignedTinyInteger('region_type')->nullable();
                $table->string('note', 500)->nullable();
                $table->boolean('is_closed')->default(false);
                $table->string('mode', 10)->default('test');
                $table->timestamp('deleted_at_remote')->nullable();
                $table->timestamps();

                $table->unique(['shiply_id', 'mode']);
                $table->index(['shiply_city_id', 'mode']);
                $table->index(['mode', 'is_closed']);
            });
        }

        if (Schema::hasTable('sales_orders')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_orders', 'shiply_city_id')) {
                    $table->unsignedInteger('shiply_city_id')->nullable()->after('city_id');
                }
                if (! Schema::hasColumn('sales_orders', 'shiply_village_id')) {
                    $table->unsignedInteger('shiply_village_id')->nullable()->after('shiply_city_id');
                }
                if (! Schema::hasColumn('sales_orders', 'shiply_city_name')) {
                    $table->string('shiply_city_name')->nullable()->after('shiply_village_id');
                }
                if (! Schema::hasColumn('sales_orders', 'shiply_village_name')) {
                    $table->string('shiply_village_name')->nullable()->after('shiply_city_name');
                }
            });
        }

        if (Schema::hasTable('sales_order_deliveries')) {
            Schema::table('sales_order_deliveries', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_order_deliveries', 'handed_over_by_user_id')) {
                    $table->foreignId('handed_over_by_user_id')->nullable()->after('delivery_company_name')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('sales_order_deliveries', 'shiply_employee_email')) {
                    $table->string('shiply_employee_email')->nullable()->after('handed_over_by_user_id');
                }
                if (! Schema::hasColumn('sales_order_deliveries', 'shiply_mode')) {
                    $table->string('shiply_mode', 10)->nullable()->after('shiply_employee_email');
                }
                if (! Schema::hasColumn('sales_order_deliveries', 'shiply_parcel_code')) {
                    $table->string('shiply_parcel_code', 100)->nullable()->after('shiply_mode');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_deliveries')) {
            Schema::table('sales_order_deliveries', function (Blueprint $table) {
                foreach (['shiply_parcel_code', 'shiply_mode', 'shiply_employee_email', 'handed_over_by_user_id'] as $col) {
                    if (Schema::hasColumn('sales_order_deliveries', $col)) {
                        if ($col === 'handed_over_by_user_id') {
                            $table->dropForeign(['handed_over_by_user_id']);
                        }
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('sales_orders')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                foreach (['shiply_village_name', 'shiply_city_name', 'shiply_village_id', 'shiply_city_id'] as $col) {
                    if (Schema::hasColumn('sales_orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('shiply_villages');
        Schema::dropIfExists('shiply_cities');
    }
};
