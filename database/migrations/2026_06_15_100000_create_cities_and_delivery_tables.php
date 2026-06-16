<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->string('shiply_area_code')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('city_delivery_fees')) {
            Schema::create('city_delivery_fees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
                $table->decimal('fee', 12, 2)->default(0);
                $table->date('effective_from')->nullable();
                $table->timestamps();

                $table->index(['city_id', 'effective_from']);
            });
        }

        if (! Schema::hasTable('delivery_companies')) {
            Schema::create('delivery_companies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->seedCities();
        $this->seedDeliveryCompanies();
    }

    public function down(): void
    {
        Schema::dropIfExists('city_delivery_fees');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('delivery_companies');
    }

    private function seedCities(): void
    {
        if (! Schema::hasTable('cities') || DB::table('cities')->exists()) {
            return;
        }

        $cities = [
            ['name_ar' => 'نابلس', 'name_en' => 'Nablus', 'fee' => 20],
            ['name_ar' => 'رام الله', 'name_en' => 'Ramallah', 'fee' => 25],
            ['name_ar' => 'الخليل', 'name_en' => 'Hebron', 'fee' => 25],
            ['name_ar' => 'جنين', 'name_en' => 'Jenin', 'fee' => 20],
            ['name_ar' => 'طولكرم', 'name_en' => 'Tulkarm', 'fee' => 20],
            ['name_ar' => 'قلقيلية', 'name_en' => 'Qalqilya', 'fee' => 20],
            ['name_ar' => 'بيت لحم', 'name_en' => 'Bethlehem', 'fee' => 25],
            ['name_ar' => 'أريحا', 'name_en' => 'Jericho', 'fee' => 25],
            ['name_ar' => 'سلفيت', 'name_en' => 'Salfit', 'fee' => 20],
            ['name_ar' => 'طوباس', 'name_en' => 'Tubas', 'fee' => 20],
        ];

        foreach ($cities as $index => $city) {
            $cityId = DB::table('cities')->insertGetId([
                'name_ar' => $city['name_ar'],
                'name_en' => $city['name_en'],
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('city_delivery_fees')) {
                DB::table('city_delivery_fees')->insert([
                    'city_id' => $cityId,
                    'fee' => $city['fee'],
                    'effective_from' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedDeliveryCompanies(): void
    {
        if (! Schema::hasTable('delivery_companies') || DB::table('delivery_companies')->exists()) {
            return;
        }

        DB::table('delivery_companies')->insert([
            'name' => 'شبلي (Shiply)',
            'code' => 'shiply',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
