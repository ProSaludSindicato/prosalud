<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioPdfStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
    }

    public function test_stores_original_on_private_disk_under_organized_production_path(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'is_test' => false,
        ]);

        $source = $this->writeTempPdf('%PDF-1.4 original');
        $path = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $source);

        $year = now()->format('Y');
        $month = now()->format('m');
        $this->assertSame(
            "convenios/production/{$year}/{$month}/1234567890/{$tracking->id}/original.pdf",
            $path,
        );
        Storage::disk('prosalud-private')->assertExists($path);
        $this->assertSame('%PDF-1.4 original', Storage::disk('prosalud-private')->get($path));
    }

    public function test_stores_test_records_under_test_prefix(): void
    {
        $tracking = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '9988776655',
        ]);

        $source = $this->writeTempPdf('%PDF-1.4 test original');
        $path = app(ConvenioPdfStorageService::class)->storeFromAbsolutePath(
            $tracking,
            ConvenioPdfStage::Original,
            $source,
        );

        $this->assertStringStartsWith('convenios/test/', $path);
        $this->assertStringContainsString('/9988776655/', $path);
        Storage::disk('prosalud-private')->assertExists($path);
    }

    public function test_stores_signed_stage_alongside_original(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'is_test' => false,
        ]);

        $storage = app(ConvenioPdfStorageService::class);
        $originalPath = $storage->storeFromAbsolutePath(
            $tracking,
            ConvenioPdfStage::Original,
            $this->writeTempPdf('%PDF-1.4 original'),
        );
        $firmadoPath = $storage->storeFromAbsolutePath(
            $tracking,
            ConvenioPdfStage::FirmadoAfiliado,
            $this->writeTempPdf('%PDF-1.4 firmado'),
        );

        $this->assertSame(dirname($originalPath).'/firmado-afiliado.pdf', $firmadoPath);
        Storage::disk('prosalud-private')->assertExists($originalPath);
        Storage::disk('prosalud-private')->assertExists($firmadoPath);
    }

    public function test_download_original_reads_from_private_disk(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'estado' => 'enviado',
            'is_test' => false,
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath(
            $tracking,
            $this->writeTempPdf('%PDF-1.4 stored original'),
        );
        $tracking->update(['pdf_original_path' => $relative, 'ruta_archivo_pdf' => '/tmp/missing-local.pdf']);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');
        $plainToken = $this->apiTokenForUser($user);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-original',
            [],
            ['prosalud_auth_token' => $plainToken],
            [],
            ['HTTP_ACCEPT' => 'application/pdf'],
        );

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('%PDF-1.4 stored original', $response->getContent());
    }

    public function test_reads_legacy_local_path_when_not_on_private_disk(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create();
        $relative = 'convenios-digital/'.$tracking->id.'/original.pdf';
        $dir = storage_path('app/convenios-digital/'.$tracking->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(storage_path('app/'.$relative), '%PDF-1.4 legacy');

        $contents = app(ConvenioPdfStorageService::class)->get($relative);

        $this->assertSame('%PDF-1.4 legacy', $contents);
    }

    public function test_delete_stored_directory_removes_private_disk_files(): void
    {
        $tracking = ConvenioEmailTracking::factory()->test()->create([
            'documento' => '1111111111',
        ]);
        $storage = app(ConvenioPdfStorageService::class);
        $path = $storage->storeOriginalFromAbsolutePath($tracking, $this->writeTempPdf('%PDF-1.4 delete me'));
        $tracking->update(['pdf_original_path' => $path]);

        $storage->deleteStoredDirectory($tracking->fresh());

        Storage::disk('prosalud-private')->assertMissing($path);
    }

    private function writeTempPdf(string $contents): string
    {
        $dir = storage_path('app/temp/convenios');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/storage-test-'.Str::random(8).'.pdf';
        file_put_contents($path, $contents);

        return $path;
    }

    private function apiTokenForUser(User $user): string
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
