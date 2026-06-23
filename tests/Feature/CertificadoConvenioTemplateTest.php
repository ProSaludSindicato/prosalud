<?php

namespace Tests\Feature;

use App\Services\CertificadoConvenioService;
use PhpOffice\PhpWord\TemplateProcessor;
use Tests\TestCase;
use ZipArchive;

class CertificadoConvenioTemplateTest extends TestCase
{
    private CertificadoConvenioService $certificadoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->certificadoService = app(CertificadoConvenioService::class);
    }

    public function test_certificado_convenio_template_replaces_core_placeholders(): void
    {
        $outputPath = $this->generarDocumentoDesdePlantilla([
            'NOMBRE_COMPLETO' => 'ALISSON CARO HOLGUIN',
            'DOCUMENTO' => '1.000.918.728',
            'HOSPITAL' => 'Sede Administrativa ProSalud',
            'PROCESO' => 'DIRECTORA ADMINISTRATIVA',
            'FECHA_CERTIFICADO' => '23 de junio de 2026',
            'DESTINATARIO_COMPLETO' => 'A quien corresponda.',
            'SE_ENCUENTRA_ESTUVO' => 'se encuentra',
            'UN_CONVENIO_VARIOS_CONVENIOS' => 'varios Convenios',
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => 'desarrolla actualmente',
            'ATIENDE_ATENDIO' => 'atiende',
            'LISTA_CONVENIOS' => '❖ Sede Administrativa , desde ago. 03/2020 hasta nov. 14/2022',
            'DIA_CERTIFICADO' => '23',
            'MES_CERTIFICADO' => 'junio',
            'ANIO_CERTIFICADO' => '2026',
            'CONSECUTIVO' => '202606230001',
            'MENSAJE_COMPENSACIONES_PARTE1' => '',
            'MENSAJE_COMPENSACIONES_PARTE2' => '',
        ]);

        $documentXml = $this->obtenerDocumentXml($outputPath);
        $plainText = $this->extraerTextoPlano($documentXml);

        $this->assertStringContainsString('ALISSON CARO HOLGUIN', $plainText);
        $this->assertStringContainsString('DIRECTORA ADMINISTRATIVA', $plainText);
        $this->assertStringContainsString('Sede Administrativa ProSalud', $plainText);
        $this->assertStringContainsString('A quien corresponda.', $plainText);

        @unlink($outputPath);
    }

    public function test_certificado_convenio_template_strips_legacy_fields_without_compensaciones(): void
    {
        $outputPath = $this->generarDocumentoDesdePlantilla([
            'NOMBRE_COMPLETO' => 'ALISSON CARO HOLGUIN',
            'DOCUMENTO' => '1.000.918.728',
            'HOSPITAL' => 'Sede Administrativa ProSalud',
            'PROCESO' => 'DIRECTORA ADMINISTRATIVA',
            'FECHA_CERTIFICADO' => '23 de junio de 2026',
            'DESTINATARIO_COMPLETO' => 'A quien corresponda.',
            'SE_ENCUENTRA_ESTUVO' => 'se encuentra',
            'UN_CONVENIO_VARIOS_CONVENIOS' => 'varios Convenios',
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => 'desarrolla actualmente',
            'ATIENDE_ATENDIO' => 'atiende',
            'LISTA_CONVENIOS' => '❖ Sede Administrativa , desde ago. 03/2020 hasta nov. 14/2022',
            'DIA_CERTIFICADO' => '23',
            'MES_CERTIFICADO' => 'junio',
            'ANIO_CERTIFICADO' => '2026',
            'CONSECUTIVO' => '202606230001',
            'MENSAJE_COMPENSACIONES_PARTE1' => '',
            'MENSAJE_COMPENSACIONES_PARTE2' => '',
        ]);

        $documentXml = $this->obtenerDocumentXml($outputPath);
        $plainText = $this->extraerTextoPlano($documentXml);

        $this->assertStringNotContainsString('MERGEFIELD', $documentXml);
        $this->assertStringNotContainsString('<w:instrText', $documentXml);
        $this->assertStringNotContainsString('<w:fldSimple', $documentXml);
        $this->assertStringNotContainsString('fldCharType', $documentXml);

        $this->assertStringNotContainsString('desde hasta', $plainText);
        $this->assertStringNotContainsString('v ,', $plainText);
        $this->assertStringNotContainsString('Acorde al Convenio', $plainText);
        $this->assertStringNotContainsString('para un Total de $ .', $plainText);
        $this->assertStringNotContainsString('compensación básica a recibir', $plainText);

        $this->assertStringNotContainsString('afterLines', $documentXml);
        $this->assertSame(0, $this->contarParrafosVacios($documentXml));
        $this->assertStringContainsString('w:after="240"', $documentXml);
        $this->assertStringContainsString('w:before="520"', $documentXml);
        $this->assertStringContainsString('w:before="640"', $documentXml);
        $this->assertStringContainsString('w:ascii="Calibri"', $documentXml);
        $this->assertStringNotContainsString('asciiTheme="minorHAnsi"', $documentXml);

        $this->assertStringContainsString('ALISSON CARO HOLGUIN', $plainText);
        $this->assertStringContainsString('DIRECTORA ADMINISTRATIVA', $plainText);
        $this->assertStringContainsString('Ha participado en los siguientes convenios:', $plainText);
        $this->assertStringContainsString('Sede Administrativa , desde ago. 03/2020', $plainText);

        @unlink($outputPath);
    }

    public function test_certificado_convenio_template_renders_compensaciones_cleanly(): void
    {
        $outputPath = $this->generarDocumentoDesdePlantilla([
            'NOMBRE_COMPLETO' => 'ALISSON CARO HOLGUIN',
            'DOCUMENTO' => '1.000.918.728',
            'HOSPITAL' => 'Sede Administrativa ProSalud',
            'PROCESO' => 'DIRECTORA ADMINISTRATIVA',
            'FECHA_CERTIFICADO' => '23 de junio de 2026',
            'DESTINATARIO_COMPLETO' => 'A quien corresponda.',
            'SE_ENCUENTRA_ESTUVO' => 'se encuentra',
            'UN_CONVENIO_VARIOS_CONVENIOS' => 'varios Convenios',
            'DESARROLLA_ACTUALMENTE_DESARROLLO' => 'desarrolla actualmente',
            'ATIENDE_ATENDIO' => 'atiende',
            'LISTA_CONVENIOS' => '❖ Sede Administrativa , desde ago. 03/2020 hasta nov. 14/2022',
            'DIA_CERTIFICADO' => '23',
            'MES_CERTIFICADO' => 'junio',
            'ANIO_CERTIFICADO' => '2026',
            'CONSECUTIVO' => '202606230001',
            'MENSAJE_COMPENSACIONES_PARTE1' => 'Con una compensación Básica mensual variable de $1.498.044, para un',
            'MENSAJE_COMPENSACIONES_PARTE2' => 'Total de $1.498.044. En letras: un millón cuatrocientos noventa y ocho mil cuarenta y cuatro pesos.',
        ]);

        $documentXml = $this->obtenerDocumentXml($outputPath);
        $plainText = $this->extraerTextoPlano($documentXml);

        $this->assertStringNotContainsString('MERGEFIELD', $documentXml);
        $this->assertStringNotContainsString('<w:instrText', $documentXml);
        $this->assertStringNotContainsString('Acorde al Convenio', $plainText);

        $this->assertStringContainsString('Con una compensación Básica mensual variable de $1.498.044, para un', $plainText);
        $this->assertStringContainsString('Total de $1.498.044. En letras: un millón cuatrocientos noventa y ocho mil cuarenta y cuatro pesos.', $plainText);

        @unlink($outputPath);
    }

    /**
     * @param  array<string, string>  $datos
     */
    private function generarDocumentoDesdePlantilla(array $datos): string
    {
        $templatePath = base_path('resources/templates/certificado_convenio_template.docx');

        $this->assertFileExists($templatePath);

        $templateProcessor = new TemplateProcessor($templatePath);

        foreach ($datos as $key => $value) {
            $templateProcessor->setValue($key, $value);
        }

        $outputPath = storage_path('app/temp/test_certificado_convenio_template_'.uniqid().'.docx');
        @mkdir(dirname($outputPath), 0755, true);
        $templateProcessor->saveAs($outputPath);

        $this->certificadoService->limpiarCamposLegacyWord($outputPath);

        return $outputPath;
    }

    private function obtenerDocumentXml(string $docxPath): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertNotFalse($documentXml);

        return $documentXml;
    }

    private function extraerTextoPlano(string $documentXml): string
    {
        return strip_tags(str_replace(['<w:tab/>', '<w:br/>'], [' ', "\n"], $documentXml));
    }

    private function contarParrafosVacios(string $documentXml): int
    {
        preg_match_all('#<w:p\b.*?</w:p>#s', $documentXml, $matches);
        $emptyCount = 0;

        foreach ($matches[0] as $paragraph) {
            $text = strip_tags(str_replace(['<w:tab/>', '<w:br/>'], [' ', ' '], $paragraph));
            $hasDrawing = str_contains($paragraph, '<w:drawing')
                || str_contains($paragraph, '<w:pict')
                || str_contains($paragraph, '<w:object');

            if (! $hasDrawing && trim($text) === '') {
                $emptyCount++;
            }
        }

        return $emptyCount;
    }
}
