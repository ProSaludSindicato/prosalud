<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioPublicSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
    }

    private function createOnePagePdf(): string
    {
        $path = sys_get_temp_dir().'/convenio-test-'.Str::random(8).'.pdf';
        $minimalPdf = <<<'PDF'
%PDF-1.1
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>
endobj
4 0 obj
<< /Length 35 >>
stream
BT /F1 12 Tf 50 100 Td (Test PDF) Tj ET
endstream
endobj
xref
0 5
0000000000 65535 f
0000000010 00000 n
0000000063 00000 n
0000000120 00000 n
0000000200 00000 n
trailer
<< /Size 5 /Root 1 0 R >>
startxref
285
%%EOF
PDF;

        file_put_contents($path, $minimalPdf);

        return $path;
    }

    private function trackingWithDigitalSigning(string $plainToken): ConvenioEmailTracking
    {
        $sourcePdf = $this->createOnePagePdf();
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'ruta_archivo_pdf' => $sourcePdf,
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $sourcePdf);

        $tracking->update([
            'signing_token_hash' => hash('sha256', $plainToken),
            'token_expires_at' => now()->addDay(),
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
            'pdf_original_path' => $relative,
        ]);

        return $tracking->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAuditLog(): array
    {
        return [
            'sessionId' => 'session-test',
            'startedAt' => now()->toIso8601String(),
            'events' => [
                [
                    'id' => 'evt-1',
                    'type' => 'document_opened',
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
            'summary' => [
                'documentName' => 'convenio.pdf',
                'totalPages' => 1,
                'signaturePage' => 1,
                'signatureMethod' => 'draw',
                'submittedAt' => now()->toIso8601String(),
                'downloadedAt' => null,
            ],
        ];
    }

    public function test_metadata_returns_404_for_unknown_token(): void
    {
        $token = str_repeat('b', 64);

        $this->getJson('/api/public/convenio-firma/'.$token.'/metadata')
            ->assertStatus(404);
    }

    public function test_metadata_and_document_pdf_and_submit_flow(): void
    {
        $plainToken = str_repeat('a', 64);
        $this->trackingWithDigitalSigning($plainToken);

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_sign', true)
            ->assertJsonPath('data.header_title', config('convenio_signing.viewer_header_title'))
            ->assertJsonPath('data.firmado_afiliado_at', null)
            ->assertJsonPath('data.rechazado_at', null);

        $this->get('/api/public/convenio-firma/'.$plainToken.'/document.pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $uploadPdf = $this->createOnePagePdf();
        $file = new UploadedFile($uploadPdf, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '1',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '1',
        ])->assertStatus(403);

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_sign', false)
            ->assertJsonPath('data.signing_estado', ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO)
            ->assertJsonStructure([
                'data' => [
                    'firmado_afiliado_at',
                ],
            ]);
    }

    public function test_metadata_returns_404_for_short_token(): void
    {
        $this->getJson('/api/public/convenio-firma/'.str_repeat('x', 16).'/metadata')
            ->assertStatus(404);
    }

    public function test_metadata_header_title_uses_tracking_override_when_set(): void
    {
        $plainToken = str_repeat('d', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken);
        $tracking->update(['viewer_header_title' => 'Título personalizado del visor']);

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('data.header_title', 'Título personalizado del visor');
    }

    public function test_download_firmado_afiliado_after_submit(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $plainToken = str_repeat('c', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken);

        $uploadPdf = $this->createOnePagePdf();
        $file = new UploadedFile($uploadPdf, 'signed.pdf', 'application/pdf', null, true);
        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '1',
        ])->assertOk();

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO, $tracking->signing_estado);
        $this->assertNotNull($tracking->pdf_firmado_afiliado_path);
        $this->assertStringEndsWith('/firmado-afiliado.pdf', $tracking->pdf_firmado_afiliado_path);
        Storage::disk('prosalud-private')->assertExists($tracking->pdf_firmado_afiliado_path);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $plainApi = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainApi),
            'expires_at' => now()->addDay(),
        ]);

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-final',
            [],
            ['prosalud_auth_token' => $plainApi],
            [],
            ['HTTP_ACCEPT' => 'application/pdf'],
        )->assertOk();
    }

    public function test_download_original_returns_pdf_when_stored_copy_exists(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $sourcePdf = $this->createOnePagePdf();
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'ruta_archivo_pdf' => $sourcePdf,
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $sourcePdf);
        $tracking->update(['pdf_original_path' => $relative]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $plainApi = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainApi),
            'expires_at' => now()->addDay(),
        ]);

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-original',
            [],
            ['prosalud_auth_token' => $plainApi],
            [],
            ['HTTP_ACCEPT' => 'application/pdf'],
        )->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_download_original_returns_404_when_no_file(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'ruta_archivo_pdf' => storage_path('app/missing-convenio-test.pdf'),
            'pdf_original_path' => null,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $plainApi = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainApi),
            'expires_at' => now()->addDay(),
        ]);

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-original',
            [],
            ['prosalud_auth_token' => $plainApi],
        )->assertStatus(404)->assertJsonPath('success', false);
    }
}
