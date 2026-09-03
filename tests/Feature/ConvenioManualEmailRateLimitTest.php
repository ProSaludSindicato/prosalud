<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Services\AfiliadoService;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ConvenioManualEmailRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('convenio-prosanet-lookup');
        RateLimiter::clear('convenio-email-send');
        config([
            'convenios.prosanet_per_minute' => 1,
            'convenio_signing.enabled' => false,
        ]);
    }

    public function test_send_job_releases_when_prosanet_rate_limit_is_exceeded(): void
    {
        Mail::fake();
        ConvenioRateLimiter::hitProsanet();

        $pdfPath = $this->writeTempPdf('%PDF-1.4 rate limit');

        $afiliadoService = Mockery::mock(AfiliadoService::class);
        $afiliadoService->shouldNotReceive('getAfiliadoByDocumentoOnly');

        $job = new SendConvenioManualEmailJob(
            documento: '1234567890',
            nombreArchivo: 'BELLO - TEST USER - 1234567890.pdf',
            rutaArchivoPdf: $pdfPath,
            nombreConvenio: 'BELLO',
        );

        $job->handle(
            $afiliadoService,
            app(ConvenioDigitalSigningService::class),
            app(ConvenioPdfStorageService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame(0, ConvenioEmailTracking::count());
    }

    public function test_send_job_with_existing_tracking_uses_s3_pdf_when_local_missing(): void
    {
        Mail::fake();
        Storage::fake('prosalud-private');
        config(['convenios.storage_disk' => 'prosalud-private', 'convenio_signing.enabled' => false]);

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '5555555555',
            'nombre_archivo' => 'BELLO - TEST USER - 5555555555.pdf',
            'ruta_archivo_pdf' => '/tmp/does-not-exist.pdf',
            'pdf_original_path' => null,
            'is_test' => false,
        ]);

        $pdfPath = $this->writeTempPdf('%PDF-1.4 existing tracking');
        $storage = app(ConvenioPdfStorageService::class);
        $relative = $storage->storeOriginalFromAbsolutePath($tracking, $pdfPath);
        $tracking->update(['pdf_original_path' => $relative]);

        $afiliadoService = Mockery::mock(AfiliadoService::class);
        $afiliadoService->shouldNotReceive('getAfiliadoByDocumentoOnly');

        $job = new SendConvenioManualEmailJob(
            documento: '5555555555',
            nombreArchivo: 'BELLO - TEST USER - 5555555555.pdf',
            rutaArchivoPdf: '/tmp/does-not-exist.pdf',
            nombreConvenio: 'BELLO',
            optionalEmail: 'afiliado@example.com',
            existingTrackingId: $tracking->id,
        );

        $job->handle(
            $afiliadoService,
            app(ConvenioDigitalSigningService::class),
            $storage,
        );

        Mail::assertSent(\App\Mail\ConvenioManualNotification::class);
        $this->assertSame('enviado', $tracking->fresh()->estado);
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
}
