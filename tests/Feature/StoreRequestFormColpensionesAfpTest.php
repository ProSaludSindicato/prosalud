<?php

namespace Tests\Feature;

use App\Models\RequestForm;
use App\Services\RecaptchaService;
use App\Services\VoterSessionService;
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
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('verify')->andReturn(['success' => true]);
        });
    }

    /**
     * @return array<string, string>
     */
    private function affiliateHeaders(string $document): array
    {
        $service = app(VoterSessionService::class);

        return [VoterSessionService::HEADER => $service->issue('CC', $document, '', $service->affiliateDataUpdateScope())];
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
        ], $this->affiliateHeaders('1234567890'));

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
        ], $this->affiliateHeaders('1234567891'));

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $requestForm = RequestForm::query()
            ->where('document_number', '1234567891')
            ->first();

        $this->assertNotNull($requestForm);
        $this->assertSame('Colpensiones', $requestForm->payload['afp'] ?? null);
    }

    public function test_actualizar_datos_personales_requires_affiliate_session(): void
    {
        $this->post('/api/requests', [
            'request_type' => 'actualizar-datos-personales',
            'id_type' => 'CC',
            'id_number' => '1234567890',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '3001234567',
            'recaptcha_token' => 'test-token',
            'payload' => ['proceso' => 'Área administrativa', 'dondeRealizaProceso' => 'Bogotá', 'afp' => 'colpensiones'],
            'files' => ['certificadoAfp' => UploadedFile::fake()->create('certificado-afp.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'AFFILIATE_SESSION_INVALID');

        $this->assertDatabaseMissing('request_forms', ['document_number' => '1234567890']);
    }

    public function test_actualizar_datos_personales_rejects_session_of_another_document(): void
    {
        $this->post('/api/requests', [
            'request_type' => 'actualizar-datos-personales',
            'id_type' => 'CC',
            'id_number' => '9999999999',
            'name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '3001234567',
            'recaptcha_token' => 'test-token',
            'payload' => ['proceso' => 'Área administrativa', 'dondeRealizaProceso' => 'Bogotá', 'afp' => 'colpensiones'],
            'files' => ['certificadoAfp' => UploadedFile::fake()->create('certificado-afp.pdf', 100, 'application/pdf')],
        ], $this->affiliateHeaders('1234567890') + ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'AFFILIATE_SESSION_MISMATCH');

        $this->assertDatabaseMissing('request_forms', ['document_number' => '9999999999']);
    }
}
