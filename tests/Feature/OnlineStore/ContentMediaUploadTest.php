<?php

namespace Tests\Feature\OnlineStore;

use App\Http\Controllers\API\OnlineStore\ContentMediaController;
use App\Http\Controllers\API\OnlineStore\StorefrontContentController;
use App\Http\Controllers\API\Pictures;
use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class ContentMediaUploadTest extends TestCase
{
    private string $originalPublicPath;

    private string $testPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPublicPath = public_path();
        $this->testPublicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doctor-bike-online-store-'.Str::uuid();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);

        File::ensureDirectoryExists($this->testPublicPath);
        app()->usePublicPath($this->testPublicPath);
    }

    protected function tearDown(): void
    {
        app()->usePublicPath($this->originalPublicPath);
        File::deleteDirectory($this->testPublicPath);
        parent::tearDown();
    }

    public function test_admin_uploads_a_valid_image_to_a_fake_public_directory_with_a_server_generated_name(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();

        $response = $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->image('client-banner-name.png', 1200, 600)->size(100),
        ], ['Accept' => 'application/json'])->assertCreated();

        $imagePath = $response->json('data.image_path');
        $response->assertExactJson(['data' => ['image_path' => $imagePath]]);
        $this->assertMatchesRegularExpression(
            '#^public/OnlineStore/Content/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$#',
            $imagePath,
        );
        $this->assertStringNotContainsString('client-banner-name', $imagePath);
        $this->assertStringNotContainsString($this->testPublicPath, $imagePath);
        $this->assertStringNotContainsString('\\', $imagePath);
        $this->assertFileExists($this->testPublicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, Str::after($imagePath, 'public/')));
    }

    public function test_employee_requires_the_exact_content_manage_permission(): void
    {
        $this->authenticateEmployee('Online Store View');
        $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->image('denied.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->authenticateEmployee('Online Store Content Manage');
        $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->image('allowed.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_missing_video_and_document_uploads_are_rejected(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();

        $this->post('/api/online-store/content-images', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDirectoryDoesNotExist($this->testPublicPath.DIRECTORY_SEPARATOR.'OnlineStore'.DIRECTORY_SEPARATOR.'Content');
    }

    public function test_returned_path_is_accepted_by_banner_create_and_update(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $imagePath = $this->post('/api/online-store/content-images', [
            'file' => UploadedFile::fake()->image('banner.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.image_path');

        $banner = $this->postJson('/api/online-store/banners', [
            'image_path' => $imagePath,
            'action_type' => 'none',
        ])->assertCreated()->assertJsonPath('data.image_path', $imagePath)->json('data');

        $this->patchJson('/api/online-store/banners/'.$banner['id'], [
            'image_path' => $imagePath,
        ])->assertOk()->assertJsonPath('data.image_path', $imagePath);
    }

    public function test_content_upload_is_additive_to_existing_banner_and_pictures_routes(): void
    {
        $upload = $this->route('POST', 'api/online-store/content-images');
        $banner = $this->route('POST', 'api/online-store/banners');
        $pictures = $this->route('POST', 'api/store/picture');

        $this->assertSame(ContentMediaController::class.'@store', $upload->getActionName());
        $this->assertContains('auth:sanctum', $upload->gatherMiddleware());
        $this->assertContains('refresh.token.expiry', $upload->gatherMiddleware());
        $this->assertSame(StorefrontContentController::class.'@storeBanner', $banner->getActionName());
        $this->assertSame(Pictures::class.'@store', $pictures->getActionName());
        $this->assertContains('check.permission:Financial Official Papers Manage', $pictures->gatherMiddleware());
    }

    private function authenticateEmployee(?string $permission = null): User
    {
        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        if ($permission !== null) {
            $details = EmployeeDetail::query()->create(['user_id' => $employee->id]);
            $permissionModel = Permission::query()->firstOrCreate(
                ['name_en' => $permission],
                ['name' => $permission],
            );
            EmployeePermission::query()->create([
                'employee_id' => $details->id,
                'permission_id' => $permissionModel->id,
            ]);
        }
        Sanctum::actingAs($employee);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        return $employee;
    }

    private function route(string $method, string $uri): Route
    {
        $route = collect(RouteFacade::getRoutes()->getRoutes())->first(
            fn (Route $candidate) => $candidate->uri() === $uri && in_array($method, $candidate->methods(), true),
        );

        $this->assertInstanceOf(Route::class, $route);

        return $route;
    }
}
