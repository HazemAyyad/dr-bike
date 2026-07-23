<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_development_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('app_development_tasks', 'tags')) {
                $table->json('tags')->nullable()->after('priority');
            }

            if (! Schema::hasColumn('app_development_tasks', 'due_at')) {
                $table->date('due_at')->nullable()->after('tags');
                $table->index('due_at', 'adt_due_at_idx');
            }
        });

        if (! Schema::hasTable('app_development_task_reads')) {
            Schema::create('app_development_task_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_development_task_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->foreign('app_development_task_id', 'adt_read_task_fk')
                    ->references('id')
                    ->on('app_development_tasks')
                    ->cascadeOnDelete();
                $table->foreign('user_id', 'adt_read_user_fk')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
                $table->unique(['app_development_task_id', 'user_id'], 'adt_read_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_task_reads');

        Schema::table('app_development_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('app_development_tasks', 'due_at')) {
                $table->dropIndex('adt_due_at_idx');
                $table->dropColumn('due_at');
            }

            if (Schema::hasColumn('app_development_tasks', 'tags')) {
                $table->dropColumn('tags');
            }
        });
    }
};
