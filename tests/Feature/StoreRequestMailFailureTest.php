<?php

namespace Tests\Feature;

use App\Jobs\ProcessCertificadoConvenioJob;
use App\Services\RecaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreRequestMailFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_is_still_accepted_when_the_confirmation_email_fails(): void
    {
        $this->mock(RecaptchaService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')->andReturn(['success' => true]);
        });

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('cc')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/requests', [
            'request_type' => 'certificado-seguridad-social',
            'id_type' => 'CC',
            'id_number' => '1234567890',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '3001234567',
            'recaptcha_token' => 'test-token',
            'payload' => [],
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->assertDatabaseHas('request_forms', ['document_number' => '1234567890']);
    }

    public function test_simple_convenio_certificate_is_pushed_to_the_queue_not_run_after_the_response(): void
    {
        Mail::fake();
        Bus::fake();

        $this->mock(RecaptchaService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')->andReturn(['success' => true]);
        });

        $this->postJson('/api/requests', [
            'request_type' => 'certificado-convenio',
            'id_type' => 'CC',
            'id_number' => '1234567890',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '3001234567',
            'recaptcha_token' => 'test-token',
            'payload' => [
                'proceso' => 'Proceso',
                'dondeRealizaProceso' => 'Hospital',
                'infoCertificado' => ['fechaIngresoRetiro' => true],
            ],
        ])->assertStatus(201);

        Bus::assertDispatched(ProcessCertificadoConvenioJob::class);
        Bus::assertNotDispatchedAfterResponse(ProcessCertificadoConvenioJob::class);
    }
}
