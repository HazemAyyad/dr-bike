<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SecurityCenterWebTest extends TestCase
{
    public function test_security_center_redirects_guests_to_login(): void
    {
        $this->get('/security-center')
            ->assertRedirect('/security-center/login');
    }

    public function test_security_center_login_rejects_wrong_token(): void
    {
        Config::set('security_center.web_token', 'correct-secret');

        $this->from('/security-center/login')
            ->post('/security-center/login', ['token' => 'wrong-secret'])
            ->assertRedirect('/security-center/login')
            ->assertSessionHasErrors('token');
    }

    public function test_security_center_login_opens_protected_page(): void
    {
        Config::set('security_center.web_token', 'correct-secret');

        $this->post('/security-center/login', ['token' => 'correct-secret'])
            ->assertRedirect('/security-center')
            ->assertSessionHas('security_center_authenticated', true);

        $this->get('/security-center')
            ->assertOk()
            ->assertSee('مركز أمان Doctor Bike');
    }
}
