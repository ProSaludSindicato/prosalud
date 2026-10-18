<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RespondCompressedFilesFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('prosalud-private');
        Mail::fake();
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    public function test_response_is_aborted_when_compressed_files_cannot_be_stored(): void
    {
        $this->actingAsAdmin();
        $form = RequestForm::factory()->create(['request_type' => 'otro-tramite', 'status' => RequestStatuses::PENDING]);

        RequestResponse::creating(fn () => throw new \RuntimeException('db down'));

        $this->post("/api/requests/{$form->id}/respond", [
            'status' => 'COMPLETED',
            'email_subject' => 'Respuesta',
            'email_body' => 'Sus archivos adjuntos.',
            'attachments' => [UploadedFile::fake()->create('soporte.zip', 10, 'application/zip')],
        ], ['Accept' => 'application/json'])->assertStatus(500)->assertJsonPath('success', false);

        Mail::assertNothingSent();
        $this->assertSame(RequestStatuses::PENDING, $form->fresh()->status);
    }
}
