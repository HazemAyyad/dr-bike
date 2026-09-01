<?php

namespace Tests\Unit;

use App\Models\EmployeeTaskTemplate;
use App\Services\EmployeeTasks\EmployeeTaskCancellationService;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeTaskCancellationServiceTest extends TestCase
{
    private EmployeeTaskCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('employee_task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('recurrence_type')->default('daily');
            $table->json('recurrence_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('legacy_task_id')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_canceled')->default(false);
            $table->date('scheduled_date')->nullable();
            $table->unsignedBigInteger('completed_by_employee_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_task_occurrence_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->string('status')->default('pending');
        });

        Schema::create('employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_canceled')->default(false);
            $table->dateTime('start_time')->nullable();
            $table->unsignedBigInteger('completed_by_employee_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sub_employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_task_id');
            $table->string('status')->default('pending');
        });

        $this->service = new EmployeeTaskCancellationService();
    }

    public function test_canceling_one_occurrence_does_not_cancel_its_series(): void
    {
        $templateId = $this->insertTemplate();
        $firstId = $this->insertOccurrence($templateId);
        $secondId = $this->insertOccurrence($templateId);

        $this->service->cancelOccurrence($firstId);

        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $firstId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $secondId, 'is_canceled' => 0]);
        $this->assertDatabaseHas('employee_task_templates', ['id' => $templateId, 'is_active' => 1]);
    }

    public function test_canceling_one_legacy_task_does_not_cancel_its_siblings(): void
    {
        $parentId = $this->insertLegacyTask();
        $firstChildId = $this->insertLegacyTask(parentId: $parentId);
        $secondChildId = $this->insertLegacyTask(parentId: $parentId);

        $this->service->cancelLegacyTask($firstChildId);

        $this->assertDatabaseHas('employee_tasks', ['id' => $parentId, 'is_canceled' => 0]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $firstChildId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $secondChildId, 'is_canceled' => 0]);
    }

    public function test_canceling_occurrence_series_preserves_history_and_cancels_only_untouched_current_and_future_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'Asia/Hebron'));
        $templateId = $this->insertTemplate();
        $pastCompletedId = $this->insertOccurrence($templateId, date: '2026-08-31', status: 'completed');
        $todayId = $this->insertOccurrence($templateId, date: '2026-09-01');
        $futureId = $this->insertOccurrence($templateId, date: '2026-09-02');
        $startedFutureId = $this->insertOccurrence($templateId, date: '2026-09-03', startedAt: now());
        $linkedLegacyId = $this->insertLegacyTask(templateId: $templateId, date: '2026-09-02');
        $unrelatedId = $this->insertOccurrence(null);

        $this->service->cancelOccurrenceSeries($todayId);

        $this->assertDatabaseHas('employee_task_templates', ['id' => $templateId, 'is_active' => 0]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $pastCompletedId, 'is_canceled' => 0]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $todayId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $futureId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $startedFutureId, 'is_canceled' => 0]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $linkedLegacyId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $unrelatedId, 'is_canceled' => 0]);

        $recurrence = new EmployeeTaskRecurrenceService(
            $this->createMock(EmployeeTaskTimelineService::class)
        );
        $template = EmployeeTaskTemplate::findOrFail($templateId);

        $this->assertCount(0, $recurrence->ensureOccurrences($template));
        $this->assertSame(4, DB::table('employee_task_occurrences')->where('template_id', $templateId)->count());
    }

    public function test_occurrence_without_template_never_cancels_unrelated_null_template_rows(): void
    {
        $targetId = $this->insertOccurrence(null);
        $unrelatedId = $this->insertOccurrence(null);

        try {
            $this->service->cancelOccurrenceSeries($targetId);
            $this->fail('Expected validation to reject an occurrence without a template.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('employee_task_occurrences', ['id' => $targetId, 'is_canceled' => 0]);
            $this->assertDatabaseHas('employee_task_occurrences', ['id' => $unrelatedId, 'is_canceled' => 0]);
        }
    }

    public function test_canceling_legacy_child_cancels_parent_and_all_siblings_only(): void
    {
        $parentId = $this->insertLegacyTask();
        $firstChildId = $this->insertLegacyTask(parentId: $parentId);
        $secondChildId = $this->insertLegacyTask(parentId: $parentId);
        $unrelatedId = $this->insertLegacyTask();

        $this->service->cancelLegacySeries($firstChildId);

        $this->assertDatabaseHas('employee_tasks', ['id' => $parentId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $firstChildId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $secondChildId, 'is_canceled' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $unrelatedId, 'is_canceled' => 0]);
    }

    private function insertTemplate(): int
    {
        return (int) DB::table('employee_task_templates')->insertGetId([
            'recurrence_type' => 'daily',
            'recurrence_config' => json_encode([]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOccurrence(
        ?int $templateId,
        ?int $legacyTaskId = null,
        string $date = '2026-09-01',
        string $status = 'pending',
        mixed $startedAt = null
    ): int
    {
        return (int) DB::table('employee_task_occurrences')->insertGetId([
            'template_id' => $templateId,
            'legacy_task_id' => $legacyTaskId,
            'status' => $status,
            'is_canceled' => false,
            'scheduled_date' => $date,
            'started_at' => $startedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLegacyTask(
        ?int $parentId = null,
        ?int $templateId = null,
        string $date = '2026-09-01',
        string $status = 'pending'
    ): int
    {
        return (int) DB::table('employee_tasks')->insertGetId([
            'parent_id' => $parentId,
            'template_id' => $templateId,
            'status' => $status,
            'is_canceled' => false,
            'start_time' => $date.' 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
