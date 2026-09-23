<?php

namespace Tests\Unit;

use App\Models\SocioDemographicSurvey;
use App\Services\SocioDemographicSurveyExcelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SocioDemographicSurveyExcelExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_report_builds_detail_sheet_without_range_error(): void
    {
        SocioDemographicSurvey::query()->create([
            'id' => '1000000001',
            'survey_type' => 'active_affiliate',
            'correo' => 'test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '1000000001',
            'hospital' => 'HOSP1',
            'profesion' => 'Enfermera',
            'datos_sociodemograficos' => ['estado_civil' => 'soltero'],
            'datos_consumo' => ['consumo_licor' => 'no'],
            'condiciones_salud' => ['hipertension' => 'no'],
            'limitaciones_fisicas' => ['ninguna' => true],
            'recomendacion_restriccion_laboral' => 'no',
            'numero_documento_firma' => '1000000001',
            'created_at' => now()->setYear(2026),
        ]);

        $service = app(SocioDemographicSurveyExcelExportService::class);

        $filePath = $service->generateReport([
            'year' => 2026,
            'survey_type' => 'all',
            'include_signatures' => false,
            'date_range' => [
                'include_all' => true,
            ],
        ]);

        $this->assertFileExists($filePath);

        $spreadsheet = IOFactory::load($filePath);

        $this->assertNotNull($spreadsheet->getSheetByName('Resumen y Estadísticas'));
        $this->assertNotNull($spreadsheet->getSheetByName('Detalle Encuestas'));
        $this->assertNotNull($spreadsheet->getSheetByName('Beneficiarios'));

        $detailSheet = $spreadsheet->getSheetByName('Detalle Encuestas');
        $this->assertSame('ID', (string) $detailSheet->getCell('A1')->getValue());
        $this->assertSame('1000000001', (string) $detailSheet->getCell('A2')->getValue());

        @unlink($filePath);
    }
}
