<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteRejectedConvenioCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => true,
        ]);
    }

    public function test_completes_convenio_rejected_by_invalidation(): void
    {
        $tracking = $this->createRejected(ConvenioEmailTracking::SIGNING_RECHAZADO);

        $exitCode = Artisan::call('convenios:complete-rejected', [
            'ids' => [(string) $tracking->id],
            '--force' => true,
            '--no-email' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->signing_estado);
        $this->assertNull($tracking->rechazado_at);
        $this->assertNull($tracking->motivo_rechazo);
        $this->assertNotNull($tracking->completed_at);
        $this->assertNotNull($tracking->pdf_final_path);
    }

    public function test_completes_convenio_rejected_with_review_error(): void
    {
        $tracking = $this->createRejected(ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE);

        Artisan::call('convenios:complete-rejected', [
            '--documento' => $tracking->documento,
            '--force' => true,
            '--no-email' => true,
        ]);

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->signing_estado);
        $this->assertNull($tracking->president_sign_last_error);
    }

    public function test_fails_without_final_pdf(): void
    {
        $tracking = $this->createRejected(ConvenioEmailTracking::SIGNING_RECHAZADO);
        $tracking->update(['pdf_final_path' => null]);

        $exitCode = Artisan::call('convenios:complete-rejected', [
            'ids' => [(string) $tracking->id],
            '--force' => true,
            '--no-email' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(ConvenioEmailTracking::SIGNING_RECHAZADO, $tracking->fresh()->signing_estado);
    }

    public function test_ignores_convenios_not_rejected(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
        ]);

        $exitCode = Artisan::call('convenios:complete-rejected', [
            'ids' => [(string) $tracking->id],
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION, $tracking->fresh()->signing_estado);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $tracking = $this->createRejected(ConvenioEmailTracking::SIGNING_RECHAZADO);

        Artisan::call('convenios:complete-rejected', [
            'ids' => [(string) $tracking->id],
            '--dry-run' => true,
        ]);

        $this->assertSame(ConvenioEmailTracking::SIGNING_RECHAZADO, $tracking->fresh()->signing_estado);
    }

    private function createRejected(string $estado): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => $estado,
            'firmado_afiliado_at' => now()->subHours(2),
            'firmado_presidente_at' => now()->subHour(),
            'rechazado_at' => $estado === ConvenioEmailTracking::SIGNING_RECHAZADO ? now() : null,
            'motivo_rechazo' => $estado === ConvenioEmailTracking::SIGNING_RECHAZADO ? 'Error del revisor.' : null,
            'president_sign_last_error' => $estado === ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE ? 'Rechazado durante la revisión del convenio.' : null,
        ]);

        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final',
        );

        $tracking->update(['pdf_final_path' => $path]);

        return $tracking->fresh();
    }
}
