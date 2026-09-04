<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Mail\ConvenioManualNotification;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\AfiliadoService;
use App\Services\ConvenioPdfStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioResendFromStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => false,
            'convenios.delivery_mode' => 'production',
        ]);
    }

    public function test_resend_reuses_existing_tracking_instead_of_creating_child(): void
    {
        Mail::fake();

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')
                ->andReturn([
                    'correo_personal' => 'afiliado@example.com',
                    'nombre_completo' => 'Carlos Ospina',
                    'nombres' => 'Carlos',
                    'apellidos' => 'Ospina',
                ]);
        });

        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853497');
        $trackingId = $tracking->id;

        $response = $this->call(
            'POST',
            '/api/convenios-manual/resend-emails',
            ['tracking_ids' => [$trackingId]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        Mail::assertSent(ConvenioManualNotification::class, function (ConvenioManualNotification $mail): bool {
            return $mail->hasTo('afiliado@example.com');
        });

        $this->assertSame(1, ConvenioEmailTracking::query()->count());

        $tracking->refresh();
        $this->assertSame('enviado', $tracking->estado);
        $this->assertNotNull($tracking->pdf_original_path);
        Storage::disk('prosalud-private')->assertExists((string) $tracking->pdf_original_path);
    }

    public function test_resend_failed_record_updates_same_row(): void
    {
        Mail::fake();

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')
                ->andReturn([
                    'correo_personal' => 'afiliado@example.com',
                    'nombre_completo' => 'Carlos Ospina',
                ]);
        });

        [, $token] = $this->authenticatedManageUser();

        $parent = $this->createTrackingWithStoredPdf('70853497');

        $failed = ConvenioEmailTracking::factory()->create([
            'documento' => $parent->documento,
            'parent_tracking_id' => $parent->id,
            'nombre_archivo' => $parent->nombre_archivo,
            'nombre_convenio' => $parent->nombre_convenio,
            'email_afiliado' => 'afiliado@example.com',
            'pdf_original_path' => null,
            'ruta_archivo_pdf' => '/tmp/missing-resend.pdf',
            'estado' => 'fallido',
            'error_message' => 'Archivo PDF no encontrado para este registro.',
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/resend-emails',
            ['tracking_ids' => [$failed->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 1);

        $this->assertSame(2, ConvenioEmailTracking::query()->count());

        $failed->refresh();
        $this->assertSame('enviado', $failed->estado);
        $this->assertNull($failed->error_message);
        $this->assertNotNull($failed->pdf_original_path);
        Storage::disk('prosalud-private')->assertExists((string) $failed->pdf_original_path);
    }

    public function test_resend_job_resolves_pdf_from_storage_when_reusing_failed_tracking(): void
    {
        Mail::fake();

        $this->mock(AfiliadoService::class, function ($mock): void {
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')
                ->andReturn([
                    'correo_personal' => 'afiliado@example.com',
                    'nombre_completo' => 'Carlos Ospina',
                ]);
        });

        $parent = $this->createTrackingWithStoredPdf('70853497');

        $failed = ConvenioEmailTracking::factory()->create([
            'documento' => $parent->documento,
            'parent_tracking_id' => $parent->id,
            'nombre_archivo' => $parent->nombre_archivo,
            'nombre_convenio' => $parent->nombre_convenio,
            'email_afiliado' => 'afiliado@example.com',
            'pdf_original_path' => $parent->pdf_original_path,
            'pdf_original_sha256' => $parent->pdf_original_sha256,
            'ruta_archivo_pdf' => '/var/www/html/storage/app/temp/convenios/s3-'.$parent->id.'-missing.pdf',
            'estado' => 'pendiente',
        ]);

        $job = new SendConvenioManualEmailJob(
            documento: $failed->documento,
            nombreArchivo: $failed->nombre_archivo,
            rutaArchivoPdf: $failed->ruta_archivo_pdf,
            nombreConvenio: $failed->nombre_convenio,
            parentTrackingId: null,
            optionalEmail: 'afiliado@example.com',
            sede: $failed->sede,
            convenioData: $failed->convenio_data,
            generatedByUserId: $failed->generated_by_user_id,
            existingTrackingId: $failed->id,
        );

        $job->handle(
            app(AfiliadoService::class),
            app(\App\Services\ConvenioDigitalSigningService::class),
            app(ConvenioPdfStorageService::class),
            app(\App\Services\ConvenioPdfIntegrityService::class),
        );

        $failed->refresh();
        $this->assertSame('enviado', $failed->estado);
        $this->assertNull($failed->error_message);
        $this->assertSame(2, ConvenioEmailTracking::query()->count());
    }

    public function test_has_original_falls_back_to_parent_storage_path(): void
    {
        $parent = $this->createTrackingWithStoredPdf('70853497');

        $child = ConvenioEmailTracking::factory()->create([
            'documento' => $parent->documento,
            'parent_tracking_id' => $parent->id,
            'pdf_original_path' => null,
            'ruta_archivo_pdf' => '/tmp/missing-child.pdf',
            'estado' => 'pendiente',
        ]);

        $storage = app(ConvenioPdfStorageService::class);

        $this->assertTrue($storage->hasOriginal($child->fresh(['parentTracking'])));
        $this->assertSame('%PDF-1.4 resend storage test', $storage->originalContents($child->fresh(['parentTracking'])));
    }

    private function createTrackingWithStoredPdf(string $documento): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => $documento,
            'nombre_afiliado' => 'CARLOS HORACIO OSPINA JARAMILLO',
            'nombre_convenio' => 'BELLO',
            'nombre_archivo' => 'BELLO - OSPINA JARAMILLO CARLOS HORACIO - '.$documento.'.pdf',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => '/tmp/missing-original.pdf',
            'estado' => 'enviado',
            'convenio_data' => [
                'source' => 'pdf_zip',
                'batch_id' => 'batch-test',
            ],
        ]);

        $source = $this->writeTempPdf('%PDF-1.4 resend storage test');
        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $source);
        $tracking->update([
            'pdf_original_path' => $relative,
            'pdf_original_sha256' => hash('sha256', '%PDF-1.4 resend storage test'),
        ]);

        return $tracking->fresh();
    }

    private function writeTempPdf(string $contents): string
    {
        $dir = storage_path('app/temp/convenios/tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/test-'.uniqid('', true).'.pdf';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin-resend@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
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
}
