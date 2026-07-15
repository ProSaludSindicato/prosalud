<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Models\RequestResponseAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequestFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        Role::findByName('admin')->givePermissionTo('requests.view');
        $this->admin->assignRole('admin');
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

    private function authenticatedGet(string $uri, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get($uri);
    }

    public function test_request_file_download_converts_webp_image_to_pdf(): void
    {
        Storage::fake('prosalud-private');

        $webpContent = $this->createWebpImage();
        $storedPath = 'request-forms/2026/07/anexo.webp';
        Storage::disk('prosalud-private')->put($storedPath, $webpContent);

        $request = RequestForm::factory()->create([
            'status' => RequestStatuses::PENDING,
            'files' => [
                'anexoDescanso' => [
                    'path' => $storedPath,
                    'disk' => 'prosalud-private',
                    'original_name' => 'anexo-descanso.webp',
                    'mime_type' => 'image/webp',
                    'size' => strlen($webpContent),
                    'original_key' => 'anexoDescanso',
                ],
            ],
        ]);

        $response = $this->authenticatedGet(
            "/api/requests/{$request->id}/files/anexoDescanso?disposition=attachment",
            $this->admin,
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_response_attachment_download_converts_webp_image_to_pdf(): void
    {
        Storage::fake('prosalud-private');

        $webpContent = $this->createWebpImage();
        $storedPath = 'request-responses/2026/07/response-attachment-1.webp';
        Storage::disk('prosalud-private')->put($storedPath, $webpContent);

        $request = RequestForm::factory()->create([
            'status' => RequestStatuses::PENDING,
        ]);

        $requestResponse = RequestResponse::query()->create([
            'request_form_id' => $request->id,
            'responded_by' => $this->admin->id,
            'status' => RequestStatuses::COMPLETED,
            'email_subject' => 'Respuesta',
            'email_body' => 'Cuerpo',
            'created_at' => now(),
        ]);

        $attachment = RequestResponseAttachment::query()->create([
            'request_response_id' => $requestResponse->id,
            'path' => $storedPath,
            'original_name' => 'soporte.webp',
            'created_at' => now(),
        ]);

        $response = $this->authenticatedGet(
            "/api/requests/responses/{$requestResponse->id}/attachments/{$attachment->id}?disposition=inline",
            $this->admin,
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertTrue($response->headers->has('X-Download-Filename'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_request_file_download_returns_pdf_unchanged(): void
    {
        Storage::fake('prosalud-private');

        $pdfContent = '%PDF-1.4 fake pdf';
        $storedPath = 'request-forms/2026/07/documento.pdf';
        Storage::disk('prosalud-private')->put($storedPath, $pdfContent);

        $request = RequestForm::factory()->create([
            'files' => [
                'certificado' => [
                    'path' => $storedPath,
                    'disk' => 'prosalud-private',
                    'original_name' => 'certificado.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => strlen($pdfContent),
                    'original_key' => 'certificado',
                ],
            ],
        ]);

        $response = $this->authenticatedGet(
            "/api/requests/{$request->id}/files/certificado",
            $this->admin,
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertSame($pdfContent, $response->getContent());
    }

    private function createWebpImage(): string
    {
        $image = imagecreatetruecolor(20, 20);
        $color = imagecolorallocate($image, 0, 128, 255);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagewebp($image, null, 85);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content ?: '';
    }
}
