<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Embeds images into spreadsheets, downloading, shrinking and storing each source only once.
 *
 * Drawings that share a local file are written once into the xlsx media folder, so reusing
 * the prepared file keeps reports small even when the same image appears in many rows.
 */
class ExcelImageEmbedder
{
    /**
     * Pixels stored per displayed point, so images stay sharp when zooming the sheet.
     */
    public const PIXEL_DENSITY = 3;

    public const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    private const CONCURRENT_DOWNLOADS = 10;

    private const DOWNLOAD_TIMEOUT_SECONDS = 15;

    /**
     * Prepared local files keyed by source and display size (null when the source could not be used).
     *
     * @var array<string, string|null>
     */
    private array $preparedFiles = [];

    /**
     * @var array<int, string>
     */
    private array $tempFiles = [];

    /**
     * Place a prepared image in a cell. Returns false when there is nothing to place.
     */
    public function embed(
        Worksheet $sheet,
        ?string $preparedFile,
        string $cell,
        int $width,
        int $height,
        int $offsetX = 5,
        int $offsetY = 5
    ): bool {
        if ($preparedFile === null) {
            return false;
        }

        $drawing = new Drawing;
        $drawing->setPath($preparedFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
        $drawing->setWorksheet($sheet);

        return true;
    }

    /**
     * Download files from an S3 compatible disk concurrently through presigned URLs.
     * Other drivers are left to be read one by one by prepareFromDisk().
     *
     * @param  iterable<int, string|null>  $paths
     */
    public function prefetchFromDisk(string $disk, iterable $paths, int $width, int $height): void
    {
        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return;
        }

        $storage = Storage::disk($disk);
        $urls = [];

        foreach ($paths as $path) {
            if (! $path || isset($urls[$path]) || array_key_exists($this->diskKey($disk, $path, $width, $height), $this->preparedFiles)) {
                continue;
            }

            try {
                $urls[$path] = $storage->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                // Falls back to a direct read in prepareFromDisk()
            }
        }

        foreach ($this->downloadConcurrently($urls) as $path => $content) {
            if ($content !== null) {
                $this->preparedFiles[$this->diskKey($disk, $path, $width, $height)] = $this->storeImage($content, $path, $width, $height);
            }
        }
    }

    /**
     * Prepare an image stored on a disk, trying each disk in order until the file is found.
     *
     * @param  string|array<int, string>  $disks
     */
    public function prepareFromDisk(string|array $disks, ?string $path, int $width, int $height): ?string
    {
        if (! $path) {
            return null;
        }

        $disks = (array) $disks;
        $key = $this->diskKey($disks[0], $path, $width, $height);

        if (array_key_exists($key, $this->preparedFiles)) {
            return $this->preparedFiles[$key];
        }

        $content = null;
        foreach ($disks as $disk) {
            try {
                $content = Storage::disk($disk)->get($path);
            } catch (\Throwable) {
                $content = null;
            }

            if ($content !== null && $content !== '') {
                break;
            }
        }

        if ($content === null || $content === '') {
            Log::warning('Imagen no encontrada en almacenamiento para reporte Excel', ['path' => $path, 'disks' => $disks]);

            return $this->preparedFiles[$key] = null;
        }

        return $this->preparedFiles[$key] = $this->storeImage($content, $path, $width, $height);
    }

    /**
     * Prepare an image whose bytes are already in memory (e.g. base64 signatures stored in the database).
     */
    public function prepareFromContent(string $cacheKey, string $content, int $width, int $height): ?string
    {
        $key = 'content:'.$cacheKey.':'.$width.'x'.$height;

        if (array_key_exists($key, $this->preparedFiles)) {
            return $this->preparedFiles[$key];
        }

        return $this->preparedFiles[$key] = $this->storeImage($content, $cacheKey, $width, $height);
    }

    /**
     * Download several remote images concurrently.
     *
     * @param  iterable<int, string|null>  $urls
     */
    public function prefetchUrls(iterable $urls, int $width, int $height): void
    {
        $pending = [];

        foreach ($urls as $url) {
            if ($url && ! array_key_exists($this->urlKey($url, $width, $height), $this->preparedFiles)) {
                $pending[$url] = $url;
            }
        }

        foreach ($this->downloadConcurrently($pending) as $url => $content) {
            $this->preparedFiles[$this->urlKey($url, $width, $height)] = $content === null
                ? $this->logMissing($url)
                : $this->storeImage($content, $url, $width, $height);
        }
    }

    /**
     * Prepare a remote image, downloading it unless it was already prefetched.
     */
    public function prepareFromUrl(?string $url, int $width, int $height): ?string
    {
        if (! $url) {
            return null;
        }

        $key = $this->urlKey($url, $width, $height);

        if (! array_key_exists($key, $this->preparedFiles)) {
            $this->prefetchUrls([$url], $width, $height);
        }

        return $this->preparedFiles[$key];
    }

    /**
     * Delete every temporary file and forget prepared images.
     */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
        $this->preparedFiles = [];
    }

    /**
     * @param  array<string, string>  $urls  Keyed by the identifier returned in the result.
     * @return array<string, string|null>
     */
    private function downloadConcurrently(array $urls): array
    {
        $results = [];

        foreach (array_chunk($urls, self::CONCURRENT_DOWNLOADS, true) as $chunk) {
            $identifiers = array_keys($chunk);

            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                foreach (array_values($chunk) as $index => $url) {
                    $pool->as((string) $index)->timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get($url);
                }
            });

            foreach ($identifiers as $index => $identifier) {
                $response = $responses[(string) $index] ?? null;

                $results[$identifier] = $response instanceof Response && $response->successful() && $response->body() !== ''
                    ? $response->body()
                    : null;
            }
        }

        return $results;
    }

    /**
     * Shrink the image to fit the display box and write it to a temporary file.
     */
    private function storeImage(string $content, string $source, int $width, int $height): ?string
    {
        if (strlen($content) > self::MAX_SOURCE_BYTES) {
            Log::warning('Imagen excede el tamaño máximo permitido para reporte Excel', [
                'source' => $source,
                'size' => strlen($content),
            ]);

            return null;
        }

        $imageInfo = @getimagesizefromstring($content);

        if (! $imageInfo) {
            Log::warning('Contenido no es una imagen válida para reporte Excel', ['source' => $source]);

            return null;
        }

        $isJpeg = in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/jpg'], true);
        $shrunk = $this->shrink($content, $isJpeg, $width * self::PIXEL_DENSITY, $height * self::PIXEL_DENSITY);

        if ($shrunk !== null) {
            $content = $shrunk;
            $extension = $isJpeg ? 'jpg' : 'png';
        } else {
            $extension = match ($imageInfo['mime'] ?? null) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        $tempBaseFile = tempnam(sys_get_temp_dir(), 'excel_image_');
        $tempImageFile = $tempBaseFile.'.'.$extension;
        file_put_contents($tempImageFile, $content);

        $this->tempFiles[] = $tempBaseFile;
        $this->tempFiles[] = $tempImageFile;

        return $tempImageFile;
    }

    /**
     * Scale the image down to fit within the given box (null when GD cannot handle it).
     */
    private function shrink(string $content, bool $asJpeg, int $maxWidth, int $maxHeight): ?string
    {
        if (! function_exists('imagecreatefromstring') || $maxWidth <= 0 || $maxHeight <= 0) {
            return null;
        }

        $source = @imagecreatefromstring($content);

        if ($source === false) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($asJpeg) {
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        } else {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        $asJpeg ? imagejpeg($target, null, 85) : imagepng($target, null, 9);
        $output = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $output !== false && $output !== '' ? $output : null;
    }

    private function logMissing(string $source): null
    {
        Log::warning('No se pudo descargar imagen para reporte Excel', ['source' => $source]);

        return null;
    }

    private function diskKey(string $disk, string $path, int $width, int $height): string
    {
        return "disk:{$disk}:{$path}:{$width}x{$height}";
    }

    private function urlKey(string $url, int $width, int $height): string
    {
        return 'url:'.strtok($url, '?').':'.$width.'x'.$height;
    }
}
