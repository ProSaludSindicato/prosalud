<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class AttachmentDeliveryService
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * @return array{content: string, mime_type: string, filename: string, disposition: string}
     */
    public function prepareForDelivery(
        string $content,
        string $mimeType,
        string $originalName,
        string $disposition = 'attachment',
    ): array {
        $mimeType = strtolower($mimeType);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'archivo';

        if ($mimeType === 'application/pdf') {
            return $this->buildResult($content, 'application/pdf', $originalName, $disposition);
        }

        if ($this->isImageMimeType($mimeType)) {
            return $this->prepareImageDelivery($content, $mimeType, $baseName, $disposition);
        }

        return $this->buildResult($content, $mimeType, $originalName, $disposition);
    }

    /**
     * @return array{content: string, mime_type: string, filename: string, disposition: string}
     */
    private function prepareImageDelivery(
        string $content,
        string $mimeType,
        string $baseName,
        string $disposition,
    ): array {
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            Log::warning('No se pudo decodificar imagen para entrega estándar', [
                'mime_type' => $mimeType,
            ]);

            return $this->buildResult($content, $mimeType, $baseName.'.'.$this->extensionFromMime($mimeType), $disposition);
        }

        $usePng = $this->shouldUsePngFallback($image, $mimeType);

        try {
            $pdfContent = $this->convertImageResourceToPdf($image, $usePng);

            if ($pdfContent !== null) {
                imagedestroy($image);

                return $this->buildResult($pdfContent, 'application/pdf', $baseName.'.pdf', $disposition);
            }
        } catch (\Throwable $e) {
            Log::warning('Falló conversión de imagen a PDF, usando formato raster', [
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);
        }

        $raster = $this->encodeRasterImage($image, $usePng);
        imagedestroy($image);

        return $this->buildResult(
            $raster['content'],
            $raster['mime_type'],
            $baseName.'.'.$raster['extension'],
            $disposition,
        );
    }

    private function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::IMAGE_MIME_TYPES, true);
    }

    private function shouldUsePngFallback(\GdImage $image, string $mimeType): bool
    {
        if (in_array($mimeType, ['image/png', 'image/gif'], true)) {
            return $this->imageHasTransparency($image);
        }

        return false;
    }

    private function imageHasTransparency(\GdImage $image): bool
    {
        if (! imageistruecolor($image)) {
            $transparentColor = imagecolortransparent($image);

            return $transparentColor >= 0;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width === 0 || $height === 0) {
            return false;
        }

        $samplePoints = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int) ($width / 2), (int) ($height / 2)],
        ];

        foreach ($samplePoints as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;

            if ($alpha > 0) {
                return true;
            }
        }

        return false;
    }

    private function convertImageResourceToPdf(\GdImage $image, bool $usePng): ?string
    {
        $raster = $this->encodeRasterImage($image, $usePng);

        $pdf = Pdf::loadView('attachments.image-pdf', [
            'imageBase64' => base64_encode($raster['content']),
            'imageMimeType' => $raster['mime_type'],
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('enable-local-file-access', true);
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('margin_top', 20);
        $pdf->setOption('margin_right', 20);
        $pdf->setOption('margin_bottom', 20);
        $pdf->setOption('margin_left', 20);

        $output = $pdf->output();

        return is_string($output) && $output !== '' ? $output : null;
    }

    /**
     * @return array{content: string, mime_type: string, extension: string}
     */
    private function encodeRasterImage(\GdImage $image, bool $usePng): array
    {
        ob_start();

        if ($usePng) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            $content = ob_get_clean();

            return [
                'content' => $content ?: '',
                'mime_type' => 'image/png',
                'extension' => 'png',
            ];
        }

        imagejpeg($image, null, 90);
        $content = ob_get_clean();

        return [
            'content' => $content ?: '',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
        ];
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * @return array{content: string, mime_type: string, filename: string, disposition: string}
     */
    private function buildResult(
        string $content,
        string $mimeType,
        string $filename,
        string $disposition,
    ): array {
        return [
            'content' => $content,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'disposition' => $disposition,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildDownloadResponseHeaders(array $delivery): array
    {
        return [
            'Content-Type' => $delivery['mime_type'],
            'Content-Disposition' => $this->buildContentDispositionHeader(
                $delivery['disposition'],
                $delivery['filename'],
            ),
            'X-Download-Filename' => rawurlencode($delivery['filename']),
        ];
    }

    private function buildContentDispositionHeader(string $disposition, string $filename): string
    {
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'archivo';
        $asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiFallback,
            rawurlencode($filename),
        );
    }
}
