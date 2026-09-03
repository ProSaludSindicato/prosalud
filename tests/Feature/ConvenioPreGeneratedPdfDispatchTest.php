<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPreGeneratedPdfDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConvenioPreGeneratedPdfDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => false,
        ]);
    }

    public function test_persist_without_email_stores_pdf_on_private_disk(): void
    {
        $pdfPath = $this->writeTempPdf('%PDF-1.4 dispatch test');

        $service = app(ConvenioPreGeneratedPdfDispatchService::class);
        $result = $service->persistAndOptionallySend(
            absolutePdfPath: $pdfPath,
            filename: 'BELLO - TEST USER - 1234567890.pdf',
            source: 'cli',
            batchId: null,
            generatedByUserId: null,
            sendEmail: false,
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['dispatched']);
        $this->assertNotNull($result['tracking_id']);

        $tracking = ConvenioEmailTracking::findOrFail($result['tracking_id']);
        $this->assertSame('1234567890', $tracking->documento);
        $this->assertSame('cli', $tracking->convenio_data['source'] ?? null);
        $this->assertNotNull($tracking->pdf_original_path);
        Storage::disk('prosalud-private')->assertExists((string) $tracking->pdf_original_path);
    }

    public function test_persist_with_email_dispatches_send_job_with_existing_tracking(): void
    {
        Bus::fake([SendConvenioManualEmailJob::class]);

        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $pdfPath = $this->writeTempPdf('%PDF-1.4 dispatch email');

        $service = app(ConvenioPreGeneratedPdfDispatchService::class);
        $result = $service->persistAndOptionallySend(
            absolutePdfPath: $pdfPath,
            filename: 'BELLO - TEST USER - 9876543210.pdf',
            source: 'pdf_zip',
            batchId: 'batch-123',
            generatedByUserId: $user->id,
            sendEmail: true,
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dispatched']);

        Bus::assertDispatched(SendConvenioManualEmailJob::class, function (SendConvenioManualEmailJob $job) use ($result): bool {
            return $job->existingTrackingId === $result['tracking_id']
                && $job->documento === '9876543210';
        });
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
