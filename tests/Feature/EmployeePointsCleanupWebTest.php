<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeePointsCleanupWebTest extends TestCase
{
    private string $originalConnection;

    private int $employeeOneFirstLogId;

    private int $employeeTwoLogId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'employee_points_web_test',
            'database.connections.employee_points_web_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('employee_points_web_test');
        DB::setDefaultConnection('employee_points_web_test');
        Storage::fake('public');

        $this->createTables();
        $this->seedEmployeesAndPointArtifacts();
    }

    protected function tearDown(): void
    {
        DB::purge('employee_points_web_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_page_requires_security_center_login(): void
    {
        $this->get('/security-center/employee-points')
            ->assertRedirect('/security-center/login');
    }

    public function test_authenticated_operator_can_see_history_counts_and_balances(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->get('/security-center/employee-points')
            ->assertOk()
            ->assertSee('تنظيف نقاط الموظفين')
            ->assertSee('هذا حذف نهائي وليس تصفيرًا')
            ->assertSee('أحمد')
            ->assertSee('+10')
            ->assertSee('سارة')
            ->assertSee('-7');
    }

    public function test_operator_can_permanently_delete_one_employees_point_artifacts(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->delete('/security-center/employee-points/10', ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseMissing('employee_points_logs', ['employee_id' => 10]);
        $this->assertDatabaseHas('employee_points_logs', ['employee_id' => 20]);
        $this->assertDatabaseHas('employee_point_rule_executions', [
            'id' => 1,
            'points_log_id' => null,
        ]);
        $this->assertDatabaseHas('employee_point_rule_executions', [
            'id' => 2,
            'points_log_id' => $this->employeeTwoLogId,
        ]);
        $this->assertDatabaseMissing('employee_notifications', [
            'employee_id' => 10,
            'type' => 'employee_points_changed',
        ]);
        $this->assertDatabaseHas('employee_notifications', [
            'employee_id' => 10,
            'type' => 'unrelated',
        ]);
        $this->assertDatabaseMissing('admin_notifications', [
            'employee_id' => 10,
            'type' => 'employee_reward_earned',
        ]);
        $this->assertDatabaseMissing('employee_activity_logs', [
            'employee_id' => 10,
            'module' => 'employee_points',
        ]);
        $this->assertDatabaseHas('employee_activity_logs', [
            'employee_id' => 10,
            'module' => 'attendance',
        ]);
        Storage::disk('public')->assertMissing('employee-points/10/proof.jpg');
        Storage::disk('public')->assertExists('employee-points/20/proof.jpg');
    }

    public function test_delete_all_requires_the_exact_confirmation_phrase(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->from('/security-center/employee-points')
            ->delete('/security-center/employee-points', ['confirmation' => 'نعم'])
            ->assertRedirect('/security-center/employee-points')
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('employee_points_logs', 3);
        Storage::disk('public')->assertExists('employee-points/10/proof.jpg');
    }

    public function test_operator_can_permanently_delete_all_employees_point_artifacts(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->delete('/security-center/employee-points', [
                'confirmation' => 'حذف جميع النقاط نهائيا',
            ])
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseCount('employee_points_logs', 0);
        $this->assertSame(0, DB::table('employee_point_rule_executions')->whereNotNull('points_log_id')->count());
        $this->assertSame(0, DB::table('employee_notifications')->whereIn('type', [
            'employee_points_changed',
            'employee_reward_earned',
        ])->count());
        $this->assertSame(0, DB::table('admin_notifications')->whereIn('type', [
            'employee_points_changed',
            'employee_reward_earned',
        ])->count());
        $this->assertSame(0, DB::table('employee_activity_logs')->where('module', 'employee_points')->count());
        $this->assertDatabaseHas('employee_notifications', ['type' => 'unrelated']);
        $this->assertDatabaseHas('employee_activity_logs', ['module' => 'attendance']);
        Storage::disk('public')->assertMissing('employee-points/10/proof.jpg');
        Storage::disk('public')->assertMissing('employee-points/20/proof.jpg');
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('job_title')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('employee_points_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_details');
            $table->integer('points');
            $table->string('operation_type');
            $table->string('category');
            $table->string('source');
            $table->text('image_path')->nullable();
            $table->date('points_date');
            $table->timestamps();
        });
        Schema::create('employee_point_rule_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('points_log_id')->nullable();
        });
        Schema::create('employee_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('type');
        });
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('type');
        });
        Schema::create('employee_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('module');
        });
    }

    private function seedEmployeesAndPointArtifacts(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'أحمد', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'سارة', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'ليان', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('employee_details')->insert([
            ['id' => 10, 'user_id' => 1, 'job_title' => 'مبيعات', 'is_suspended' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'user_id' => 2, 'job_title' => 'صيانة', 'is_suspended' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 30, 'user_id' => 3, 'job_title' => 'إدارة', 'is_suspended' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Storage::disk('public')->put('employee-points/10/proof.jpg', 'employee-one-proof');
        Storage::disk('public')->put('employee-points/20/proof.jpg', 'employee-two-proof');
        $this->employeeOneFirstLogId = DB::table('employee_points_logs')->insertGetId(
            $this->pointsLog(10, 12, 'add', 'employee-points/10/proof.jpg')
        );
        DB::table('employee_points_logs')->insert($this->pointsLog(10, 2, 'deduct'));
        $this->employeeTwoLogId = DB::table('employee_points_logs')->insertGetId(
            $this->pointsLog(20, 7, 'deduct', 'employee-points/20/proof.jpg')
        );

        DB::table('employee_point_rule_executions')->insert([
            ['id' => 1, 'points_log_id' => $this->employeeOneFirstLogId],
            ['id' => 2, 'points_log_id' => $this->employeeTwoLogId],
        ]);
        DB::table('employee_notifications')->insert([
            ['employee_id' => 10, 'type' => 'employee_points_changed'],
            ['employee_id' => 10, 'type' => 'unrelated'],
            ['employee_id' => 20, 'type' => 'employee_reward_earned'],
        ]);
        DB::table('admin_notifications')->insert([
            ['employee_id' => 10, 'type' => 'employee_reward_earned'],
            ['employee_id' => 20, 'type' => 'employee_points_changed'],
        ]);
        DB::table('employee_activity_logs')->insert([
            ['employee_id' => 10, 'module' => 'employee_points'],
            ['employee_id' => 10, 'module' => 'attendance'],
            ['employee_id' => 20, 'module' => 'employee_points'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pointsLog(int $employeeId, int $points, string $operation, ?string $imagePath = null): array
    {
        return [
            'employee_id' => $employeeId,
            'points' => $points,
            'operation_type' => $operation,
            'category' => 'manual',
            'source' => 'manual',
            'image_path' => $imagePath,
            'points_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
