<?php

namespace Tests\Unit;

use App\Enums\ConvenioTextIntegrityStatus;
use App\Services\ConvenioPdfIntegrityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class ConvenioPdfIntegrityServiceTest extends TestCase
{
    private ConvenioPdfIntegrityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ConvenioPdfIntegrityService::class);
    }

    public function test_hash_returns_sha256_hex(): void
    {
        $this->assertSame(
            hash('sha256', 'contenido'),
            $this->service->hash('contenido'),
        );
    }

    public function test_normalize_text_collapses_whitespace_and_lowercases(): void
    {
        $this->assertSame(
            'convenio de afiliación',
            $this->service->normalizeText("  CONVENIO   de\n Afiliación  "),
        );
    }

    public function test_compare_contents_returns_matched_for_same_text(): void
    {
        $original = $this->createPdfWithText('Convenio original ProSalud');
        $signed = $this->createPdfWithText('Convenio original ProSalud');

        $result = $this->service->compareContents($original, $signed);

        $this->assertSame(ConvenioTextIntegrityStatus::Matched, $result['status']);
        $this->assertNull($result['mismatch_reason']);
        $this->assertFalse($this->service->hasMismatch($result));
    }

    public function test_compare_signed_against_cached_original_metadata(): void
    {
        $original = $this->createPdfWithText('Convenio original ProSalud');
        $signed = $this->createPdfWithText('Convenio original ProSalud');
        $integrity = $this->service->originalIntegrityFromContents($original);

        $result = $this->service->compareSignedAgainstOriginal(
            $integrity['page_count'],
            $integrity['text_fingerprint'],
            $signed,
        );

        $this->assertSame(ConvenioTextIntegrityStatus::Matched, $result['status']);
        $this->assertNull($result['mismatch_reason']);
    }

    public function test_compare_contents_returns_mismatch_for_different_text(): void
    {
        $original = $this->createPdfWithText('Convenio original ProSalud');
        $signed = $this->createPdfWithText('Convenio alterado sin autorizacion');

        $result = $this->service->compareContents($original, $signed);

        $this->assertNull($result['status']);
        $this->assertSame('text_mismatch', $result['mismatch_reason']);
        $this->assertTrue($this->service->hasMismatch($result));
    }

    private function createPdfWithText(string $text): string
    {
        return Pdf::loadHTML('<html><body><p>'.e($text).'</p></body></html>')->output();
    }
}
