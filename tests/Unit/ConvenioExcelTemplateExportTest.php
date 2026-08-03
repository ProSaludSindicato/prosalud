<?php

namespace Tests\Unit;

use App\Services\ConvenioExcelTemplateExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ConvenioExcelTemplateExportTest extends TestCase
{
    public function test_template_does_not_prefill_send_email_column(): void
    {
        $service = app(ConvenioExcelTemplateExportService::class);
        $tempPath = $service->generateTemplate();

        try {
            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();

            $sendEmailColumn = null;
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $header = (string) $sheet->getCell($colLetter.'1')->getValue();
                if (mb_strtolower(trim($header), 'UTF-8') === 'enviar email') {
                    $sendEmailColumn = $col;
                    break;
                }
            }

            $this->assertNotNull($sendEmailColumn, 'No se encontró la columna Enviar Email en la plantilla');

            $sendEmailColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($sendEmailColumn);

            for ($row = 2; $row <= 20; $row++) {
                $value = $sheet->getCell($sendEmailColLetter.$row)->getValue();
                $this->assertTrue(
                    $value === null || trim((string) $value) === '',
                    "La fila {$row} de Enviar Email no debe tener valor por defecto",
                );
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
