<?php

namespace Tests\Feature;

use App\Constants\RequestSubtypes;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Services\RequestExcelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertSame('Subtipo de Solicitud', $sheet->getCell('F1')->getValue());
        $this->assertSame('Detalles Específicos', $sheet->getCell('G1')->getValue());
        $this->assertSame('Fecha Inicio Descanso', $sheet->getCell('H1')->getValue());
        $this->assertSame('Fecha Fin Descanso', $sheet->getCell('I1')->getValue());
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

        $start = $sheet->getCell("H{$row}");
        $end = $sheet->getCell("I{$row}");

        $this->assertSame('2026-11-01', ExcelDate::excelToDateTimeObject($start->getValue())->format('Y-m-d'));
        $this->assertSame('2026-11-15', ExcelDate::excelToDateTimeObject($end->getValue())->format('Y-m-d'));
        $this->assertSame('01/11/2026', $start->getFormattedValue());
        $this->assertStringContainsString('Fecha Inicio Descanso: 01/11/2026', (string) $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('A1:I2', $sheet->getAutoFilter()->getRange());
    }

    public function test_rest_date_columns_are_empty_for_other_request_types(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertNull($sheet->getCell("H{$row}")->getValue());
        $this->assertNull($sheet->getCell("I{$row}")->getValue());
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

        $this->assertSame('no-es-fecha', $sheet->getCell("H{$row}")->getValue());
        $this->assertNull($sheet->getCell("I{$row}")->getValue());
    }

    public function test_payment_verification_subtype_is_exported_in_its_own_column(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => [
                'proceso' => 'NUTRICION',
                'solicitudRelacionadaCon' => RequestSubtypes::INCAPACIDADES,
            ],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertSame('Incapacidades', $sheet->getCell("F{$row}")->getValue());
        $this->assertStringContainsString('NUTRICION', (string) $sheet->getCell("G{$row}")->getValue());
    }

    public function test_subtype_column_is_empty_for_request_types_without_subtypes(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'payload' => ['solicitudRelacionadaCon' => RequestSubtypes::INCAPACIDADES],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertNull($sheet->getCell("F{$row}")->getValue());
    }

    public function test_payment_verification_without_subtype_leaves_column_empty(): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => ['proceso' => 'NUTRICION'],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertNull($sheet->getCell("F{$row}")->getValue());
    }

    #[DataProvider('legacySubtypeVariants')]
    public function test_legacy_subtype_variants_are_grouped_under_the_same_label(string $storedSubtype, string $expectedLabel): void
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => ['solicitudRelacionadaCon' => $storedSubtype],
        ]);

        $sheet = $this->specificDetailsSheet();
        $row = $this->rowsById($sheet)[$request->id];

        $this->assertSame($expectedLabel, $sheet->getCell("F{$row}")->getValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function legacySubtypeVariants(): array
    {
        return [
            'final canónico' => ['COMPENSACIÓN. FINAL (LIQUIDACIÓN)', 'Compensación Final (Liquidación)'],
            'final como etiqueta' => ['Compensación Final', 'Compensación Final (Liquidación)'],
            'final sin tildes' => ['COMPENSACION FINAL', 'Compensación Final (Liquidación)'],
            'anual canónico' => ['COMPENSACIÓN ANUAL DIFERIDA Y/O DESCANSO', 'Compensación Anual Diferida y/o Descanso'],
            'anual como etiqueta' => ['Compensación Anual Diferida', 'Compensación Anual Diferida y/o Descanso'],
            'anual en minúsculas' => ['compensación anual diferida y/o descanso', 'Compensación Anual Diferida y/o Descanso'],
            'mensual sin punto' => ['COMPENSACIÓN MENSUAL', 'Compensación Mensual'],
            'colillas como etiqueta' => ['Duplicado Colillas', 'Duplicado de Colillas'],
            'viáticos con tilde' => ['VIÁTICOS', 'Viáticos'],
            'ceiisas en mayúsculas' => ['CEIISAS', 'Ceiisas'],
            'desconocido' => ['OTRO  TIPO.NUEVO', 'Otro Tipo Nuevo'],
        ];
    }
}
