<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioInvalidationRestoreService;
use App\Services\ConvenioInvalidationService;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ConvenioInvalidationRestoreTest extends TestCase
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

    public function test_restore_returns_invalidated_firmado_afiliado_to_president_sign_eligible_state(): void
    {
        $tracking = $this->createInvalidatedAfterAffiliateSign();

        $restored = app(ConvenioInvalidationRestoreService::class)->restore($tracking);

        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO, $restored->signing_estado);
        $this->assertNull($restored->rechazado_at);
        $this->assertNull($restored->motivo_rechazo);
        $this->assertNull($restored->firmado_presidente_at);
        $this->assertNull($restored->pdf_final_path);
        $this->assertNotNull($restored->firmado_afiliado_at);
        $this->assertNotNull($restored->pdf_firmado_afiliado_path);
        $this->assertTrue($restored->isEligibleForPresidentSign());
    }

    public function test_restore_clears_president_progress_when_invalidated_after_review(): void
    {
        $tracking = $this->createInvalidatedAfterAffiliateSign();
        $pdfStorage = app(ConvenioPdfStorageService::class);
        $finalPath = $pdfStorage->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final-with-president',
        );

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_RECHAZADO,
            'pdf_final_path' => $finalPath,
            'firmado_presidente_at' => now(),
            'president_sign_last_error' => 'Error previo',
        ]);

        $restored = app(ConvenioInvalidationRestoreService::class)->restore($tracking->fresh());

        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO, $restored->signing_estado);
        $this->assertNull($restored->pdf_final_path);
        $this->assertNull($restored->firmado_presidente_at);
        $this->assertNull($restored->president_sign_last_error);
        $this->assertFalse($pdfStorage->hasStage($restored, ConvenioPdfStage::Final));
        $this->assertTrue($pdfStorage->hasStage($restored, ConvenioPdfStage::FirmadoAfiliado));
    }

    public function test_restore_rejects_non_invalidated_convenio(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El convenio no está invalidado.');

        app(ConvenioInvalidationRestoreService::class)->restore($tracking);
    }

    public function test_restore_rejects_invalidated_convenio_without_affiliate_signature(): void
    {
        $tracking = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_RECHAZADO,
            'rechazado_at' => now(),
            'motivo_rechazo' => 'Invalidado antes de firmar.',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El afiliado no había firmado este convenio.');

        app(ConvenioInvalidationRestoreService::class)->restore($tracking);
    }

    public function test_command_restores_by_id(): void
    {
        $tracking = $this->createInvalidatedAfterAffiliateSign();

        $exitCode = Artisan::call('convenios:restore-invalidated', [
            'ids' => [(string) $tracking->id],
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO, $tracking->signing_estado);
        $this->assertTrue($tracking->isEligibleForPresidentSign());
    }

    public function test_command_dry_run_does_not_persist_changes(): void
    {
        $tracking = $this->createInvalidatedAfterAffiliateSign();

        Artisan::call('convenios:restore-invalidated', [
            'ids' => [(string) $tracking->id],
            '--dry-run' => true,
        ]);

        $tracking->refresh();
        $this->assertTrue($tracking->isInvalidated());
    }

    private function createInvalidatedAfterAffiliateSign(): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now()->subHour(),
        ]);

        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::FirmadoAfiliado,
            '%PDF-1.4 affiliate-signed',
        );

        $tracking->update([
            'pdf_firmado_afiliado_path' => $path,
        ]);

        app(ConvenioInvalidationService::class)->invalidate(
            $tracking->fresh(),
            null,
            'Invalidado por error operativo.',
        );

        return $tracking->fresh();
    }
}
