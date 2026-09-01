<?php

namespace Tests\Feature;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanTestConvenioTrackingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
    }

    public function test_deletes_test_records_and_keeps_real_ones(): void
    {
        $testDir = storage_path('app/temp/convenios');
        if (! is_dir($testDir)) {
            mkdir($testDir, 0755, true);
        }

        $testPdf = $testDir.'/clean-test-record.pdf';
        file_put_contents($testPdf, '%PDF-1.4 test');

        $test = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '1111111111',
            'ruta_archivo_pdf' => $testPdf,
        ]);
        $real = ConvenioEmailTracking::factory()->create([
            'documento' => '2222222222',
            'is_test' => false,
        ]);

        $this->artisan('convenios:clean-test-tracking', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $test->id]);
        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $real->id]);
        $this->assertFileDoesNotExist($testPdf);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $test = ConvenioEmailTracking::factory()->test()->create();

        $this->artisan('convenios:clean-test-tracking', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('convenio_email_tracking', ['id' => $test->id]);
    }

    public function test_deletes_digital_signing_storage_directory(): void
    {
        $tracking = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '1111111111',
        ]);

        $dir = storage_path('app/temp/convenios');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $source = $dir.'/clean-s3-original.pdf';
        file_put_contents($source, '%PDF-1.4 stored');

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $source);
        $tracking->update(['pdf_original_path' => $relative]);
        Storage::disk('prosalud-private')->assertExists($relative);

        $this->artisan('convenios:clean-test-tracking', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('convenio_email_tracking', ['id' => $tracking->id]);
        Storage::disk('prosalud-private')->assertMissing($relative);
    }
}
