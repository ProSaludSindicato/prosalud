<?php

namespace App\Services;

use App\Support\ConvenioPreGeneratedPdfFilename;
use Illuminate\Support\Str;
use ZipArchive;

class ConvenioPdfZipImportService
{
    public function __construct(
        private readonly ConvenioPdfStorageService $pdfStorageService,
    ) {}

    /**
     * @return array{
     *     valid: list<array{entry: string, filename: string, documento: string, nombre_convenio: string}>,
     *     rejected: list<array{entry: string, reason: string}>
     * }
     */
    public function scanZipEntries(string $zipPath): array
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath);

        if ($openResult !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo ZIP.');
        }

        $valid = [];
        $rejected = [];
        $maxPdfs = max(1, (int) config('convenios.zip_max_pdfs', 200));
        $pdfCount = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (! is_string($entryName) || $entryName === '') {
                    continue;
                }

                if ($this->shouldSkipEntry($entryName)) {
                    continue;
                }

                if (! str_ends_with(strtolower($entryName), '.pdf')) {
                    $rejected[] = [
                        'entry' => basename($entryName),
                        'reason' => 'Solo se permiten archivos PDF.',
                    ];

                    continue;
                }

                $pdfCount++;
                if ($pdfCount > $maxPdfs) {
                    $rejected[] = [
                        'entry' => basename($entryName),
                        'reason' => 'Se superó el máximo de PDFs permitidos en el ZIP ('.$maxPdfs.').',
                    ];

                    continue;
                }

                $filename = basename($entryName);
                $parsed = ConvenioPreGeneratedPdfFilename::parse($filename);

                if ($parsed === null) {
                    $rejected[] = [
                        'entry' => $filename,
                        'reason' => 'Nombre inválido. Use: SEDE - NOMBRE COMPLETO - DOCUMENTO.pdf',
                    ];

                    continue;
                }

                $contents = $zip->getFromIndex($index);
                if ($contents === false || ! ConvenioPreGeneratedPdfFilename::isValidPdfMagic($contents)) {
                    $rejected[] = [
                        'entry' => $filename,
                        'reason' => 'El archivo no es un PDF válido.',
                    ];

                    continue;
                }

                $valid[] = [
                    'entry' => $entryName,
                    'filename' => $parsed['filename'],
                    'documento' => $parsed['documento'],
                    'nombre_convenio' => $parsed['nombre_convenio'],
                ];
            }
        } finally {
            $zip->close();
        }

        return [
            'valid' => $valid,
            'rejected' => $rejected,
        ];
    }

    public function storeZipOnDisk(string $localZipPath): string
    {
        $relativePath = 'convenios/inbox/zips/'.Str::uuid().'.zip';
        $contents = file_get_contents($localZipPath);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException('No se pudo leer el archivo ZIP subido.');
        }

        $stored = $this->pdfStorageService->disk()->put($relativePath, $contents, [
            'visibility' => 'private',
            'ContentType' => 'application/zip',
        ]);

        if ($stored === false) {
            throw new \RuntimeException('No se pudo almacenar el ZIP en el almacenamiento privado.');
        }

        return $relativePath;
    }

    public function materializeZipToTemp(string $relativeZipPath): string
    {
        $disk = $this->pdfStorageService->disk();
        if (! $disk->exists($relativeZipPath)) {
            throw new \RuntimeException('No se pudo descargar el ZIP desde almacenamiento.');
        }

        $tempDir = storage_path('app/temp/convenios/zips');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/'.Str::uuid().'.zip';
        $stream = $disk->readStream($relativeZipPath);
        if (! is_resource($stream)) {
            throw new \RuntimeException('No se pudo descargar el ZIP desde almacenamiento.');
        }

        try {
            $destination = fopen($tempPath, 'w');
            if ($destination === false) {
                throw new \RuntimeException('No se pudo materializar el ZIP temporalmente.');
            }

            try {
                if (stream_copy_to_stream($stream, $destination) === false) {
                    throw new \RuntimeException('No se pudo materializar el ZIP temporalmente.');
                }
            } finally {
                fclose($destination);
            }
        } finally {
            fclose($stream);
        }

        if (! is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);
            throw new \RuntimeException('No se pudo descargar el ZIP desde almacenamiento.');
        }

        return $tempPath;
    }

    public function deleteStoredZip(string $relativeZipPath): void
    {
        $this->pdfStorageService->disk()->delete($relativeZipPath);
    }

    /**
     * @param  list<array{entry: string, filename: string}>  $validEntries
     */
    public function extractEntryToTemp(string $zipPath, array $entryMeta): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('No se pudo abrir el ZIP para extracción.');
        }

        try {
            $contents = $zip->getFromName($entryMeta['entry']);
            if ($contents === false || ! ConvenioPreGeneratedPdfFilename::isValidPdfMagic($contents)) {
                throw new \RuntimeException('No se pudo extraer un PDF válido del ZIP.');
            }

            $tempDir = storage_path('app/temp/convenios/extracted');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir.'/'.Str::uuid().'_'.$entryMeta['filename'];
            if (file_put_contents($tempPath, $contents) === false) {
                throw new \RuntimeException('No se pudo escribir el PDF extraído.');
            }

            return $tempPath;
        } finally {
            $zip->close();
        }
    }

    private function shouldSkipEntry(string $entryName): bool
    {
        if (str_contains($entryName, '..')) {
            return true;
        }

        if (str_starts_with($entryName, '__MACOSX/')) {
            return true;
        }

        if (str_ends_with($entryName, '/')) {
            return true;
        }

        return basename($entryName) === '.DS_Store';
    }
}
