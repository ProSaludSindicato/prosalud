<?php

namespace Tests\Feature;

use App\Jobs\GenerateConvenioJob;
use App\Jobs\ProcessConvenioPdfZipJob;
use App\Jobs\SendConvenioManualEmailJob;
use App\Mail\ConvenioManualNotification;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\AfiliadoService;
use App\Services\ConvenioFailedEmailRetryService;
use App\Services\ConvenioGenerationService;
use App\Services\ConvenioPreGeneratedPdfDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Con el modo TEST habilitado, cada convenio creado/enviado se marca como prueba o real.
 * Los registros TEST nunca llegan al afiliado; los reales siguen el flujo productivo.
 */
class ConvenioPerRecordTestModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config(['convenio_signing.enabled' => false]);
        RateLimiter::clear('convenio-email-send');
        RateLimiter::clear('convenio-prosanet-lookup');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(string $email = 'gestor@example.com'): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create(['email' => $email]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $plainToken];
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

    private function writePdf(string $name): string
    {
        $pdfPath = storage_path('app/temp/convenios/'.$name);
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 '.$name);

        return $pdfPath;
    }

    private function mockGeneration(string $pdfPath): void
    {
        $this->mock(ConvenioGenerationService::class, function ($mock) use ($pdfPath): void {
            $mock->shouldReceive('generarConvenio')->andReturn([
                'ruta' => str_replace('.pdf', '.docx', $pdfPath),
                'nombre' => 'Convenio_1234567890_Perez_Juan.docx',
                'tipo' => 'docx',
            ]);
            $mock->shouldReceive('finalizeConvenioPdf')->andReturn([
                'ruta' => $pdfPath,
                'nombre' => 'Convenio_1234567890_Perez_Juan.pdf',
                'tipo' => 'pdf',
            ]);
        });
    }

    private function mockAffiliate(?string $email = 'afiliado@example.com'): void
    {
        $this->mock(AfiliadoService::class, function ($mock) use ($email): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn($email === null ? null : [
                'correo_personal' => $email,
                'nombre_completo' => 'Juan Perez',
                'nombres' => 'Juan',
                'apellidos' => 'Perez',
            ]);
        });
    }

    private function postAs(string $token, string $uri, array $data)
    {
        return $this->call('POST', $uri, $data, ['prosalud_auth_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function test_delivery_config_reports_test_mode(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        [, $token] = $this->authenticatedManageUser();

        $this->call('GET', '/api/convenios-manual/delivery-config', [], ['prosalud_auth_token' => $token], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('delivery_mode', 'test')
            ->assertJsonPath('test_mode_enabled', true);

        config(['convenios.delivery_mode' => 'production']);

        $this->call('GET', '/api/convenios-manual/delivery-config', [], ['prosalud_auth_token' => $token], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('test_mode_enabled', false);
    }

    public function test_generate_without_is_test_in_test_mode_defaults_to_test(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([GenerateConvenioJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $this->postAs($token, '/api/convenios-manual/generate-and-send', $this->convenioPayload([
            'send_email' => true,
            'email' => 'afiliado@example.com',
        ]))->assertStatus(202)->assertJsonPath('data.is_test', true);

        Bus::assertDispatched(GenerateConvenioJob::class, fn (GenerateConvenioJob $job): bool => $job->isTest === true);
    }

    public function test_generate_with_is_test_false_in_test_mode_is_real(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([GenerateConvenioJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $this->postAs($token, '/api/convenios-manual/generate-and-send', $this->convenioPayload([
            'send_email' => true,
            'email' => 'afiliado@example.com',
            'is_test' => false,
        ]))->assertStatus(202)->assertJsonPath('data.is_test', false);

        Bus::assertDispatched(GenerateConvenioJob::class, fn (GenerateConvenioJob $job): bool => $job->isTest === false);
    }

    public function test_generate_rejects_invalid_is_test(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([GenerateConvenioJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $this->postAs($token, '/api/convenios-manual/generate-and-send', $this->convenioPayload([
            'is_test' => 'quizas',
        ]))->assertStatus(422);

        Bus::assertNotDispatched(GenerateConvenioJob::class);
    }

    public function test_real_convenio_generated_in_test_mode_is_sent_to_affiliate(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Mail::fake();
        $this->mockGeneration($this->writePdf('real-in-test-mode.pdf'));
        $this->mockAffiliate();

        $user = User::factory()->create(['email' => 'gestor@example.com']);

        app()->call([new GenerateConvenioJob($this->convenioPayload(), 'afiliado@example.com', true, $user->id, false), 'handle']);

        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('afiliado@example.com') && ! $mail->isTest);
        Mail::assertNotSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('gestor@example.com'));

        $tracking = ConvenioEmailTracking::query()->where('documento', '1234567890')->firstOrFail();
        $this->assertFalse($tracking->is_test);
        $this->assertStringStartsWith('convenios/production/', (string) $tracking->pdf_original_path);
    }

    public function test_test_convenio_generated_in_test_mode_is_sent_to_creator(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Mail::fake();
        $this->mockGeneration($this->writePdf('test-in-test-mode.pdf'));
        $this->mockAffiliate();

        $user = User::factory()->create(['email' => 'gestor@example.com']);

        app()->call([new GenerateConvenioJob($this->convenioPayload(), 'afiliado@example.com', true, $user->id, true), 'handle']);

        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('gestor@example.com') && $mail->isTest);
        Mail::assertNotSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('afiliado@example.com'));

        $this->assertTrue(ConvenioEmailTracking::query()->where('documento', '1234567890')->firstOrFail()->is_test);
    }

    public function test_test_send_without_resolvable_user_email_never_falls_back_to_affiliate(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Mail::fake();
        $this->mockAffiliate();

        $job = new SendConvenioManualEmailJob(
            '1234567890',
            'Convenio_1234567890_Perez_Juan.pdf',
            $this->writePdf('test-no-user.pdf'),
            'TEST CONVENIO',
            null,
            null,
            'BELLO',
            $this->convenioPayload(),
            null,
            null,
            true,
        );

        app()->call([$job, 'handle']);

        Mail::assertNothingSent();
        $tracking = ConvenioEmailTracking::query()->where('documento', '1234567890')->firstOrFail();
        $this->assertTrue($tracking->is_test);
        $this->assertSame('fallido', $tracking->estado);
    }

    public function test_resend_of_real_record_in_test_mode_goes_to_affiliate(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Mail::fake();
        $this->mockAffiliate();
        [, $token] = $this->authenticatedManageUser();

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => $this->writePdf('resend-real.pdf'),
            'estado' => 'enviado',
        ]);

        $this->postAs($token, '/api/convenios-manual/resend-emails', ['tracking_ids' => [$tracking->id]])
            ->assertOk()
            ->assertJsonPath('data.real_count', 1)
            ->assertJsonPath('data.test_count', 0);

        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('afiliado@example.com') && ! $mail->isTest);
        Mail::assertNotSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('gestor@example.com'));
        $this->assertFalse($tracking->fresh()->is_test);
    }

    public function test_resend_of_test_record_in_production_mode_never_reaches_affiliate(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Mail::fake();
        $this->mockAffiliate();
        [, $token] = $this->authenticatedManageUser();

        $tracking = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '1234567890',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => $this->writePdf('resend-test-prod.pdf'),
            'estado' => 'enviado',
        ]);

        $this->postAs($token, '/api/convenios-manual/resend-emails', [
            'tracking_ids' => [$tracking->id],
            'emails' => [(string) $tracking->id => 'afiliado@example.com'],
        ])->assertOk()->assertJsonPath('data.test_count', 1);

        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('gestor@example.com') && $mail->isTest);
        Mail::assertNotSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('afiliado@example.com'));
    }

    public function test_mixed_resend_routes_each_record_by_its_own_flag(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Mail::fake();
        $this->mockAffiliate('real@example.com');
        [, $token] = $this->authenticatedManageUser();

        $real = ConvenioEmailTracking::factory()->create([
            'documento' => '1111111111',
            'email_afiliado' => 'real@example.com',
            'ruta_archivo_pdf' => $this->writePdf('mixed-real.pdf'),
            'estado' => 'enviado',
        ]);
        $test = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '2222222222',
            'email_afiliado' => 'otro-afiliado@example.com',
            'ruta_archivo_pdf' => $this->writePdf('mixed-test.pdf'),
            'estado' => 'enviado',
        ]);

        $this->postAs($token, '/api/convenios-manual/resend-emails', ['tracking_ids' => [$real->id, $test->id]])
            ->assertOk()
            ->assertJsonPath('data.real_count', 1)
            ->assertJsonPath('data.test_count', 1);

        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('real@example.com') && $mail->documento === '1111111111');
        Mail::assertSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('gestor@example.com') && $mail->documento === '2222222222' && $mail->isTest);
        Mail::assertNotSent(ConvenioManualNotification::class, fn (ConvenioManualNotification $mail): bool => $mail->hasTo('otro-afiliado@example.com'));
    }

    public function test_retry_failed_routes_each_record_by_its_own_flag(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([SendConvenioManualEmailJob::class]);

        $real = ConvenioEmailTracking::factory()->create([
            'estado' => 'fallido',
            'email_afiliado' => 'real@example.com',
            'ruta_archivo_pdf' => $this->writePdf('retry-real.pdf'),
        ]);
        $test = ConvenioEmailTracking::factory()->test()->create([
            'estado' => 'fallido',
            'email_afiliado' => 'otro-afiliado@example.com',
            'ruta_archivo_pdf' => $this->writePdf('retry-test.pdf'),
        ]);

        app(ConvenioFailedEmailRetryService::class)->retry(['tracking_ids' => [$real->id, $test->id]], 99, 'gestor@example.com');

        Bus::assertDispatched(SendConvenioManualEmailJob::class, fn (SendConvenioManualEmailJob $job): bool => $job->existingTrackingId === $real->id && $job->optionalEmail === 'real@example.com' && $job->isTest === false);
        Bus::assertDispatched(SendConvenioManualEmailJob::class, fn (SendConvenioManualEmailJob $job): bool => $job->existingTrackingId === $test->id && $job->optionalEmail === 'gestor@example.com' && $job->isTest === true);
    }

    public function test_pre_generated_pdf_real_in_test_mode_is_stored_as_real(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([SendConvenioManualEmailJob::class]);
        $user = User::factory()->create(['email' => 'gestor@example.com']);

        $pdfPath = $this->writePdf('zip-real.pdf');
        $result = app(ConvenioPreGeneratedPdfDispatchService::class)->persistAndOptionallySend(
            absolutePdfPath: $pdfPath,
            filename: 'BELLO - JUAN PEREZ - 1234567890.pdf',
            source: 'pdf_zip',
            batchId: 'batch-1',
            generatedByUserId: $user->id,
            sendEmail: true,
            isTest: false,
        );

        $tracking = ConvenioEmailTracking::query()->findOrFail($result['tracking_id']);
        $this->assertFalse($tracking->is_test);
        Bus::assertDispatched(SendConvenioManualEmailJob::class, fn (SendConvenioManualEmailJob $job): bool => $job->optionalEmail === null && $job->isTest === false);
    }

    public function test_pre_generated_pdf_test_is_stored_as_test_and_sent_to_user(): void
    {
        config(['convenios.delivery_mode' => 'test']);
        Bus::fake([SendConvenioManualEmailJob::class]);
        $user = User::factory()->create(['email' => 'gestor@example.com']);

        $result = app(ConvenioPreGeneratedPdfDispatchService::class)->persistAndOptionallySend(
            absolutePdfPath: $this->writePdf('zip-test.pdf'),
            filename: 'BELLO - JUAN PEREZ - 1234567890.pdf',
            source: 'pdf_zip',
            batchId: 'batch-2',
            generatedByUserId: $user->id,
            sendEmail: true,
            isTest: true,
        );

        $this->assertTrue(ConvenioEmailTracking::query()->findOrFail($result['tracking_id'])->is_test);
        Bus::assertDispatched(SendConvenioManualEmailJob::class, fn (SendConvenioManualEmailJob $job): bool => $job->optionalEmail === 'gestor@example.com' && $job->isTest === true);
    }

    public function test_download_generated_with_token_returns_that_generation_not_a_stale_pdf(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Bus::fake([GenerateConvenioJob::class]);
        [$user, $token] = $this->authenticatedManageUser();

        // PDF viejo del mismo documento (otra generación anterior con otros valores).
        $stale = storage_path('app/temp/convenios/SEDE ADMINISTRATIVA - ALISSON CARO HOLGUIN - 1234567890 - 20262.pdf');
        if (! is_dir(dirname($stale))) {
            mkdir(dirname($stale), 0755, true);
        }
        file_put_contents($stale, '%PDF-1.4 STALE 2.000.000');

        $response = $this->postAs($token, '/api/convenios-manual/generate-and-send', $this->convenioPayload(['send_email' => false]));
        $response->assertStatus(202);
        $previewToken = $response->json('data.preview_token');
        $this->assertNotEmpty($previewToken);

        $query = ['numero_documento' => '1234567890', 'preview_token' => $previewToken];

        // Aún no generado: debe esperar, nunca entregar el PDF viejo.
        $this->call('GET', '/api/convenios-manual/download-generated', $query, ['prosalud_auth_token' => $token], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(404)
            ->assertJsonPath('status', 'processing');

        $this->mockGeneration($this->writePdf('fresh-generation.pdf'));
        file_put_contents(storage_path('app/temp/convenios/fresh-generation.pdf'), '%PDF-1.4 FRESH 200.000');
        app()->call([new GenerateConvenioJob($this->convenioPayload(), null, false, $user->id, false, $previewToken), 'handle']);

        $download = $this->call('GET', '/api/convenios-manual/download-generated', $query, ['prosalud_auth_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
        $download->assertOk();
        $this->assertStringContainsString('FRESH 200.000', $download->baseResponse->getFile()->getContent());

        @unlink($stale);
    }

    public function test_download_generated_token_is_scoped_to_the_creating_user(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        [$owner] = $this->authenticatedManageUser('dueno@example.com');
        $this->mockGeneration($this->writePdf('owner-preview.pdf'));
        $previewToken = (string) Str::uuid();

        app()->call([new GenerateConvenioJob($this->convenioPayload(), null, false, $owner->id, false, $previewToken), 'handle']);

        $other = User::factory()->create();
        $other->givePermissionTo(['document_signing.manage']);
        $otherToken = 'other-plain-'.Str::random(48);
        ApiToken::query()->create(['user_id' => $other->id, 'name' => 'phpunit', 'token' => hash('sha256', $otherToken), 'expires_at' => now()->addDay()]);

        $this->call('GET', '/api/convenios-manual/download-generated', ['numero_documento' => '1234567890', 'preview_token' => $previewToken], ['prosalud_auth_token' => $otherToken], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_jobs_queued_before_the_flag_existed_still_deserialize(): void
    {
        foreach ([SendConvenioManualEmailJob::class, GenerateConvenioJob::class, ProcessConvenioPdfZipJob::class] as $jobClass) {
            $property = new ReflectionProperty($jobClass, 'isTest');
            $this->assertTrue($property->hasDefaultValue(), $jobClass.'::$isTest debe tener valor por defecto');
            $this->assertNull($property->getDefaultValue());
        }

        $job = new GenerateConvenioJob($this->convenioPayload(), 'afiliado@example.com', true, 1, false);
        $serialized = serialize($job);
        $legacy = preg_replace('/s:6:"isTest";b:0;/', '', $serialized, 1, $replaced);
        $this->assertSame(1, $replaced);
        $legacy = preg_replace_callback(
            '/^O:(\d+):"([^"]+)":(\d+):/',
            fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':',
            (string) $legacy,
        );

        $restored = unserialize((string) $legacy);
        $this->assertInstanceOf(GenerateConvenioJob::class, $restored);
        $this->assertNull($restored->isTest);
    }
}
