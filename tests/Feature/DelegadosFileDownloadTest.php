<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DelegadosFileDownloadTest extends TestCase
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

    public function test_user_with_permission_can_download_delegados_file(): void
    {
        Storage::fake('prosalud-private');
        Storage::disk('prosalud-private')->put('data/DELEGADOS.xlsx', 'fake-xlsx-bytes');

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/delegados-file/download');

        $response->assertOk();
        $response->assertDownload('DELEGADOS.xlsx');
        $this->assertSame('fake-xlsx-bytes', $response->streamedContent());
    }

    public function test_download_returns_404_when_file_missing(): void
    {
        Storage::fake('prosalud-private');
        Storage::fake('local');

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/delegados-file/download', ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'FILE_NOT_FOUND');
    }

    public function test_download_returns_403_without_permission(): void
    {
        Storage::fake('prosalud-private');
        Storage::disk('prosalud-private')->put('data/DELEGADOS.xlsx', 'x');

        $user = User::factory()->create();

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/delegados-file/download', ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
