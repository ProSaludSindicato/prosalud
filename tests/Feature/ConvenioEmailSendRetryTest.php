<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use App\Services\AfiliadoService;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConvenioEmailSendRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['convenio_signing.enabled' => false]);
    }

    public function test_marcar_como_enviado_clears_previous_error_message(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'fallido',
            'error_message' => 'Error al enviar correo: Expected response code "354" but got code "550"',
            'intentos' => 1,
        ]);

        $tracking->marcarComoEnviado();
        $tracking->refresh();

        $this->assertSame('enviado', $tracking->estado);
        $this->assertNull($tracking->error_message);
        $this->assertNotNull($tracking->enviado_at);
    }

    public function test_successful_send_after_previous_failure_clears_error_message(): void
    {
        Mail::fake();

        $pdfPath = $this->writeTempPdf('%PDF-1.4 retry success');

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1017252506',
            'nombre_archivo' => 'BELLO - TEST USER - 1017252506.pdf',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'fallido',
            'error_message' => 'Error al enviar correo: Expected response code "354" but got code "550"',
            'intentos' => 1,
            'email_afiliado' => 'afiliado@example.com',
        ]);

        $job = new SendConvenioManualEmailJob(
            documento: $tracking->documento,
            nombreArchivo: $tracking->nombre_archivo,
            rutaArchivoPdf: $pdfPath,
            nombreConvenio: 'BELLO',
            optionalEmail: 'afiliado@example.com',
            existingTrackingId: $tracking->id,
        );

        $job->handle(
            Mockery::mock(AfiliadoService::class),
            app(ConvenioDigitalSigningService::class),
            app(ConvenioPdfStorageService::class),
        );

        $tracking->refresh();

        Mail::assertSent(ConvenioManualNotification::class);
        $this->assertSame('enviado', $tracking->estado);
        $this->assertNull($tracking->error_message);
        $this->assertNotNull($tracking->enviado_at);
        $this->assertSame(1, $tracking->intentos);
    }

    public function test_transient_mail_failure_does_not_persist_error_while_job_can_retry(): void
    {
        $pdfPath = $this->writeTempPdf('%PDF-1.4 retry transient');

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1143409136',
            'nombre_archivo' => 'BELLO - TEST USER - 1143409136.pdf',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'pendiente',
            'error_message' => null,
            'intentos' => 0,
            'email_afiliado' => 'afiliado@example.com',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Expected response code "354" but got code "550", with message "550 5.7.0 Too many emails per second"'));

        $job = new SendConvenioManualEmailJob(
            documento: $tracking->documento,
            nombreArchivo: $tracking->nombre_archivo,
            rutaArchivoPdf: $pdfPath,
            nombreConvenio: 'BELLO',
            optionalEmail: 'afiliado@example.com',
            existingTrackingId: $tracking->id,
        );

        try {
            $job->handle(
                Mockery::mock(AfiliadoService::class),
                app(ConvenioDigitalSigningService::class),
                app(ConvenioPdfStorageService::class),
            );
            $this->fail('Expected the mail transport exception to be rethrown for queue retry.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Too many emails per second', $e->getMessage());
        }

        $tracking->refresh();

        $this->assertSame('pendiente', $tracking->estado);
        $this->assertNull($tracking->error_message);
        $this->assertSame(0, $tracking->intentos);
    }

    public function test_failed_callback_marks_tracking_as_failed_after_retries_exhaust(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'pendiente',
            'error_message' => null,
            'intentos' => 0,
        ]);

        $job = new SendConvenioManualEmailJob(
            documento: $tracking->documento,
            nombreArchivo: $tracking->nombre_archivo,
            rutaArchivoPdf: '/tmp/missing.pdf',
            nombreConvenio: 'BELLO',
            existingTrackingId: $tracking->id,
        );

        $job->failed(new RuntimeException('Expected response code "354" but got code "550", with message "550 5.7.0 Too many emails per second"'));

        $tracking->refresh();

        $this->assertSame('fallido', $tracking->estado);
        $this->assertStringContainsString('Job falló después de 5 intentos', (string) $tracking->error_message);
        $this->assertStringContainsString('Too many emails per second', (string) $tracking->error_message);
        $this->assertSame(1, $tracking->intentos);
    }

    public function test_resolve_visible_error_message_only_for_failed_estado(): void
    {
        $sent = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'error_message' => 'Error al enviar correo: 550 5.7.0 Too many emails per second',
        ]);
        $failed = ConvenioEmailTracking::factory()->create([
            'estado' => 'fallido',
            'error_message' => 'Job falló después de 5 intentos: 550 5.7.0 Too many emails per second',
        ]);

        $this->assertNull($sent->resolveVisibleErrorMessage());
        $this->assertSame(
            'Job falló después de 5 intentos: 550 5.7.0 Too many emails per second',
            $failed->resolveVisibleErrorMessage()
        );
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
