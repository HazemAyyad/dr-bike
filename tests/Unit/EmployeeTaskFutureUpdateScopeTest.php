<?php

namespace Tests\Unit;

use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeTaskFutureUpdateScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('employee_task_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('display_number')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('priority')->default('medium');
            $table->boolean('is_forced_to_upload_img')->default(false);
            $table->string('proof_media_type')->default('none');
            $table->boolean('requires_admin_review')->default(true);
            $table->boolean('not_shown_for_employee')->default(false);
            $table->json('admin_img')->nullable();
            $table->string('audio')->nullable();
            $table->string('recurrence_type');
            $table->json('recurrence_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_task_template_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_image')->default(false);
            $table->string('proof_media_type')->default('none');
            $table->unsignedInteger('bonus_points')->default(0);
            $table->json('admin_img')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('completed_by_employee_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('priority')->default('medium');
            $table->string('status')->default('pending');
            $table->boolean('is_canceled')->default(false);
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->date('scheduled_date');
            $table->json('employee_img')->nullable();
            $table->json('admin_img')->nullable();
            $table->string('audio')->nullable();
            $table->boolean('is_forced_to_upload_img')->default(false);
            $table->string('proof_media_type')->default('none');
            $table->boolean('requires_admin_review')->default(true);
            $table->boolean('not_shown_for_employee')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_task_occurrence_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->unsignedBigInteger('template_subtask_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_image')->default(false);
            $table->string('proof_media_type')->default('none');
            $table->unsignedInteger('bonus_points')->default(0);
            $table->string('status')->default('pending');
            $table->json('admin_img')->nullable();
            $table->json('employee_img')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_updates_only_untouched_current_and_future_occurrences(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $templateId = DB::table('employee_task_templates')->insertGetId([
            'display_number' => 1,
            'employee_id' => 20,
            'name' => 'الاسم الجديد',
            'points' => 5,
            'priority' => 'high',
            'recurrence_type' => 'daily',
            'recurrence_config' => json_encode([
                'anchor_date' => '2026-08-01',
                'start_time' => '2026-09-01 17:00:00',
                'end_time' => '2026-09-01 18:00:00',
            ]),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_task_template_subtasks')->insert([
            'template_id' => $templateId,
            'name' => 'البند الجديد',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pastId = $this->occurrence($templateId, '2026-08-31');
        $currentId = $this->occurrence($templateId, '2026-09-01');
        $startedId = $this->occurrence($templateId, '2026-09-02', ['started_at' => now()]);

        $service = new EmployeeTaskRecurrenceService($this->createMock(EmployeeTaskTimelineService::class));
        $updated = $service->syncCurrentAndFutureOccurrences(EmployeeTaskTemplate::findOrFail($templateId));

        $this->assertSame(1, $updated);
        $this->assertSame('قديم', EmployeeTaskOccurrence::findOrFail($pastId)->name);
        $this->assertSame('الاسم الجديد', EmployeeTaskOccurrence::findOrFail($currentId)->name);
        $this->assertSame('قديم', EmployeeTaskOccurrence::findOrFail($startedId)->name);
        $this->assertDatabaseHas('employee_task_occurrence_subtasks', [
            'occurrence_id' => $currentId,
            'name' => 'البند الجديد',
        ]);
    }

    private function occurrence(int $templateId, string $date, array $extra = []): int
    {
        return DB::table('employee_task_occurrences')->insertGetId(array_merge([
            'template_id' => $templateId,
            'employee_id' => 20,
            'name' => 'قديم',
            'start_time' => $date.' 10:00:00',
            'end_time' => $date.' 11:00:00',
            'scheduled_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
