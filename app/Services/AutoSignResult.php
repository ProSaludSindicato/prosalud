<?php

namespace App\Services;

class AutoSignResult
{
    public function __construct(
        public readonly string $pdfContents,
        public readonly ?string $detectionMethod,
        public readonly ?int $page,
        public readonly ?int $durationMs,
    ) {}
}
