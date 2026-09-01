<?php

namespace Tests\Unit;

use App\Services\ConvenioExcelTemplateExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ConvenioExcelTemplateExportTest extends TestCase
{
    public function test_template_does_not_include_send_email_column(): void
    {
        $service = app(ConvenioExcelTemplateExportService::class);
        $tempPath = $service->generateTemplate();

        try {
            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();

            $headers = [];
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headers[] = mb_strtolower(trim((string) $sheet->getCell($colLetter.'1')->getValue()), 'UTF-8');
            }

            $this->assertContains('email', $headers);
            $this->assertNotContains('enviar email', $headers);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
