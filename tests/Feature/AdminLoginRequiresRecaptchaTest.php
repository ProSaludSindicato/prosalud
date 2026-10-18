<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminLoginRequiresRecaptchaTest extends TestCase
{
    public function test_admin_login_rejects_request_without_recaptcha_token(): void
    {
        config([
            'services.recaptcha.enabled' => true,
            'services.recaptcha.site_key' => 'test-site-key',
            'services.recaptcha.secret_key' => 'test-secret-key',
            'services.recaptcha.project_id' => 'test-project',
        ]);

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
