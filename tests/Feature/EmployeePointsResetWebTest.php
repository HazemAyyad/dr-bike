<?php

namespace Tests\Feature;

use App\Services\EmployeePointsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeePointsResetWebTest extends TestCase
{
    private string $originalConnection;

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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fcm_token')->nullable();
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
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('source');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->date('points_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

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
        DB::table('employee_points_logs')->insert([
            $this->pointsLog(10, 12, 'add'),
            $this->pointsLog(10, 2, 'deduct'),
            $this->pointsLog(20, 7, 'deduct'),
        ]);
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

    public function test_authenticated_operator_can_see_employee_balances(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->get('/security-center/employee-points')
            ->assertOk()
            ->assertSee('تصفير نقاط الموظفين')
            ->assertSee('أحمد')
            ->assertSee('+10')
            ->assertSee('سارة')
            ->assertSee('-7');
    }

    public function test_operator_can_reset_one_employee_without_deleting_history(): void
    {
        $response = $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/employee-points/10/reset', ['confirmed' => '1']);

        $response->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertSame(0, app(EmployeePointsService::class)->getTotalNetPoints(10));
        $this->assertDatabaseCount('employee_points_logs', 4);
        $this->assertDatabaseHas('employee_points_logs', [
            'employee_id' => 10,
            'points' => 10,
            'operation_type' => 'deduct',
            'category' => 'security_center_reset',
            'source' => 'manual',
        ]);
    }

    public function test_reset_all_requires_the_exact_confirmation_phrase(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->from('/security-center/employee-points')
            ->post('/security-center/employee-points/reset-all', ['confirmation' => 'نعم'])
            ->assertRedirect('/security-center/employee-points')
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(10, app(EmployeePointsService::class)->getTotalNetPoints(10));
        $this->assertSame(-7, app(EmployeePointsService::class)->getTotalNetPoints(20));
    }

    public function test_operator_can_reset_all_positive_and_negative_balances(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/employee-points/reset-all', ['confirmation' => 'تصفير الجميع'])
            ->assertRedirect()
            ->assertSessionHas('flash');

        $pointsService = app(EmployeePointsService::class);
        $this->assertSame(0, $pointsService->getTotalNetPoints(10));
        $this->assertSame(0, $pointsService->getTotalNetPoints(20));
        $this->assertSame(0, $pointsService->getTotalNetPoints(30));
        $this->assertDatabaseCount('employee_points_logs', 5);
        $this->assertDatabaseHas('employee_points_logs', [
            'employee_id' => 20,
            'points' => 7,
            'operation_type' => 'add',
            'category' => 'security_center_reset',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pointsLog(int $employeeId, int $points, string $operation): array
    {
        return [
            'employee_id' => $employeeId,
            'points' => $points,
            'operation_type' => $operation,
            'category' => 'manual',
            'source' => 'manual',
            'reason' => 'اختبار',
            'points_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
