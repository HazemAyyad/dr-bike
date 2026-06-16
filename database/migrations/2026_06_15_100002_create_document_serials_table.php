<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_serials')) {
            Schema::create('document_serials', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->string('document_type', 20);
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();

                $table->unique(['year', 'document_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_serials');
    }
};
