<?php

namespace Tests\Unit;

use App\Http\Controllers\AdminNotificationWebController;
use App\Http\Controllers\EmployeeNotificationWebController;
use App\Http\Controllers\SmsTestWebController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NotificationTestWebAuthorizationTest extends TestCase
{
    /** @dataProvider protectedControllers */
    public function test_test_pages_fail_closed_in_production_without_a_token(
        string $controllerClass
    ): void {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';
        config()->set('services.notification_test_web.admin_token', null);
        config()->set('services.notification_test_web.employee_token', null);

        $method = new ReflectionMethod($controllerClass, 'authorizeRequest');
        $method->setAccessible(true);

        try {
            $method->invoke(new $controllerClass(), Request::create('/test', 'GET'));
            $this->fail("{$controllerClass} allowed an unprotected production request.");
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public static function protectedControllers(): array
    {
        return [
            [AdminNotificationWebController::class],
            [EmployeeNotificationWebController::class],
            [SmsTestWebController::class],
        ];
    }
}
