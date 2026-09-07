<?php

namespace Tests\Feature;

use App\Jobs\ProcessConvenioPdfZipJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ConvenioImportWithoutDuplicateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => true,
            'convenios.delivery_mode' => 'production',
            'convenios.duplicate_import_check_enabled' => false,
        ]);
    }

    public function test_import_pdf_zip_proceeds_without_duplicate_confirmation_when_check_disabled(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                ]),
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.validos', 1);

        Bus::assertDispatched(ProcessConvenioPdfZipJob::class);
        $this->assertSame(1, ConvenioEmailTracking::query()->count());
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin-no-dup-check-'.Str::random(8).'@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
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
}
