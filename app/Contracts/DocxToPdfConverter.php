<?php

namespace App\Contracts;

interface DocxToPdfConverter
{
    /**
     * Convierte un archivo .docx a .pdf.
     *
     * @return array{path?: string, content?: string|null, size: int}
     */
    public function convert(string $docxPath, bool $saveToStorage = true): array;

    public function isAvailable(): bool;
}
