<?php

namespace App\Services;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioDisplayFilename;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConvenioPdfStorageService
{
    public function diskName(): string
    {
        $configured = (string) config('convenios.storage_disk', 'prosalud-private');
        $disks = config('filesystems.disks', []);

        if (isset($disks[$configured])) {
            return $configured;
        }

        return 'local';
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function relativeDirectory(ConvenioEmailTracking $tracking): string
    {
        $scope = $tracking->is_test ? 'test' : 'production';
        $created = $tracking->created_at ?? now();
        $documento = preg_replace('/[^0-9]/', '', (string) $tracking->documento) ?: 'sin-documento';

        return sprintf(
            'convenios/%s/%s/%s/%s/%d',
            $scope,
            $created->format('Y'),
            $created->format('m'),
            $documento,
            $tracking->id,
        );
    }

    public function relativePath(ConvenioEmailTracking $tracking, ConvenioPdfStage $stage): string
    {
        return $this->relativeDirectory($tracking).'/'.ConvenioDisplayFilename::storageFileName($tracking, $stage);
    }

    public function migrateToMnemonicPath(ConvenioEmailTracking $tracking, ConvenioPdfStage $stage): ?string
    {
        $currentPath = match ($stage) {
            ConvenioPdfStage::Original => $tracking->pdf_original_path,
            ConvenioPdfStage::FirmadoAfiliado => $tracking->pdf_firmado_afiliado_path,
            ConvenioPdfStage::Final => $tracking->pdf_final_path,
        };

        if (! is_string($currentPath) || $currentPath === '') {
            return null;
        }

        $expectedPath = $this->relativePath($tracking, $stage);
        if ($currentPath === $expectedPath) {
            return $currentPath;
        }

        $contents = $this->get($currentPath);
        if ($contents === null) {
            return null;
        }

        $stored = $this->disk()->put($expectedPath, $contents, [
            'visibility' => 'private',
            'ContentType' => 'application/pdf',
        ]);

        if ($stored === false) {
            throw new \RuntimeException('No se pudo migrar el PDF al nombre nemotécnico.');
        }

        if ($this->disk()->exists($currentPath)) {
            $this->disk()->delete($currentPath);
        }

        $legacy = $this->legacyAbsolutePath($currentPath);
        if (is_file($legacy)) {
            @unlink($legacy);
        }

        Log::info('[CONVENIO STORAGE] PDF migrado a nombre nemotécnico', [
            'tracking_id' => $tracking->id,
            'stage' => $stage->value,
            'from' => $currentPath,
            'to' => $expectedPath,
        ]);

        return $expectedPath;
    }

    public function storeFromAbsolutePath(ConvenioEmailTracking $tracking, ConvenioPdfStage $stage, string $absolutePdfPath): string
    {
        if (! is_file($absolutePdfPath)) {
            throw new \InvalidArgumentException('El archivo PDF de origen no existe.');
        }

        $contents = file_get_contents($absolutePdfPath);
        if ($contents === false || $contents === '') {
            throw new \RuntimeException('No se pudo leer el PDF de origen.');
        }

        $relativePath = $this->relativePath($tracking, $stage);
        $stored = $this->disk()->put($relativePath, $contents, [
            'visibility' => 'private',
            'ContentType' => 'application/pdf',
        ]);

        if ($stored === false) {
            throw new \RuntimeException('No se pudo guardar el PDF en el almacenamiento privado.');
        }

        Log::info('[CONVENIO STORAGE] PDF almacenado', [
            'tracking_id' => $tracking->id,
            'stage' => $stage->value,
            'disk' => $this->diskName(),
            'relative_path' => $relativePath,
        ]);

        return $relativePath;
    }

    public function storeOriginalFromAbsolutePath(ConvenioEmailTracking $tracking, string $absolutePdfPath): string
    {
        return $this->storeFromAbsolutePath($tracking, ConvenioPdfStage::Original, $absolutePdfPath);
    }

    public function exists(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        if ($this->disk()->exists($relativePath)) {
            return true;
        }

        return is_file($this->legacyAbsolutePath($relativePath));
    }

    public function get(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        if ($this->disk()->exists($relativePath)) {
            $contents = $this->disk()->get($relativePath);

            return is_string($contents) && $contents !== '' ? $contents : null;
        }

        $legacy = $this->legacyAbsolutePath($relativePath);
        if (! is_file($legacy)) {
            return null;
        }

        $contents = file_get_contents($legacy);

        return $contents === false || $contents === '' ? null : $contents;
    }

    public function hasOriginal(ConvenioEmailTracking $tracking): bool
    {
        if ($this->exists($tracking->pdf_original_path)) {
            return true;
        }

        return is_string($tracking->ruta_archivo_pdf)
            && $tracking->ruta_archivo_pdf !== ''
            && is_file($tracking->ruta_archivo_pdf);
    }

    public function hasStage(ConvenioEmailTracking $tracking, ConvenioPdfStage $stage): bool
    {
        $path = match ($stage) {
            ConvenioPdfStage::Original => $tracking->pdf_original_path,
            ConvenioPdfStage::FirmadoAfiliado => $tracking->pdf_firmado_afiliado_path,
            ConvenioPdfStage::Final => $tracking->pdf_final_path,
        };

        return $this->exists($path);
    }

    public function originalContents(ConvenioEmailTracking $tracking): ?string
    {
        $fromStorage = $this->get($tracking->pdf_original_path);
        if ($fromStorage !== null) {
            return $fromStorage;
        }

        if (is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)) {
            $contents = file_get_contents($tracking->ruta_archivo_pdf);

            return $contents === false || $contents === '' ? null : $contents;
        }

        return null;
    }

    public function materializeOriginalToTemp(ConvenioEmailTracking $tracking): ?string
    {
        $contents = $this->originalContents($tracking);
        if ($contents === null) {
            return null;
        }

        if (is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)) {
            return $tracking->ruta_archivo_pdf;
        }

        $outputDir = storage_path('app/temp/convenios');
        if (! is_dir($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $tempPath = $outputDir.'/s3-'.$tracking->id.'-'.uniqid('', true).'.pdf';
        if (file_put_contents($tempPath, $contents) === false) {
            return null;
        }

        return $tempPath;
    }

    public function downloadResponse(string $contents, string $downloadName, string $disposition = 'attachment'): Response
    {
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $downloadName) ?: 'convenio.pdf';
        $asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);

        $dispositionHeader = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiFallback,
            rawurlencode($downloadName),
        );

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $dispositionHeader,
            'X-Download-Filename' => rawurlencode($downloadName),
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    public function deleteStoredDirectory(ConvenioEmailTracking $tracking): void
    {
        $paths = array_filter([
            $tracking->pdf_original_path,
            $tracking->pdf_firmado_afiliado_path,
            $tracking->pdf_final_path,
        ], fn ($path): bool => is_string($path) && $path !== '');

        foreach ($paths as $path) {
            if ($this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }

            $legacy = $this->legacyAbsolutePath($path);
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }

        $directories = array_unique(array_filter(array_map(
            fn (string $path): string => dirname($path),
            $paths,
        ), fn (string $dir): bool => $dir !== '.' && $dir !== ''));

        foreach ($directories as $directory) {
            $this->disk()->deleteDirectory($directory);
        }

        $legacyDir = storage_path('app/convenios-digital/'.$tracking->id);
        if (is_dir($legacyDir)) {
            File::deleteDirectory($legacyDir);
        }
    }

    private function legacyAbsolutePath(string $relativePath): string
    {
        return storage_path('app/'.$relativePath);
    }
}
