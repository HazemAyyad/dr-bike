<?php

namespace Tests\Unit;

use Illuminate\Routing\Route;
use Tests\TestCase;

class FinancialAffairsPermissionRoutesTest extends TestCase
{
    /** @dataProvider protectedRouteProvider */
    public function test_financial_route_uses_the_expected_detailed_permission(
        string $method,
        string $uri,
        string $permission
    ): void {
        $route = $this->route($method, $uri);
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains("check.permission:{$permission}", $middleware);
        $this->assertNotContains('check.permission:Expenses and Financial Affairs', $middleware);
    }

    public static function protectedRouteProvider(): array
    {
        return [
            ['GET', 'api/get/all/expenses', 'Financial Expenses View'],
            ['POST', 'api/store/expense', 'Financial Expenses Create'],
            ['POST', 'api/edit/expense', 'Financial Expenses Edit'],
            ['GET', 'api/expenses/report', 'Financial Expenses Reports'],
            ['GET', 'api/get/all/destructions', 'Financial Destructions View'],
            ['POST', 'api/store/destruction', 'Financial Destructions Manage'],
            ['GET', 'api/get/all/assets', 'Financial Assets View'],
            ['POST', 'api/add/asset', 'Financial Assets Manage'],
            ['POST', 'api/delete/asset', 'Financial Assets Delete'],
            ['GET', 'api/depreciate/all/assets', 'Financial Assets Depreciate'],
            ['GET', 'api/get/all/asset/logs/report', 'Financial Assets Reports'],
            ['GET', 'api/get/all/papers', 'Financial Official Papers View'],
            ['POST', 'api/store/paper', 'Financial Official Papers Manage'],
            ['POST', 'api/cancel/paper', 'Financial Official Papers Delete'],
        ];
    }

    private function route(string $method, string $uri): Route
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        $this->assertInstanceOf(Route::class, $route, "Route {$method} {$uri} was not registered.");

        return $route;
    }
}
