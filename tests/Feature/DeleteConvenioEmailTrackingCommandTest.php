<?php

namespace Tests\Feature;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteConvenioEmailTrackingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config(['convenios.storage_disk' => 'prosalud-private']);
    }

    public function test_deletes_failed_tracking_by_id(): void
    {
        $failed = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'error_message' => 'Archivo PDF no encontrado para este registro.',
        ]);

        $kept = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'enviado',
        ]);

        $this->artisan('convenios:delete-tracking', [
            'ids' => [(string) $failed->id],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $failed->id]);
        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $kept->id]);
    }

    public function test_accepts_multiple_ids_and_comma_separated_values(): void
    {
        $first = ConvenioEmailTracking::factory()->create(['estado' => 'fallido']);
        $second = ConvenioEmailTracking::factory()->create(['estado' => 'fallido']);

        $this->artisan('convenios:delete-tracking', [
            'ids' => ["{$first->id},{$second->id}"],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $first->id]);
        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $second->id]);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $failed = ConvenioEmailTracking::factory()->create(['estado' => 'fallido']);

        $this->artisan('convenios:delete-tracking', [
            'ids' => [(string) $failed->id],
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $failed->id]);
    }

    public function test_blocks_deleting_sent_records_without_allow_enviado(): void
    {
        $sent = ConvenioEmailTracking::factory()->create(['estado' => 'enviado']);

        $this->artisan('convenios:delete-tracking', [
            'ids' => [(string) $sent->id],
            '--force' => true,
        ])->assertFailed();

        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $sent->id]);
    }

    public function test_deletes_sent_record_when_allow_enviado_is_set(): void
    {
        $sent = ConvenioEmailTracking::factory()->create(['estado' => 'enviado']);

        $this->artisan('convenios:delete-tracking', [
            'ids' => [(string) $sent->id],
            '--force' => true,
            '--allow-enviado' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $sent->id]);
    }

    public function test_deletes_private_storage_files(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
        ]);

        $dir = storage_path('app/temp/convenios');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $source = $dir.'/delete-tracking-source.pdf';
        file_put_contents($source, '%PDF-1.4 delete tracking');

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $source);
        $tracking->update(['pdf_original_path' => $relative]);
        Storage::disk('prosalud-private')->assertExists($relative);

        $this->artisan('convenios:delete-tracking', [
            'ids' => [(string) $tracking->id],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $tracking->id]);
        Storage::disk('prosalud-private')->assertMissing($relative);
    }

    public function test_deletes_by_documento_and_estado_filters(): void
    {
        $failed = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'nombre_convenio' => 'BELLO',
        ]);

        $sent = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'enviado',
            'nombre_convenio' => 'BELLO',
        ]);

        $this->artisan('convenios:delete-tracking', [
            '--documento' => '70853497',
            '--estado' => 'fallido',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $failed->id]);
        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $sent->id]);
    }

    public function test_requires_latest_or_all_when_multiple_records_match_filters(): void
    {
        ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => now()->subHour(),
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => now(),
        ]);

        $this->artisan('convenios:delete-tracking', [
            '--documento' => '70853497',
            '--estado' => 'fallido',
        ])->assertFailed();

        $this->assertSame(2, ConvenioEmailTracking::query()->where('documento', '70853497')->count());
    }

    public function test_latest_option_deletes_only_most_recent_match(): void
    {
        $older = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => now()->subHour(),
        ]);
        $newer = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => now(),
        ]);

        $this->artisan('convenios:delete-tracking', [
            '--documento' => '70853497',
            '--estado' => 'fallido',
            '--latest' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $older->id]);
        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $newer->id]);
    }

    public function test_created_on_filter_matches_record_date(): void
    {
        $target = ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => '2026-09-04 09:16:00',
        ]);

        ConvenioEmailTracking::factory()->create([
            'documento' => '70853497',
            'estado' => 'fallido',
            'created_at' => '2026-09-03 09:16:00',
        ]);

        $this->artisan('convenios:delete-tracking', [
            '--documento' => '70853497',
            '--estado' => 'fallido',
            '--created-on' => '2026-09-04 09:16',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $target->id]);
        $this->assertSame(1, ConvenioEmailTracking::query()->where('documento', '70853497')->count());
    }
}
