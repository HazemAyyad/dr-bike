<?php

namespace Tests\Feature;

use App\Http\Controllers\API\SpecialTasks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecialTaskCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('special_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('ongoing');
            $table->timestamps();
        });

        Schema::create('sub_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('special_task_id');
            $table->string('name');
            $table->string('status')->default('ongoing');
            $table->timestamps();
        });

        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('type');
            $table->timestamps();
        });
    }

    public function test_completing_special_task_completes_its_open_subtasks(): void
    {
        $taskId = DB::table('special_tasks')->insertGetId([
            'name' => 'Private task',
            'status' => 'ongoing',
        ]);

        foreach (['ongoing', 'pending', 'completed', 'canceled', 'rejected'] as $status) {
            DB::table('sub_tasks')->insert([
                'special_task_id' => $taskId,
                'name' => $status,
                'status' => $status,
            ]);
        }

        $response = app(SpecialTasks::class)->changeSpecialTaskToCompleted(
            Request::create('/change/special/task/to/completed', 'POST', [
                'special_task_id' => $taskId,
            ]),
        );

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertDatabaseHas('special_tasks', ['id' => $taskId, 'status' => 'completed']);
        $this->assertDatabaseHas('sub_tasks', ['name' => 'ongoing', 'status' => 'completed']);
        $this->assertDatabaseHas('sub_tasks', ['name' => 'pending', 'status' => 'completed']);
        $this->assertDatabaseHas('sub_tasks', ['name' => 'canceled', 'status' => 'canceled']);
        $this->assertDatabaseHas('sub_tasks', ['name' => 'rejected', 'status' => 'rejected']);
    }
}
