<?php

namespace App\Services;

use App\Enums\ConvenioTextIntegrityStatus;
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

    /**
     * @return array{text: string|null, page_count: int|null}
     */
    public function extractPdfMetadata(string $contents): array
    {
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
     * @return array{
     *     status: ConvenioTextIntegrityStatus,
     *     original_page_count: int|null,
     *     signed_page_count: int|null,
     *     mismatch_reason: string|null
     * }
     */
    public function compareContents(string $originalContents, string $signedContents): array
    {
        $originalMeta = $this->extractPdfMetadata($originalContents);
        $signedMeta = $this->extractPdfMetadata($signedContents);

        $originalText = $originalMeta['text'];
        $signedText = $signedMeta['text'];
        $originalPages = $originalMeta['page_count'];
        $signedPages = $signedMeta['page_count'];

        if ($originalText === null || $signedText === null) {
            return [
                'status' => ConvenioTextIntegrityStatus::Unavailable,
                'original_page_count' => $originalPages,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => null,
            ];
        }

        if ($originalPages !== null && $signedPages !== null && $originalPages !== $signedPages) {
            return [
                'status' => null,
                'original_page_count' => $originalPages,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => 'page_count_mismatch',
            ];
        }

        $normalizedOriginal = $this->normalizeText($originalText);
        $normalizedSigned = $this->normalizeText($signedText);

        if ($normalizedOriginal !== $normalizedSigned) {
            return [
                'status' => null,
                'original_page_count' => $originalPages,
                'signed_page_count' => $signedPages,
                'mismatch_reason' => 'text_mismatch',
            ];
        }

        return [
            'status' => ConvenioTextIntegrityStatus::Matched,
            'original_page_count' => $originalPages,
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
}
