<?php

namespace Tests\Feature;

use App\Models\RequestForm;
use App\Services\RecaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreRequestFormColpensionesAfpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->mock(RecaptchaService::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn(['success' => true]);
        });
    }

    public function test_actualizar_datos_personales_accepts_colpensiones_afp_with_certificate(): void
    {
        $file = UploadedFile::fake()->create('certificado-afp.pdf', 100, 'application/pdf');

        $response = $this->post('/api/requests', [
            'request_type' => 'actualizar-datos-personales',
            'id_type' => 'CC',
            'id_number' => '1234567890',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '3001234567',
            'recaptcha_token' => 'test-token',
            'payload' => [
                'proceso' => 'Área administrativa',
                'dondeRealizaProceso' => 'Bogotá',
                'afp' => 'colpensiones',
            ],
            'files' => [
                'certificadoAfp' => $file,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('request_forms', [
            'document_number' => '1234567890',
        ]);

        $requestForm = RequestForm::query()
            ->where('document_number', '1234567890')
            ->first();

        $this->assertNotNull($requestForm);
        $this->assertSame('colpensiones', $requestForm->payload['afp'] ?? null);
    }

    public function test_actualizar_datos_personales_accepts_colpensiones_label_afp_with_certificate(): void
    {
        $file = UploadedFile::fake()->create('certificado-afp.pdf', 100, 'application/pdf');

        $response = $this->post('/api/requests', [
            'request_type' => 'actualizar-datos-personales',
            'id_type' => 'CC',
            'id_number' => '1234567891',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test2@example.com',
            'phone_number' => '3001234568',
            'recaptcha_token' => 'test-token',
            'payload' => [
                'proceso' => 'Área administrativa',
                'dondeRealizaProceso' => 'Bogotá',
                'afp' => 'Colpensiones',
            ],
            'files' => [
                'certificadoAfp' => $file,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $requestForm = RequestForm::query()
            ->where('document_number', '1234567891')
            ->first();

        $this->assertNotNull($requestForm);
        $this->assertSame('Colpensiones', $requestForm->payload['afp'] ?? null);
    }
}
