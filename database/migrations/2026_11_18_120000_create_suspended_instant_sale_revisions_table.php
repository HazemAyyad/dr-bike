<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspended_instant_sale_revisions', function (Blueprint $table) {
            $table->id();
            // Keep the archive even if an invoice or its actor is later removed.
            $table->unsignedBigInteger('suspended_instant_sale_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 40);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspended_instant_sale_revisions');
    }
};
