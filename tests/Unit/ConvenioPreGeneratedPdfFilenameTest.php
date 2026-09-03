<?php

namespace Tests\Unit;

use App\Support\ConvenioPreGeneratedPdfFilename;
use PHPUnit\Framework\TestCase;

class ConvenioPreGeneratedPdfFilenameTest extends TestCase
{
    public function test_parses_standard_filename(): void
    {
        $parsed = ConvenioPreGeneratedPdfFilename::parse(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf'
        );

        $this->assertNotNull($parsed);
        $this->assertSame('1035228093', $parsed['documento']);
        $this->assertSame('BELLO', $parsed['nombre_convenio']);
        $this->assertSame('ACEVEDO MONTOYA LUISA FERNANDA', $parsed['nombre_afiliado']);
        $this->assertNull($parsed['periodo']);
        $this->assertSame('BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf', $parsed['filename']);
    }

    public function test_parses_filename_with_period_suffix(): void
    {
        $parsed = ConvenioPreGeneratedPdfFilename::parse(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093 - 20262.pdf'
        );

        $this->assertNotNull($parsed);
        $this->assertSame('1035228093', $parsed['documento']);
        $this->assertSame('20262', $parsed['periodo']);
    }

    public function test_rejects_filename_without_separator(): void
    {
        $this->assertNull(ConvenioPreGeneratedPdfFilename::parse('convenio_invalido.pdf'));
    }

    public function test_rejects_filename_without_document_number(): void
    {
        $this->assertNull(ConvenioPreGeneratedPdfFilename::parse('BELLO - NOMBRE COMPLETO - .pdf'));
    }

    public function test_detects_pdf_magic_bytes(): void
    {
        $this->assertTrue(ConvenioPreGeneratedPdfFilename::isValidPdfMagic('%PDF-1.4 test'));
        $this->assertFalse(ConvenioPreGeneratedPdfFilename::isValidPdfMagic('not-a-pdf'));
    }
}
