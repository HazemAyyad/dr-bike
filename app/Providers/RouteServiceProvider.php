<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->group(base_path('routes/api_store.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($this->isHighTrafficAdminApi($request)) {
                return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function isHighTrafficAdminApi(Request $request): bool
    {
        return $request->is(
            'api/all/instant/sales',
            'api/show/instant/sale',
            'api/create/instant/sale',
            'api/edit/instant/sale',
            'api/cancel/instant/sale',
            'api/get/instant/sale/invoice',
            'api/instant/sale/*',
            'api/suspended/instant/sale*',
            'api/suspended/instant/sales*',
            'api/all/profit/sales',
            'api/show/profit/sale',
            'api/create/profit/sale',
            'api/edit/profit/sale',
            'api/cancel/profit/sale',
            'api/sales/*',
            'api/offer/packages*',
            'api/all/products',
            'api/products/*',
            'api/product/*',
            'api/get/products/list',
            'api/get/deleted/products',
            'api/get/product/*',
            'api/get/all/categories',
            'api/get/all/subcategories',
            'api/stock/*',
            'api/store/sections*'
        );
    }
}
