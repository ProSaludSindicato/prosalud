<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyRecaptcha;
use App\Rules\RecaptchaRule;
use App\Services\RecaptchaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RecaptchaDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.recaptcha.enabled' => false,
            'services.recaptcha.site_key' => 'test-site-key',
            'services.recaptcha.secret_key' => 'test-secret-key',
            'services.recaptcha.project_id' => 'test-project',
        ]);
    }

    public function test_recaptcha_service_skips_google_verification_when_disabled(): void
    {
        Http::fake();

        $service = app(RecaptchaService::class);
        $result = $service->verify(null);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['disabled'] ?? false);
        Http::assertNothingSent();
    }

    public function test_recaptcha_rule_passes_without_token_when_disabled(): void
    {
        $rule = new RecaptchaRule;

        $this->assertTrue($rule->passes('recaptcha_token', null));
    }

    public function test_verify_recaptcha_middleware_allows_request_without_token_when_disabled(): void
    {
        Route::middleware(VerifyRecaptcha::class.':test_action')
            ->post('/testing/recaptcha-disabled', fn () => response()->json(['ok' => true]));

        $response = $this->postJson('/testing/recaptcha-disabled');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_recaptcha_service_requires_token_when_enabled(): void
    {
        config(['services.recaptcha.enabled' => true]);

        $service = app(RecaptchaService::class);
        $result = $service->verify(null);

        $this->assertFalse($result['success']);
        $this->assertContains('missing-input-response', $result['error_codes']);
    }
}
