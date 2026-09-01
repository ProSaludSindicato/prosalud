<?php

namespace Tests\Feature;

use App\Jobs\GenerateConvenioJob;
use App\Jobs\SendConvenioManualEmailJob;
use App\Mail\ConvenioManualNotification;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\AfiliadoService;
use App\Services\ConvenioGenerationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ConvenioDeliveryModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config(['convenio_signing.enabled' => false]);
    }

    private function apiCookieForUser(User $user): string
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function convenioPayload(array $overrides = []): array
    {
        return array_merge([
            'numero_documento' => '1234567890',
            'apellidos' => 'Perez',
            'nombres' => 'Juan',
            'fecha_nacimiento' => '1990-01-01',
            'lugar_nacimiento' => 'Medellin',
            'proceso' => 'TEST CONVENIO',
            'ciudad' => 'Medellin',
            'sede' => 'BELLO',
            'fecha_inicio' => '2026-01-01',
            'direccion' => 'Calle 1',
            'celular' => '3001234567',
            'compensacion_basica_redactada' => 'Compensacion basica de prueba para el convenio.',
        ], $overrides);
    }

    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin-verificacion@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
    }

    public function test_sync_download_returns_pdf(): void
    {
        config(['convenios.delivery_mode' => 'production']);

        [$user, $token] = $this->authenticatedManageUser();

        $pdfPath = storage_path('app/temp/convenios/test-sync.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 sync test');

        $this->mock(ConvenioGenerationService::class, function ($mock) use ($pdfPath): void {
            $mock->shouldReceive('generarConvenio')
                ->once()
                ->andReturn([
                    'ruta' => storage_path('app/temp/convenios/test-sync.docx'),
                    'nombre' => 'Convenio_1234567890_Perez_Juan.docx',
                    'tipo' => 'docx',
                ]);
            $mock->shouldReceive('finalizeConvenioPdf')
                ->once()
                ->andReturn([
                    'ruta' => $pdfPath,
                    'nombre' => 'Convenio_1234567890_Perez_Juan.pdf',
                    'tipo' => 'pdf',
                ]);
        });

        $response = $this->call(
            'POST',
            '/api/convenios-manual/generate-and-send',
            $this->convenioPayload(['download' => true]),
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_generate_job_in_production_dispatches_email_job(): void
    {
        config(['convenios.delivery_mode' => 'production']);

        Bus::fake([SendConvenioManualEmailJob::class]);

        $pdfPath = storage_path('app/temp/convenios/test-prod.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 prod test');

        $this->mock(ConvenioGenerationService::class, function ($mock) use ($pdfPath): void {
            $mock->shouldReceive('generarConvenio')
                ->once()
                ->andReturn([
                    'ruta' => storage_path('app/temp/convenios/test-prod.docx'),
                    'nombre' => 'Convenio_1234567890_Perez_Juan.docx',
                    'tipo' => 'docx',
                ]);
            $mock->shouldReceive('finalizeConvenioPdf')
                ->once()
                ->andReturn([
                    'ruta' => $pdfPath,
                    'nombre' => 'Convenio_1234567890_Perez_Juan.pdf',
                    'tipo' => 'pdf',
                ]);
        });

        $job = new GenerateConvenioJob(
            $this->convenioPayload(),
            'afiliado@example.com',
            true,
            1,
        );

        app()->call([$job, 'handle']);

        Bus::assertDispatched(SendConvenioManualEmailJob::class);
        $this->assertDatabaseMissing('convenio_email_tracking', [
            'documento' => '1234567890',
            'estado' => ConvenioEmailTracking::ESTADO_VERIFICACION,
        ]);
    }

    public function test_generate_job_in_test_mode_marks_tracking_as_test_and_emails_creator_with_pdf(): void
    {
        config([
            'convenios.delivery_mode' => 'test',
            'convenio_signing.enabled' => false,
        ]);

        Mail::fake();

        $pdfPath = storage_path('app/temp/convenios/test-verificacion.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 verification test');

        $this->mock(ConvenioGenerationService::class, function ($mock) use ($pdfPath): void {
            $mock->shouldReceive('generarConvenio')
                ->once()
                ->andReturn([
                    'ruta' => storage_path('app/temp/convenios/test-verificacion.docx'),
                    'nombre' => 'Convenio_1234567890_Perez_Juan.docx',
                    'tipo' => 'docx',
                ]);
            $mock->shouldReceive('finalizeConvenioPdf')
                ->once()
                ->andReturn([
                    'ruta' => $pdfPath,
                    'nombre' => 'Convenio_1234567890_Perez_Juan.pdf',
                    'tipo' => 'pdf',
                ]);
        });

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn(null);
        });

        $user = User::factory()->create([
            'email' => 'creator-test@example.com',
        ]);

        $job = new GenerateConvenioJob(
            $this->convenioPayload(),
            'afiliado@example.com',
            true,
            $user->id,
        );

        app()->call([$job, 'handle']);

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail) use ($user): bool {
            $mail->build();

            return $mail->hasTo($user->email)
                && $mail->isTest
                && $mail->signingUrl === null
                && str_contains((string) $mail->subject, '[TEST]')
                && count($mail->rawAttachments) === 1
                && ! $mail->hasCc('sprosalud.auxiliar@gmail.com');
        });

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail): bool {
            return ! $mail->hasTo('afiliado@example.com');
        });

        $tracking = ConvenioEmailTracking::query()->where('documento', '1234567890')->first();
        $this->assertNotNull($tracking);
        $this->assertTrue($tracking->is_test);
        $this->assertSame('enviado', $tracking->estado);
        $this->assertSame($user->id, $tracking->generated_by_user_id);
        $this->assertNotSame(ConvenioEmailTracking::ESTADO_VERIFICACION, $tracking->estado);
        $this->assertNotNull($tracking->pdf_original_path);
        $this->assertStringStartsWith('convenios/test/', $tracking->pdf_original_path);
        $this->assertStringEndsWith('/original.pdf', $tracking->pdf_original_path);
        Storage::disk('prosalud-private')->assertExists($tracking->pdf_original_path);
    }

    public function test_test_mode_with_digital_signing_sends_signing_link(): void
    {
        config([
            'convenios.delivery_mode' => 'test',
            'convenio_signing.enabled' => true,
            'services.firma_digital.app_url' => 'https://firma.test',
            'services.firma_digital.sign_path' => '/sign',
        ]);

        Mail::fake();

        $pdfPath = storage_path('app/temp/convenios/test-signing.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 signing test');

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn(null);
        });

        $user = User::factory()->create([
            'email' => 'tester@example.com',
        ]);

        $job = new SendConvenioManualEmailJob(
            '1234567890',
            'Convenio_1234567890_Perez_Juan.pdf',
            $pdfPath,
            'TEST CONVENIO',
            null,
            'tester@example.com',
            'BELLO',
            $this->convenioPayload(),
            $user->id,
        );

        app()->call([$job, 'handle']);

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail): bool {
            $mail->build();

            return $mail->hasTo('tester@example.com')
                && $mail->isTest
                && is_string($mail->signingUrl)
                && str_contains($mail->signingUrl, 'https://firma.test/sign/')
                && $mail->rawAttachments === []
                && str_contains((string) $mail->subject, '[TEST]');
        });

        $tracking = ConvenioEmailTracking::query()->where('documento', '1234567890')->first();
        $this->assertNotNull($tracking);
        $this->assertTrue($tracking->is_test);
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA, $tracking->signing_estado);
        $this->assertNotNull($tracking->signing_token_hash);
        $this->assertStringStartsWith('[TEST] ', (string) $tracking->viewer_header_title);
        $this->assertNotNull($tracking->pdf_original_path);
        $this->assertStringStartsWith('convenios/test/', $tracking->pdf_original_path);
        Storage::disk('prosalud-private')->assertExists($tracking->pdf_original_path);
    }

    public function test_non_production_delivery_mode_is_treated_as_test(): void
    {
        config(['convenios.delivery_mode' => 'staging']);

        $this->assertTrue(\App\Support\ConvenioDelivery::isTestMode());
        $this->assertFalse(\App\Support\ConvenioDelivery::isProductionMode());
    }

    public function test_tracking_detail_includes_convenio_data(): void
    {
        config(['convenios.delivery_mode' => 'test']);

        [$user, $token] = $this->authenticatedManageUser();

        $convenioData = $this->convenioPayload();

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'estado' => ConvenioEmailTracking::ESTADO_VERIFICACION,
            'generated_by_user_id' => $user->id,
            'convenio_data' => $convenioData,
        ]);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id,
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.tracking.id', $tracking->id)
            ->assertJsonPath('data.convenio_data.numero_documento', '1234567890')
            ->assertJsonPath('data.generated_by.email', $user->email);

        $fields = $response->json('data.convenio_data_fields');
        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
    }

    public function test_resend_in_test_mode_emails_requesting_user(): void
    {
        config(['convenios.delivery_mode' => 'test']);

        Mail::fake();

        [$user, $token] = $this->authenticatedManageUser();

        $pdfPath = storage_path('app/temp/convenios/resend-test.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 resend test');

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'enviado',
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/resend-emails',
            ['tracking_ids' => [$tracking->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('delivery_mode', 'test');

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail): bool {
            return ! $mail->hasTo('afiliado@example.com');
        });
    }

    public function test_resend_in_production_emails_affiliate(): void
    {
        config(['convenios.delivery_mode' => 'production']);

        Mail::fake();

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')
                ->andReturn([
                    'correo_personal' => 'afiliado@example.com',
                    'nombre_completo' => 'Juan Perez',
                    'nombres' => 'Juan',
                    'apellidos' => 'Perez',
                ]);
        });

        [$user, $token] = $this->authenticatedManageUser();

        $pdfPath = storage_path('app/temp/convenios/resend-prod.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 resend prod');

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'enviado',
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/resend-emails',
            ['tracking_ids' => [$tracking->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('delivery_mode', 'production');

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail): bool {
            return $mail->hasTo('afiliado@example.com');
        });
    }

    public function test_download_generated_finds_pdf_file(): void
    {
        config(['convenios.delivery_mode' => 'production']);

        [$user, $token] = $this->authenticatedManageUser();

        $outputDir = storage_path('app/temp/convenios');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $pdfPath = $outputDir.'/Convenio_1234567890_Perez_Juan.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 download generated');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/download-generated',
            ['numero_documento' => '1234567890'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
