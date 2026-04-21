<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DelegadosPhotosUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
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

    private function createZipUpload(array $entries): UploadedFile
    {
        $zipPath = storage_path('framework/testing/delegados-photos-'.Str::uuid().'.zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return UploadedFile::fake()->createWithContent('candidatos.zip', $content);
    }

    public function test_upload_photos_zip_replaces_previous_photos_and_stores_new_ones(): void
    {
        Storage::fake('prosalud-public');
        Storage::disk('prosalud-public')->put('delegados/avatars/old.jpg', 'old-image');

        $zipFile = $this->createZipUpload([
            '1001159090.jpg' => 'image-a',
            '1002003000.jpeg' => 'image-b',
            '1004005000.png' => 'image-c',
            'README.txt' => 'ignored',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/photos/upload', ['file' => $zipFile], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('stored_photos_count', 3);

        Storage::disk('prosalud-public')->assertMissing('delegados/avatars/old.jpg');
        Storage::disk('prosalud-public')->assertExists('delegados/avatars/1001159090.jpg');
        Storage::disk('prosalud-public')->assertExists('delegados/avatars/1002003000.jpeg');
        Storage::disk('prosalud-public')->assertExists('delegados/avatars/1004005000.png');
    }

    public function test_upload_photos_zip_returns_422_when_zip_has_no_valid_images(): void
    {
        Storage::fake('prosalud-public');

        $zipFile = $this->createZipUpload([
            'avatar-juan.jpg' => 'image',
            'notes.txt' => 'text',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/photos/upload', ['file' => $zipFile], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_VALID_IMAGES');
    }

    public function test_upload_photos_zip_returns_403_without_permission(): void
    {
        Storage::fake('prosalud-public');

        $zipFile = $this->createZipUpload([
            '1001159090.jpg' => 'image-a',
        ]);

        $user = User::factory()->create();

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/photos/upload', ['file' => $zipFile], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
