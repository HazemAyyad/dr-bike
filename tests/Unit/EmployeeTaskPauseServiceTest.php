<?php

namespace Tests\Unit;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use App\Services\EmployeeTasks\EmployeeTaskListService;
use App\Services\EmployeeTasks\EmployeeTaskPauseService;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use App\Services\EmployeeTasks\EmployeeTaskWorkflowService;
use App\Support\EmployeeVisibleTasks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeTaskPauseServiceTest extends TestCase
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
            $table->boolean('is_active')->default(true);
            $table->boolean('is_paused')->default(false);
            $table->string('recurrence_type')->default('noRepeat');
            $table->timestamps();
        });
        foreach (['employee_tasks', 'employee_task_occurrences'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedBigInteger('template_id')->nullable();
                $table->unsignedBigInteger('legacy_task_id')->nullable();
                $table->unsignedBigInteger('employee_id')->default(1);
                $table->unsignedBigInteger('occurrence_id')->nullable();
                $table->boolean('is_paused')->default(false);
                $table->boolean('is_canceled')->default(false);
                $table->string('status')->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('start_time')->nullable();
                $table->string('task_recurrence')->default('noRepeat');
                $table->date('scheduled_date')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->softDeletes();
        });
        foreach (['sub_employee_tasks' => 'employee_task_id', 'employee_task_occurrence_subtasks' => 'occurrence_id'] as $tableName => $foreignKey) {
            Schema::create($tableName, function (Blueprint $table) use ($foreignKey) {
                $table->id();
                $table->unsignedBigInteger($foreignKey);
                $table->string('name')->nullable();
                $table->string('status')->default('pending');
                $table->integer('sort_order')->default(0);
            });
        }
    }

    public function test_pausing_a_child_hides_its_family_and_linked_occurrences_and_preserves_progress(): void
    {
        $root = $this->insert('employee_tasks');
        $child = $this->insert('employee_tasks', ['parent_id' => $root, 'status' => 'in_progress', 'started_at' => '2026-11-28 10:00:00']);
        $occurrence = $this->insert('employee_task_occurrences', ['legacy_task_id' => $child]);
        $unrelated = $this->insert('employee_tasks');
        $service = new EmployeeTaskPauseService;
        $service->setPaused($child, null, true);

        foreach ([$root, $child] as $id) {
            $this->assertDatabaseHas('employee_tasks', ['id' => $id, 'is_paused' => 1]);
        }
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $occurrence, 'is_paused' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $unrelated, 'is_paused' => 0]);
        $this->assertFalse(EmployeeVisibleTasks::isVisibleToEmployee(EmployeeTask::findOrFail($child)));

        $service->setPaused($root, null, false);
        $this->assertDatabaseHas('employee_tasks', ['id' => $child, 'is_paused' => 0, 'status' => 'in_progress', 'started_at' => '2026-11-28 10:00:00']);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $occurrence, 'is_paused' => 0]);
    }

    public function test_template_series_stops_generation_and_resumes_without_uncanceling_rows(): void
    {
        $template = $this->insert('employee_task_templates');
        $first = $this->insert('employee_task_occurrences', ['template_id' => $template]);
        $canceled = $this->insert('employee_task_occurrences', ['template_id' => $template, 'is_canceled' => true]);
        $legacy = $this->insert('employee_tasks', ['template_id' => $template]);
        $service = new EmployeeTaskPauseService;
        $service->setPaused(null, $first, true);
        $this->assertDatabaseHas('employee_task_templates', ['id' => $template, 'is_paused' => 1, 'is_active' => 1]);
        $this->assertDatabaseHas('employee_tasks', ['id' => $legacy, 'is_paused' => 1]);
        $this->assertFalse(EmployeeVisibleTasks::isOccurrenceVisibleToEmployee(EmployeeTaskOccurrence::findOrFail($first)));
        $recurrence = new EmployeeTaskRecurrenceService($this->createStub(EmployeeTaskTimelineService::class));
        $this->assertCount(0, $recurrence->ensureOccurrences(EmployeeTaskTemplate::findOrFail($template)));

        $service->setPaused(null, $first, false);
        $this->assertDatabaseHas('employee_task_templates', ['id' => $template, 'is_paused' => 0, 'is_active' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $canceled, 'is_paused' => 0, 'is_canceled' => 1]);
    }

    public function test_standalone_occurrence_does_not_pause_other_null_template_rows(): void
    {
        $target = $this->insert('employee_task_occurrences');
        $other = $this->insert('employee_task_occurrences');
        (new EmployeeTaskPauseService)->setPaused(null, $target, true);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $target, 'is_paused' => 1]);
        $this->assertDatabaseHas('employee_task_occurrences', ['id' => $other, 'is_paused' => 0]);
    }

    public function test_paused_tasks_cannot_be_accessed_as_an_assignee_or_started_or_submitted(): void
    {
        $task = EmployeeTask::findOrFail($this->insert('employee_tasks', ['is_paused' => true]));
        $occurrence = EmployeeTaskOccurrence::findOrFail($this->insert('employee_task_occurrences', ['is_paused' => true]));
        $assignees = new EmployeeTaskAssigneeService;
        $this->assertFalse($assignees->isAssignee($task, 1));
        $this->assertFalse($assignees->canAccessOccurrence($occurrence, 1));
        $workflow = new EmployeeTaskWorkflowService($this->createStub(EmployeeTaskTimelineService::class));
        foreach ([['startTask', $task], ['submitTaskForReview', $task], ['startOccurrence', $occurrence], ['submitOccurrenceForReview', $occurrence]] as [$method, $model]) {
            try {
                $workflow->$method($model);
                $this->fail('Paused tasks must not progress.');
            } catch (\RuntimeException $e) {
                $this->assertSame(__('messages.employee_task_paused'), $e->getMessage());
            }
        }
    }

    public function test_pause_endpoint_requires_task_management_and_edit_permissions(): void
    {
        $route = app('router')->getRoutes()->getByAction(\App\Http\Controllers\API\EmployeeTaskPauseController::class.'@update');
        $this->assertNotNull($route);
        $this->assertContains('check.permission:Employee Tasks', $route->gatherMiddleware());
        $this->assertContains('check.permission:Edit Employee Task', $route->gatherMiddleware());
    }

    public function test_paused_list_keeps_one_entry_per_family_and_ongoing_excludes_paused_work(): void
    {
        $template = $this->insert('employee_task_templates', ['is_paused' => true]);
        $root = $this->insert('employee_tasks', ['template_id' => $template, 'is_paused' => true]);
        $this->insert('employee_tasks', ['parent_id' => $root, 'is_paused' => true]);
        $this->insert('employee_task_occurrences', ['template_id' => $template, 'legacy_task_id' => $root, 'is_paused' => true]);
        $this->insert('employee_task_occurrences', ['template_id' => $template, 'is_paused' => true]);
        $active = $this->insert('employee_tasks');
        $list = new class extends EmployeeTaskListService
        {
            public function formatLegacyTask(EmployeeTask $task, callable $photoResolver): array
            {
                return ['task_id' => $task->id];
            }

            public function formatOccurrence(EmployeeTaskOccurrence $task, callable $photoResolver): array
            {
                return ['occurrence_id' => $task->id];
            }
        };
        $this->assertSame([['task_id' => $root]], $list->getPausedItems(fn () => '')->all());
        $this->assertSame([['task_id' => $active]], $list->getOngoingItems(fn () => '')->all());
    }

    private function insert(string $table, array $attributes = []): int
    {
        return DB::table($table)->insertGetId($attributes + ['created_at' => now(), 'updated_at' => now()]);
    }
}
