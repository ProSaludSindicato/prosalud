<?php

namespace Tests\Unit;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConvenioEmailTrackingAvailableActionsTest extends TestCase
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

    public function test_resolve_available_actions_allows_mark_invalid_for_firmado_afiliado(): void
    {
        $tracking = ConvenioEmailTracking::factory()->firmadoAfiliado()->create([
            'pdf_firmado_afiliado_path' => 'convenios/production/2026/09/123456/1/signed.pdf',
        ]);

        $actions = $tracking->resolveAvailableActions(true, false);

        $this->assertTrue($actions['mark_invalid']);
        $this->assertTrue($actions['president_sign']);
    }

    public function test_resolve_available_actions_allows_download_final_for_president_sign_error(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
            'pdf_original_path' => 'convenios/production/2026/09/123456/1/original.pdf',
            'pdf_firmado_afiliado_path' => 'convenios/production/2026/09/123456/1/signed.pdf',
            'firmado_afiliado_at' => now(),
            'president_sign_last_error' => 'No se encontró el texto ancla.',
        ]);

        $actions = $tracking->resolveAvailableActions(true, false);

        $this->assertTrue($actions['download_original']);
        $this->assertTrue($actions['download_final']);
        $this->assertTrue($actions['president_sign']);
        $this->assertTrue($actions['request_affiliate_resign']);
    }

    public function test_resolve_available_actions_uses_recorded_paths_without_storage_checks(): void
    {
        $tracking = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'estado' => 'enviado',
            'pdf_original_path' => 'convenios/production/2026/09/123456/1/original.pdf',
            'pdf_firmado_afiliado_path' => 'convenios/production/2026/09/123456/1/signed.pdf',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
        ]);

        $storage = $this->mock(ConvenioPdfStorageService::class);
        $storage->shouldNotReceive('hasOriginal');
        $storage->shouldNotReceive('hasStage');

        $actions = $tracking->resolveAvailableActions(true, false);

        $this->assertTrue($actions['download_original']);
        $this->assertTrue($actions['download_final']);
        $this->assertTrue($actions['mark_invalid']);
        $this->assertTrue($actions['president_sign']);
    }

    public function test_has_original_path_recorded_uses_parent_tracking_path(): void
    {
        $parent = ConvenioEmailTracking::factory()->create([
            'pdf_original_path' => 'convenios/production/2026/09/123456/1/original.pdf',
        ]);

        $child = ConvenioEmailTracking::factory()->create([
            'parent_tracking_id' => $parent->id,
            'pdf_original_path' => null,
            'ruta_archivo_pdf' => '',
        ]);

        $child->setRelation('parentTracking', $parent);

        $this->assertTrue($child->hasOriginalPathRecorded());
    }
}
