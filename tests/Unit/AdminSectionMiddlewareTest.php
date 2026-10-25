<?php

namespace Tests\Unit;

use App\Http\Controllers\API\AdminSectionPermissionsController;
use App\Http\Middleware\EnforceAdminSections;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSectionMiddlewareTest extends TestCase
{
    public function test_gate_runs_after_authentication_on_both_shared_and_admin_api_routes(): void
    {
        $this->app->make(Kernel::class);
        foreach (['api/get/products/list', 'api/admin/users', 'api/admin/users/{id}/sections', 'api/me'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(fn (Route $route) => $route->uri() === $uri);
            $this->assertInstanceOf(Route::class, $route);
            $middleware = app('router')->gatherRouteMiddleware($route);
            $auth = array_search('App\\Http\\Middleware\\Authenticate:sanctum', $middleware, true);
            $gate = array_search(EnforceAdminSections::class, $middleware, true);
            $this->assertNotFalse($auth);
            $this->assertNotFalse($gate);
            $this->assertLessThan($gate, $auth);
        }
    }

    public function test_denied_api_request_returns_403_without_querying_a_database(): void
    {
        DB::shouldReceive('connection')->never();
        $request = Request::create('/api/admin/users');
        $request->setUserResolver(fn () => (new User)->forceFill(['type' => 'admin', 'admin_sections' => []]));
        $request->setRouteResolver(fn () => new Route('GET', 'api/admin/users', ['uses' => 'App\\Http\\Controllers\\API\\AdminUsersController@index', 'controller' => 'App\\Http\\Controllers\\API\\AdminUsersController@index']));
        $response = (new EnforceAdminSections)->handle($request, fn () => $this->fail('Denied request reached the controller.'));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_manager_cannot_remove_their_own_access(): void
    {
        DB::shouldReceive('connection')->never();
        $request = Request::create('/api/admin/users/20/sections', 'PUT');
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 20, 'type' => 'admin', 'admin_sections' => null]));
        try {
            (new AdminSectionPermissionsController)->update($request, 20);
            $this->fail('Self permission update was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }
}
