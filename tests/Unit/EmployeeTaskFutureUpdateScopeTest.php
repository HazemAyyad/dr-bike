<?php

namespace Tests\Unit;

use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
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

        Schema::create('employee_task_template_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('employee_id');
            $table->timestamps();
            $table->unique(['template_id', 'employee_id']);
        });

        Schema::create('employee_task_occurrence_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->unsignedBigInteger('employee_id');
            $table->timestamps();
            $table->unique(['occurrence_id', 'employee_id']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_it_keeps_occurrence_only_assignment_changes_isolated(): void
    {
        $templateId = $this->template();
        $firstId = $this->occurrence($templateId, '2026-09-01');
        $secondId = $this->occurrence($templateId, '2026-09-02');
        $service = app(EmployeeTaskAssigneeService::class);

        $service->syncForTemplate(EmployeeTaskTemplate::findOrFail($templateId), [20]);
        $service->syncForOccurrence(EmployeeTaskOccurrence::findOrFail($firstId), [20, 30]);

        $this->assertSame([20, 30], $service->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($firstId)));
        $this->assertSame([20], $service->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($secondId)));
        $this->assertSame([20], $service->idsForTemplate(EmployeeTaskTemplate::findOrFail($templateId)));
        $this->assertTrue($service->canAccessOccurrence(EmployeeTaskOccurrence::findOrFail($firstId), 30));
        $this->assertFalse($service->canAccessOccurrence(EmployeeTaskOccurrence::findOrFail($secondId), 30));
    }

    public function test_shared_employee_can_trigger_lazy_occurrence_generation(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $templateId = $this->template();
        $assignees = app(EmployeeTaskAssigneeService::class);
        $assignees->syncForTemplate(EmployeeTaskTemplate::findOrFail($templateId), [20, 30]);

        $recurrence = new EmployeeTaskRecurrenceService($this->createMock(EmployeeTaskTimelineService::class));
        $recurrence->ensureActiveTemplateOccurrences(30);

        $occurrences = EmployeeTaskOccurrence::where('template_id', $templateId)->get();
        $this->assertNotEmpty($occurrences);
        foreach ($occurrences as $occurrence) {
            $this->assertSame([20, 30], $assignees->idsForOccurrence($occurrence));
        }
    }

    public function test_one_time_occurrence_assignment_is_not_overwritten_by_lazy_generation(): void
    {
        $templateId = $this->template();
        DB::table('employee_task_templates')->where('id', $templateId)->update([
            'recurrence_type' => EmployeeTaskRecurrenceService::ONE_TIME_PERSISTENT,
        ]);
        $occurrenceId = $this->occurrence($templateId, '2026-09-01');
        $assignees = app(EmployeeTaskAssigneeService::class);
        $template = EmployeeTaskTemplate::findOrFail($templateId);
        $assignees->syncForTemplate($template, [20]);
        $assignees->syncForOccurrence(EmployeeTaskOccurrence::findOrFail($occurrenceId), [30, 40]);

        $recurrence = new EmployeeTaskRecurrenceService($this->createMock(EmployeeTaskTimelineService::class));
        $recurrence->ensureOccurrences($template->fresh());

        $this->assertSame(
            [30, 40],
            $assignees->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($occurrenceId))
        );
    }

    public function test_current_and_future_assignment_change_preserves_history_and_started_work(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $templateId = $this->template();
        $pastId = $this->occurrence($templateId, '2026-08-31');
        $currentId = $this->occurrence($templateId, '2026-09-01');
        $startedId = $this->occurrence($templateId, '2026-09-02', ['started_at' => now()]);
        $assignees = app(EmployeeTaskAssigneeService::class);
        $template = EmployeeTaskTemplate::findOrFail($templateId);
        $assignees->syncForTemplate($template, [20]);

        $template->update(['employee_id' => 30]);
        $assignees->syncForTemplate($template->fresh(), [30, 40]);

        $recurrence = new EmployeeTaskRecurrenceService($this->createMock(EmployeeTaskTimelineService::class));
        $recurrence->syncCurrentAndFutureOccurrences($template->fresh());

        $this->assertSame([20], $assignees->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($pastId)));
        $this->assertSame([30, 40], $assignees->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($currentId)));
        $this->assertSame([20], $assignees->idsForOccurrence(EmployeeTaskOccurrence::findOrFail($startedId)));

        $generated = EmployeeTaskOccurrence::query()
            ->where('template_id', $templateId)
            ->whereNotIn('id', [$pastId, $currentId, $startedId])
            ->get();
        $this->assertNotEmpty($generated);
        foreach ($generated as $occurrence) {
            $this->assertSame([30, 40], $assignees->idsForOccurrence($occurrence));
        }
    }

    private function template(): int
    {
        return DB::table('employee_task_templates')->insertGetId([
            'display_number' => 2,
            'employee_id' => 20,
            'name' => 'مهمة',
            'points' => 1,
            'recurrence_type' => 'daily',
            'recurrence_config' => json_encode([
                'anchor_date' => '2026-08-01',
                'start_time' => '2026-09-01 10:00:00',
                'end_time' => '2026-09-01 11:00:00',
            ]),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function occurrence(int $templateId, string $date, array $extra = []): int
    {
        $id = DB::table('employee_task_occurrences')->insertGetId(array_merge([
            'template_id' => $templateId,
            'employee_id' => 20,
            'name' => 'قديم',
            'start_time' => $date.' 10:00:00',
            'end_time' => $date.' 11:00:00',
            'scheduled_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        DB::table('employee_task_occurrence_assignees')->insert([
            'occurrence_id' => $id,
            'employee_id' => (int) ($extra['employee_id'] ?? 20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
