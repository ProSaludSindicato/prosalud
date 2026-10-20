<?php

namespace Tests\Feature;

use App\Services\AfiliadoService;
use App\Services\RecaptchaService;
use App\Services\VoterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class AffiliateDataUpdateSessionTokenTest extends TestCase
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

    private function mockAfiliadoService(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('isFileAvailable')->andReturn(true);
        $afiliadoMock->shouldReceive('authenticateAndGetAfiliadoDetailed')
            ->with('CC', '1234567890', '2010-01-01')
            ->andReturn(['status' => 'success']);
        $afiliadoMock->shouldReceive('getCompleteAfiliadoInfo')
            ->with('CC', '1234567890', '2010-01-01')
            ->andReturn([
                'afiliado' => [
                    'tipo_documento' => 'CC',
                    'documento' => '1234567890',
                    'nombres' => 'Test',
                    'apellidos' => 'User',
                    'correo_personal' => 'test@example.com',
                ],
                'convenios' => [],
                'beneficiarios' => [],
            ]);

        $this->app->instance(AfiliadoService::class, $afiliadoMock);
    }

    public function test_authenticate_for_data_update_issues_affiliate_token_for_the_document(): void
    {
        $this->mockAfiliadoService();

        $response = $this->postJson('/api/afiliados/authenticate-for-data-update', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $session = app(VoterSessionService::class)->verify(
            $response->json('affiliate_token'),
            app(VoterSessionService::class)->affiliateDataUpdateScope()
        );

        $this->assertNotNull($session);
        $this->assertSame('1234567890', $session['document_number']);
    }

    public function test_token_from_authenticate_for_data_update_allows_submitting_the_data_update_request(): void
    {
        $this->mockAfiliadoService();

        $token = $this->postJson('/api/afiliados/authenticate-for-data-update', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->json('affiliate_token');

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
        ], [VoterSessionService::HEADER => $token, 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('request_forms', ['document_number' => '1234567890']);
    }

    public function test_failed_authentication_does_not_issue_affiliate_token(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('isFileAvailable')->andReturn(true);
        $afiliadoMock->shouldReceive('authenticateAndGetAfiliadoDetailed')->andReturn(['status' => 'affiliate_data_mismatch']);
        $this->app->instance(AfiliadoService::class, $afiliadoMock);

        $this->postJson('/api/afiliados/authenticate-for-data-update', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '1999-01-01',
        ])
            ->assertUnauthorized()
            ->assertJsonMissingPath('affiliate_token');
    }
}
