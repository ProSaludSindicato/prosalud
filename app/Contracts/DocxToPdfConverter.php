<?php

namespace App\Contracts;

interface DocxToPdfConverter
{
    /**
     * Convierte un archivo .docx a .pdf.
     *
     * @param  bool|null  $protectPdf  null usa la config global; false/true la sobrescribe.
     * @return array{path?: string, content?: string|null, size: int}
     */
    public function convert(string $docxPath, bool $saveToStorage = true, ?bool $protectPdf = null): array;

    public function isAvailable(): bool;
}
