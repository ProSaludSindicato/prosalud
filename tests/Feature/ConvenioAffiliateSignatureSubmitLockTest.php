<?php

namespace Tests\Feature;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfIntegrityService;
use App\Services\ConvenioPdfStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioAffiliateSignatureSubmitLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
    }

    public function test_submit_returns_conflict_when_signature_submit_lock_is_held(): void
    {
        $plainToken = str_repeat('a', 64);
        $tracking = $this->trackingWithDigitalSigning($plainToken, 'Convenio original ProSalud');

        $lock = Cache::lock('convenio-affiliate-sign:'.$tracking->id, 120);
        $this->assertTrue($lock->get());

        try {
            $signedPdfPath = $this->writeTempPdf($this->createPdfWithText('Convenio original ProSalud'));
            $file = new UploadedFile($signedPdfPath, 'signed.pdf', 'application/pdf', null, true);

            $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
                'pdf' => $file,
                'audit_log' => json_encode($this->sampleAuditLog()),
                'terms_accepted' => '1',
            ])->assertStatus(409)
                ->assertJsonPath('code', 'signature_submit_in_progress');
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAuditLog(): array
    {
        return [
            'sessionId' => 'session-test',
            'startedAt' => now()->toIso8601String(),
            'events' => [],
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

        if (is_string($originalContents) && $originalContents !== '') {
            app(ConvenioPdfIntegrityService::class)->persistOriginalIntegrityMetadata($tracking->fresh(), $originalContents);
        }

        return $tracking->fresh();
    }

    private function createPdfWithText(string $text): string
    {
        return Pdf::loadHTML('<html><body><p>'.e($text).'</p></body></html>')->output();
    }

    private function writeTempPdf(string $contents): string
    {
        $path = sys_get_temp_dir().'/convenio-lock-'.Str::random(8).'.pdf';
        file_put_contents($path, $contents);

        return $path;
    }
}
