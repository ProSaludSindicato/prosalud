<?php

namespace Tests\Feature;

use App\Jobs\ProcessConvenioPdfZipJob;
use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfZipImportService;
use App\Services\ConvenioPreGeneratedPdfDispatchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ImportConvenioPdfZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config(['convenios.storage_disk' => 'prosalud-private']);
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

    /**
     * @param  array<string, string>  $entries
     */
    private function createConvenioZipUpload(array $entries): UploadedFile
    {
        $zipPath = storage_path('framework/testing/convenios-'.Str::uuid().'.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return UploadedFile::fake()->createWithContent('convenios.zip', $content);
    }

    public function test_import_pdf_zip_accepts_valid_zip_and_queues_processing_job(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([ProcessConvenioPdfZipJob::class]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConvenioZipUpload([
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf' => '%PDF-1.4 valid pdf one',
            'invalid-name.pdf' => '%PDF-1.4 invalid name',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $file,
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.validos', 1)
            ->assertJsonPath('data.rechazados', 1)
            ->assertJsonPath('data.send_email', true);

        Bus::assertDispatched(ProcessConvenioPdfZipJob::class, function (ProcessConvenioPdfZipJob $job): bool {
            return $job->sendEmail === true
                && count($job->validEntries) === 1
                && $job->validEntries[0]['documento'] === '1035228093';
        });
    }

    public function test_import_pdf_zip_rejects_zip_without_valid_pdfs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([ProcessConvenioPdfZipJob::class]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConvenioZipUpload([
            'invalid-name.pdf' => '%PDF-1.4 invalid name',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $file,
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);

        Bus::assertNothingDispatched();
    }

    public function test_import_pdf_zip_returns_403_without_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();

        $file = $this->createConvenioZipUpload([
            'BELLO - TEST USER - 1234567890.pdf' => '%PDF-1.4 valid pdf',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertForbidden();
    }

    public function test_process_pdf_zip_job_persists_entries_and_deletes_stored_zip(): void
    {
        Bus::fake([SendConvenioManualEmailJob::class]);

        $filename = 'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf';
        $storedZipPath = $this->storeConvenioZip([
            $filename => '%PDF-1.4 valid pdf one',
        ]);
        $batchId = (string) Str::uuid();

        $job = new ProcessConvenioPdfZipJob(
            batchId: $batchId,
            storedZipPath: $storedZipPath,
            validEntries: [[
                'entry' => $filename,
                'filename' => $filename,
                'documento' => '1035228093',
                'nombre_convenio' => 'BELLO',
            ]],
            sendEmail: true,
            generatedByUserId: null,
        );

        $job->handle(
            app(ConvenioPdfZipImportService::class),
            app(ConvenioPreGeneratedPdfDispatchService::class),
        );

        $this->assertSame(1, ConvenioEmailTracking::query()->count());
        $tracking = ConvenioEmailTracking::query()->firstOrFail();
        $this->assertSame('1035228093', $tracking->documento);
        $this->assertSame($batchId, $tracking->convenio_data['batch_id'] ?? null);
        $this->assertSame('pdf_zip', $tracking->convenio_data['source'] ?? null);
        Storage::disk('prosalud-private')->assertMissing($storedZipPath);
        Bus::assertDispatched(SendConvenioManualEmailJob::class);
    }

    public function test_process_pdf_zip_job_keeps_stored_zip_when_download_fails(): void
    {
        $storedZipPath = 'convenios/inbox/zips/'.Str::uuid().'.zip';
        Storage::disk('prosalud-private')->put($storedZipPath, '');

        $job = new ProcessConvenioPdfZipJob(
            batchId: (string) Str::uuid(),
            storedZipPath: $storedZipPath,
            validEntries: [[
                'entry' => 'BELLO - TEST USER - 1234567890.pdf',
                'filename' => 'BELLO - TEST USER - 1234567890.pdf',
                'documento' => '1234567890',
                'nombre_convenio' => 'BELLO',
            ]],
            sendEmail: false,
            generatedByUserId: null,
        );

        try {
            $job->handle(
                app(ConvenioPdfZipImportService::class),
                app(ConvenioPreGeneratedPdfDispatchService::class),
            );
            $this->fail('Expected the job to fail when the ZIP cannot be downloaded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No se pudo descargar el ZIP desde almacenamiento.', $exception->getMessage());
        }

        Storage::disk('prosalud-private')->assertExists($storedZipPath);
        $this->assertSame(0, ConvenioEmailTracking::query()->count());
    }

    public function test_process_pdf_zip_job_skips_already_processed_entries(): void
    {
        Bus::fake([SendConvenioManualEmailJob::class]);

        $filename = 'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf';
        $batchId = (string) Str::uuid();
        ConvenioEmailTracking::factory()->create([
            'documento' => '1035228093',
            'nombre_convenio' => 'BELLO',
            'convenio_data' => [
                'source' => 'pdf_zip',
                'batch_id' => $batchId,
            ],
        ]);

        $storedZipPath = $this->storeConvenioZip([
            $filename => '%PDF-1.4 valid pdf one',
        ]);

        $job = new ProcessConvenioPdfZipJob(
            batchId: $batchId,
            storedZipPath: $storedZipPath,
            validEntries: [[
                'entry' => $filename,
                'filename' => $filename,
                'documento' => '1035228093',
                'nombre_convenio' => 'BELLO',
            ]],
            sendEmail: true,
            generatedByUserId: null,
        );

        $job->handle(
            app(ConvenioPdfZipImportService::class),
            app(ConvenioPreGeneratedPdfDispatchService::class),
        );

        $this->assertSame(1, ConvenioEmailTracking::query()->count());
        Bus::assertNotDispatched(SendConvenioManualEmailJob::class);
        Storage::disk('prosalud-private')->assertMissing($storedZipPath);
    }

    public function test_process_pdf_zip_job_timeout_stays_below_database_retry_after(): void
    {
        $job = new ProcessConvenioPdfZipJob(
            batchId: (string) Str::uuid(),
            storedZipPath: 'convenios/inbox/zips/example.zip',
            validEntries: [],
            sendEmail: false,
            generatedByUserId: null,
        );

        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(1, $job->tries);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $job->timeout,
        );
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function storeConvenioZip(array $entries): string
    {
        $zipPath = storage_path('framework/testing/convenios-store-'.Str::uuid().'.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        $storedPath = app(ConvenioPdfZipImportService::class)->storeZipOnDisk($zipPath);
        @unlink($zipPath);

        return $storedPath;
    }
}
