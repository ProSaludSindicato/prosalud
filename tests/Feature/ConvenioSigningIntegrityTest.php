<?php

namespace Tests\Feature;

use App\Enums\ConvenioTextIntegrityStatus;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioSigningIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
    }

    public function test_submit_persists_hashes_ip_user_agent_and_audit_log(): void
    {
        $plainToken = str_repeat('f', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Convenio original ProSalud'));
        $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
                'pdf' => $file,
                'audit_log' => json_encode($this->sampleAuditLog()),
                'terms_accepted' => '1',
            ], [
                'User-Agent' => 'PHPUnit Convenio Signer',
            ])->assertOk()
            ->assertJsonPath('success', true);

        $tracking->refresh();

        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO, $tracking->signing_estado);
        $this->assertNotNull($tracking->pdf_original_sha256);
        $this->assertNotNull($tracking->pdf_firmado_afiliado_sha256);
        $this->assertNotSame($tracking->pdf_original_sha256, $tracking->pdf_firmado_afiliado_sha256);
        $this->assertSame(ConvenioTextIntegrityStatus::Matched, $tracking->text_integrity_status);
        $this->assertSame('203.0.113.10', $tracking->signed_ip);
        $this->assertSame('PHPUnit Convenio Signer', $tracking->signed_user_agent);
        $this->assertSame('session-test', $tracking->signing_audit_log['sessionId'] ?? null);
        $this->assertNotNull($tracking->terms_accepted_at);
    }

    public function test_submit_rejects_pdf_with_altered_text(): void
    {
        $plainToken = str_repeat('e', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Texto completamente diferente'));
        $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'document_integrity_mismatch');

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA, $tracking->signing_estado);
        $this->assertNull($tracking->pdf_firmado_afiliado_path);
    }

    public function test_submit_requires_valid_audit_log(): void
    {
        $plainToken = str_repeat('d', 64);
        $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Convenio original ProSalud'));
        $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => '{"invalid":true}',
            'terms_accepted' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_submit_requires_terms_accepted(): void
    {
        $plainToken = str_repeat('b', 64);
        $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Convenio original ProSalud'));
        $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_show_tracking_includes_integrity_and_hides_signing_token_hash(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $plainToken = str_repeat('c', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Convenio original ProSalud'));
        $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode($this->sampleAuditLog()),
            'terms_accepted' => '1',
        ])->assertOk();

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $plainApi = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainApi),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id,
            [],
            ['prosalud_auth_token' => $plainApi],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('data.integrity.text_integrity_status', 'matched')
            ->assertJsonPath('data.integrity.text_integrity_label', 'Texto íntegro')
            ->assertJsonStructure([
                'data' => [
                    'integrity' => [
                        'pdf_original_sha256',
                        'pdf_firmado_afiliado_sha256',
                        'signed_ip',
                        'signed_user_agent',
                        'terms_accepted_at',
                        'signing_audit_log' => [
                            'sessionId',
                            'events',
                        ],
                    ],
                ],
            ]);

        $this->assertNotNull($response->json('data.integrity.terms_accepted_at'));
        $this->assertSame('Documento abierto', $response->json('data.integrity.signing_audit_log.events.0.label'));
        $this->assertSame('Dibujó su firma', $response->json('data.integrity.signing_audit_log.events.1.label'));
        $this->assertSame('Dibujada', $response->json('data.integrity.signing_audit_log.summary.signatureMethodLabel'));
        $this->assertArrayNotHasKey('signing_token_hash', $response->json('data.tracking'));
        $this->assertArrayNotHasKey('signing_audit_log', $response->json('data.tracking'));
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
                [
                    'id' => 'evt-2',
                    'type' => 'signature_drawn',
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
            'summary' => [
                'documentName' => 'convenio.pdf',
                'totalPages' => 2,
                'signaturePage' => 2,
                'signatureMethod' => 'draw',
                'submittedAt' => now()->toIso8601String(),
                'downloadedAt' => null,
            ],
        ];
    }

    private function trackingWithDigitalSigning(string $plainToken, string $text): ConvenioEmailTracking
    {
        $sourcePdf = $this->writeTempPdf($this->createPdfWithText($text));
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'ruta_archivo_pdf' => $sourcePdf,
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath($tracking, $sourcePdf);
        $originalContents = file_get_contents($sourcePdf);

        $tracking->update([
            'signing_token_hash' => hash('sha256', $plainToken),
            'token_expires_at' => now()->addDay(),
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
            'pdf_original_path' => $relative,
            'pdf_original_sha256' => is_string($originalContents) ? hash('sha256', $originalContents) : null,
        ]);

        return $tracking->fresh();
    }

    private function createPdfWithText(string $text): string
    {
        return Pdf::loadHTML('<html><body><p>'.e($text).'</p></body></html>')->output();
    }

    private function writeTempPdf(string $contents): string
    {
        $path = sys_get_temp_dir().'/convenio-integrity-'.Str::random(8).'.pdf';
        file_put_contents($path, $contents);

        return $path;
    }
}
