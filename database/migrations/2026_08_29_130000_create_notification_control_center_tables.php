<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_sounds', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('source', 20)->default('bundled');
            $table->string('category', 64)->default('general');
            $table->text('description')->nullable();
            $table->string('android_resource')->nullable();
            $table->string('ios_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('checksum', 64)->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('background_capable')->default(true);
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->foreignId('fallback_sound_id')->nullable()->constrained('notification_sounds')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_policies', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 100)->unique();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->string('priority', 20)->default('normal');
            $table->foreignId('sound_id')->nullable()->constrained('notification_sounds')->nullOnDelete();
            $table->foreignId('fallback_sound_id')->nullable()->constrained('notification_sounds')->nullOnDelete();
            $table->boolean('vibration_enabled')->default(true);
            $table->boolean('show_foreground_banner')->default(true);
            $table->boolean('show_on_lock_screen')->default(true);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->boolean('bypass_quiet_hours')->default(false);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->string('audience', 30)->default('all_admins');
            $table->json('recipient_user_ids')->nullable();
            $table->json('recipient_roles')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 100)->index();
            $table->string('locale', 10)->default('ar');
            $table->string('title_template');
            $table->text('body_template');
            $table->string('lock_screen_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['notification_type', 'locale']);
        });

        Schema::create('admin_notification_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['admin_notification_id', 'user_id'], 'admin_notification_receipt_unique');
        });

        Schema::create('notification_device_sounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_device_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_sound_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sound_version');
            $table->string('status', 20)->default('pending')->index();
            $table->string('channel_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['admin_device_token_id', 'notification_sound_id'], 'device_sound_unique');
        });

        Schema::create('notification_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_device_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_sound_id')->nullable()->constrained('notification_sounds')->nullOnDelete();
            $table->foreignId('resolved_sound_id')->nullable()->constrained('notification_sounds')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('channel_id')->nullable();
            $table->boolean('used_fallback')->default(false);
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_policy_audits', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('action', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });

        if (Schema::hasTable('permissions')) {
            $values = ['name' => 'إدارة مركز الإشعارات', 'updated_at' => now()];
            if (Schema::hasColumn('permissions', 'grant_policy')) {
                $values['grant_policy'] = 'permissions_manage';
            }
            $permission = DB::table('permissions')->where('name_en', 'Notification Center Manage');
            if ($permission->exists()) {
                $permission->update($values);
            } else {
                DB::table('permissions')->insert(array_merge($values, [
                    'id' => ((int) DB::table('permissions')->max('id')) + 1,
                    'name_en' => 'Notification Center Manage',
                    'created_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name_en', 'Notification Center Manage')->delete();
        }

        Schema::dropIfExists('notification_policy_audits');
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_device_sounds');
        Schema::dropIfExists('admin_notification_receipts');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_policies');
        Schema::dropIfExists('notification_sounds');
    }
};
