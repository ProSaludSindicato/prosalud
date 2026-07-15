<?php

namespace Tests\Unit;

use App\Services\AttachmentDeliveryService;
use Tests\TestCase;

class AttachmentDeliveryServiceTest extends TestCase
{
    private AttachmentDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttachmentDeliveryService;
    }

    public function test_pdf_file_is_returned_unchanged(): void
    {
        $pdfContent = '%PDF-1.4 fake pdf content';

        $result = $this->service->prepareForDelivery(
            $pdfContent,
            'application/pdf',
            'documento.pdf',
        );

        $this->assertSame($pdfContent, $result['content']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertSame('documento.pdf', $result['filename']);
        $this->assertSame('attachment', $result['disposition']);
    }

    public function test_word_document_is_returned_unchanged(): void
    {
        $content = 'fake-docx-content';

        $result = $this->service->prepareForDelivery(
            $content,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'carta.docx',
        );

        $this->assertSame($content, $result['content']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $result['mime_type']);
        $this->assertSame('carta.docx', $result['filename']);
    }

    public function test_jpeg_image_is_delivered_as_pdf(): void
    {
        $jpegContent = $this->createJpegImage();

        $result = $this->service->prepareForDelivery(
            $jpegContent,
            'image/jpeg',
            'foto.jpg',
        );

        $this->assertStringStartsWith('%PDF', $result['content']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertSame('foto.pdf', $result['filename']);
    }

    public function test_webp_image_is_delivered_as_pdf(): void
    {
        $webpContent = $this->createWebpImage();

        $result = $this->service->prepareForDelivery(
            $webpContent,
            'image/webp',
            'evidencia.webp',
        );

        $this->assertStringStartsWith('%PDF', $result['content']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertSame('evidencia.pdf', $result['filename']);
    }

    public function test_png_image_is_delivered_as_pdf(): void
    {
        $pngContent = $this->createTransparentPngImage();

        $result = $this->service->prepareForDelivery(
            $pngContent,
            'image/png',
            'sello.png',
        );

        $this->assertStringStartsWith('%PDF', $result['content']);
        $this->assertSame('application/pdf', $result['mime_type']);
        $this->assertSame('sello.pdf', $result['filename']);
    }

    public function test_inline_disposition_is_preserved(): void
    {
        $jpegContent = $this->createJpegImage();

        $result = $this->service->prepareForDelivery(
            $jpegContent,
            'image/jpeg',
            'foto.jpg',
            'inline',
        );

        $this->assertSame('inline', $result['disposition']);
    }

    private function createJpegImage(): string
    {
        $image = imagecreatetruecolor(20, 20);
        $color = imagecolorallocate($image, 255, 0, 0);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagejpeg($image, null, 90);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content ?: '';
    }

    private function createWebpImage(): string
    {
        $image = imagecreatetruecolor(20, 20);
        $color = imagecolorallocate($image, 0, 128, 255);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagewebp($image, null, 85);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content ?: '';
    }

    private function createTransparentPngImage(): string
    {
        $image = imagecreatetruecolor(20, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        ob_start();
        imagepng($image, null, 6);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content ?: '';
    }
}
