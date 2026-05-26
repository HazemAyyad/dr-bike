<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_reminders') && ! Schema::hasColumn('employee_reminders', 'repeat_days')) {
            Schema::table('employee_reminders', function (Blueprint $table) {
                $table->json('repeat_days')->nullable()->after('repeat_type');
            });
        }

        if (! Schema::hasTable('employee_reminder_histories')) {
            Schema::create('employee_reminder_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reminder_id')->constrained('employee_reminders')->cascadeOnDelete();
                $table->foreignId('occurrence_id')->nullable()->constrained('employee_reminder_occurrences')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employee_details')->nullOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 40)->index();
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['reminder_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_reminder_histories');

        if (Schema::hasTable('employee_reminders') && Schema::hasColumn('employee_reminders', 'repeat_days')) {
            Schema::table('employee_reminders', function (Blueprint $table) {
                $table->dropColumn('repeat_days');
            });
        }
    }
};
