<?php

namespace Tests\Feature;

use App\Jobs\ProcessConvenioPdfZipJob;
use App\Models\ApiToken;
use App\Models\User;
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
}
