<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminLoginRequiresRecaptchaTest extends TestCase
{
    public function test_admin_login_rejects_request_without_recaptcha_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
            'device_name' => 'PHPUnit',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'missing_recaptcha_token',
        ]);
    }
}
