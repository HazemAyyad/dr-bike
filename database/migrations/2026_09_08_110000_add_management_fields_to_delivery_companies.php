<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_companies', function (Blueprint $table) {
            $table->string('delivery_type', 30)->nullable()->after('code');
            $table->decimal('default_carrier_fee', 12, 2)->nullable()->after('delivery_type');
            $table->string('contact_name')->nullable()->after('default_carrier_fee');
            $table->string('contact_phone', 50)->nullable()->after('contact_name');
            $table->string('vehicle_number', 50)->nullable()->after('contact_phone');
            $table->text('notes')->nullable()->after('vehicle_number');
        });

        DB::table('delivery_companies')->orderBy('id')->get()->each(function ($company) {
            $code = strtolower(trim((string) $company->code));
            $type = match ($code) {
                'shiply' => 'shiply',
                'taxi' => 'taxi',
                'doctor_bike' => 'internal',
                'self', 'pickup' => 'pickup',
                default => 'office',
            };
            DB::table('delivery_companies')->where('id', $company->id)->update([
                'delivery_type' => $type,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_companies', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_type',
                'default_carrier_fee',
                'contact_name',
                'contact_phone',
                'vehicle_number',
                'notes',
            ]);
        });
    }
};
