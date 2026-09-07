<?php

namespace Tests\Feature;

use App\Services\AfiliadoService;
use App\Services\ConvenioGenerationService;
use App\Services\DocxToPdfService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ConvenioGenerationUnprotectedPdfTest extends TestCase
{
    private string $docxPath;

    private string $tmpPdfPath;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = storage_path('app/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $this->docxPath = $tempDir.'/convenio_unprotected_test.docx';
        $this->tmpPdfPath = $tempDir.'/convenio_unprotected_test.pdf';

        file_put_contents($this->docxPath, 'fake-docx');
        file_put_contents($this->tmpPdfPath, '%PDF-1.4 fake convenio');
    }

    protected function tearDown(): void
    {
        foreach ([$this->docxPath, $this->tmpPdfPath] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $outputPdf = storage_path('app/temp/convenios/convenio_unprotected_test.pdf');
        if (file_exists($outputPdf)) {
            @unlink($outputPdf);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_finalize_convenio_pdf_always_disables_wordtopdf_protection(): void
    {
        /** @var DocxToPdfService&MockInterface $converter */
        $converter = Mockery::mock(DocxToPdfService::class);
        $converter->shouldReceive('convert')
            ->once()
            ->with($this->docxPath, true, false)
            ->andReturn([
                'path' => 'tmp/convenio_unprotected_test.pdf',
                'content' => null,
                'size' => 21,
            ]);

        $this->app->instance(DocxToPdfService::class, $converter);

        $service = new ConvenioGenerationService($this->app->make(AfiliadoService::class));
        $result = $service->finalizeConvenioPdf($this->docxPath);

        $this->assertSame('convenio_unprotected_test.pdf', $result['nombre']);
        $this->assertSame('pdf', $result['tipo']);
        $this->assertFileExists($result['ruta']);
        $this->assertFileDoesNotExist($this->docxPath);
    }
}
