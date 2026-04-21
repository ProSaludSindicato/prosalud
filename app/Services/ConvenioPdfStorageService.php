<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ConvenioPdfStorageService
{
    public function relativeDirectory(ConvenioEmailTracking $tracking): string
    {
        return 'convenios-digital/'.$tracking->id;
    }

    /**
     * Copy an existing PDF from disk into private storage for digital signing.
     *
     * @return string Relative path from storage/app (disk "local")
     */
    public function storeOriginalFromAbsolutePath(ConvenioEmailTracking $tracking, string $absolutePdfPath): string
    {
        if (! is_file($absolutePdfPath)) {
            throw new \InvalidArgumentException('El archivo PDF de origen no existe.');
        }

        $relativeDir = $this->relativeDirectory($tracking);
        $relativePath = $relativeDir.'/original.pdf';
        $targetDir = storage_path('app/'.$relativeDir);

        if (! is_dir($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $targetPath = storage_path('app/'.$relativePath);
        if (! @copy($absolutePdfPath, $targetPath)) {
            throw new \RuntimeException('No se pudo copiar el PDF al almacenamiento interno.');
        }

        Log::info('[CONVENIO DIGITAL] PDF original almacenado', [
            'tracking_id' => $tracking->id,
            'relative_path' => $relativePath,
        ]);

        return $relativePath;
    }

    public function absolutePathForRelative(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        return storage_path('app/'.$relativePath);
    }

    /**
     * @return resource|false
     */
    public function readRelativeToStream(string $relativePath)
    {
        $abs = $this->absolutePathForRelative($relativePath);
        if ($abs === null || ! is_file($abs)) {
            return false;
        }

        return fopen($abs, 'rb');
    }
}
