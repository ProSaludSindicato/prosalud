<?php

namespace Tests\Feature;

use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Services\RequestExcelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class RequestExcelExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function specificDetailsSheet(): Worksheet
    {
        $filePath = app(RequestExcelExportService::class)->generateReport([
            'request_type' => 'all',
            'date_range' => ['include_all' => true],
        ]);

        $sheet = IOFactory::load($filePath)->getSheetByName('Detalles Específicos');
        @unlink($filePath);

        $this->assertNotNull($sheet);

        return $sheet;
    }

    /**
     * @return array<string, int>
     */
    private function rowsById(Worksheet $sheet): array
    {
        $rows = [];
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $rows[(string) $sheet->getCell("A{$row}")->getValue()] = $row;
        }

        return $rows;
    }

    public function test_specific_details_sheet_includes_rest_date_columns(): void
    {
        $sheet = $this->specificDetailsSheet();

        $this->assertSame('Detalles Específicos', $sheet->getCell('F1')->getValue());
        $this->assertSame('Fecha Inicio Descanso', $sheet->getCell('G1')->getValue());
        $this->assertSame('Fecha Fin Descanso', $sheet->getCell('H1')->getValue());
    }

    public function test_rest_compensation_dates_are_exported_as_excel_dates(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::COMPENSACION_DESCANSO,
            'payload' => [
                'proceso' => 'APH',
                'fechaInicioDescanso' => '2026-11-01',
                'fechaFinalizacionDescanso' => '2026-11-15',
            ],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $start = $sheet->getCell("G{$row}");
        $end = $sheet->getCell("H{$row}");

        $this->assertSame('2026-11-01', ExcelDate::excelToDateTimeObject($start->getValue())->format('Y-m-d'));
        $this->assertSame('2026-11-15', ExcelDate::excelToDateTimeObject($end->getValue())->format('Y-m-d'));
        $this->assertSame('01/11/2026', $start->getFormattedValue());
        $this->assertStringContainsString('Fecha Inicio Descanso: 01/11/2026', (string) $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('A1:H2', $sheet->getAutoFilter()->getRange());
    }

    public function test_rest_date_columns_are_empty_for_other_request_types(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertNull($sheet->getCell("G{$row}")->getValue());
        $this->assertNull($sheet->getCell("H{$row}")->getValue());
    }

    public function test_invalid_rest_date_is_kept_as_raw_text(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::COMPENSACION_DESCANSO,
            'payload' => [
                'fechaInicioDescanso' => 'no-es-fecha',
                'fechaFinalizacionDescanso' => '',
            ],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertSame('no-es-fecha', $sheet->getCell("G{$row}")->getValue());
        $this->assertNull($sheet->getCell("H{$row}")->getValue());
    }
}
