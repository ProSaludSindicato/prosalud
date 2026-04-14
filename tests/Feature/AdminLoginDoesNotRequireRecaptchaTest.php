<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminLoginDoesNotRequireRecaptchaTest extends TestCase
{
    public function test_admin_login_reaches_auth_controller_without_recaptcha_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'no-existe@example.com',
            'password' => 'wrong-password',
            'device_name' => 'PHPUnit',
        ]);

        $response->assertStatus(422);
        $response->assertJsonMissing(['error' => 'missing_recaptcha_token']);
    }
}
