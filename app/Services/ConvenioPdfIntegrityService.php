<?php

namespace App\Services;

use App\Enums\ConvenioTextIntegrityStatus;
use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class ConvenioPdfIntegrityService
{
    public function __construct(
        private readonly Parser $parser,
    ) {}

    public function hash(string $contents): string
    {
        return hash('sha256', $contents);
    }

    public function normalizeText(string $text): string
    {
        $normalized = normalizer_normalize($text, \Normalizer::FORM_C);
        if ($normalized === false) {
            $normalized = $text;
        }

        $lower = mb_strtolower($normalized, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $lower);

        return trim((string) $collapsed);
    }

    public function textFingerprint(string $text): string
    {
        return $this->hash($this->normalizeText($text));
    }

    /**
     * @return array{text: string|null, page_count: int|null}
     */
    public function extractPdfMetadata(string $contents): array
    {
        $this->extendExecutionTimeLimit();

        try {
            $document = $this->parser->parseContent($contents);
            $text = $document->getText();
            $pages = $document->getPages();

            return [
                'text' => is_string($text) && trim($text) !== '' ? $text : null,
                'page_count' => count($pages) > 0 ? count($pages) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[CONVENIO INTEGRITY] No se pudo extraer texto del PDF', [
                'error' => $e->getMessage(),
            ]);

            return [
                'text' => null,
                'page_count' => null,
            ];
        }
    }

    /**
     * @return array{page_count: int|null, text_fingerprint: string|null}
     */
    public function originalIntegrityFromContents(string $contents): array
    {
        $metadata = $this->extractPdfMetadata($contents);

        return [
            'page_count' => $metadata['page_count'],
            'text_fingerprint' => is_string($metadata['text']) ? $this->textFingerprint($metadata['text']) : null,
        ];
    }

    public function persistOriginalIntegrityMetadata(ConvenioEmailTracking $tracking, string $contents): void
    {
        $integrity = $this->originalIntegrityFromContents($contents);

        $tracking->update([
            'pdf_original_page_count' => $integrity['page_count'],
            'pdf_original_text_fingerprint' => $integrity['text_fingerprint'],
        ]);
    }

    /**
     * @return array{
     *     status: ConvenioTextIntegrityStatus,
     *     original_page_count: int|null,
     *     signed_page_count: int|null,
     *     mismatch_reason: string|null
     * }
     */
    public function compareContents(string $originalContents, string $signedContents): array
    {
        $originalIntegrity = $this->originalIntegrityFromContents($originalContents);

        return $this->compareSignedAgainstOriginal(
            $originalIntegrity['page_count'],
            $originalIntegrity['text_fingerprint'],
            $signedContents,
        );
    }

    /**
     * @return array{
     *     status: ConvenioTextIntegrityStatus,
     *     original_page_count: int|null,
     *     signed_page_count: int|null,
     *     mismatch_reason: string|null
     * }
     */
    public function compareSignedAgainstOriginal(
        ?int $originalPageCount,
        ?string $originalTextFingerprint,
        string $signedContents,
        ?string $originalContentsForBackfill = null,
    ): array {
        if ($originalTextFingerprint === null && $originalContentsForBackfill !== null) {
            $originalIntegrity = $this->originalIntegrityFromContents($originalContentsForBackfill);
            $originalPageCount = $originalIntegrity['page_count'];
            $originalTextFingerprint = $originalIntegrity['text_fingerprint'];
        }

        $signedMeta = $this->extractPdfMetadata($signedContents);
        $signedText = $signedMeta['text'];
        $signedPages = $signedMeta['page_count'];

        if ($originalTextFingerprint === null || $signedText === null) {
            return [
                'status' => ConvenioTextIntegrityStatus::Unavailable,
                'original_page_count' => $originalPageCount,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => null,
            ];
        }

        if ($originalPageCount !== null && $signedPages !== null && $originalPageCount !== $signedPages) {
            return [
                'status' => null,
                'original_page_count' => $originalPageCount,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => 'page_count_mismatch',
            ];
        }

        if ($this->textFingerprint($signedText) !== $originalTextFingerprint) {
            return [
                'status' => null,
                'original_page_count' => $originalPageCount,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => 'text_mismatch',
            ];
        }

        return [
            'status' => ConvenioTextIntegrityStatus::Matched,
            'original_page_count' => $originalPageCount,
            'signed_page_count' => $signedPages,
            'mismatch_reason' => null,
        ];
    }

    public function hasMismatch(array $comparison): bool
    {
        return ($comparison['mismatch_reason'] ?? null) !== null;
    }

    public function resolveTextIntegrityStatus(array $comparison): ?ConvenioTextIntegrityStatus
    {
        if ($this->hasMismatch($comparison)) {
            return null;
        }

        return $comparison['status'] ?? ConvenioTextIntegrityStatus::Unavailable;
    }

    private function extendExecutionTimeLimit(): void
    {
        $seconds = (int) config('convenio_signing.affiliate_signature_max_execution_seconds', 120);

        if ($seconds > 0) {
            @set_time_limit($seconds);
        }
    }
}
