<?php

namespace Tests\Unit;

use App\Models\WellnessDeliveryRequest;
use App\Models\WellnessDeliveryType;
use App\Services\WellnessDeliveryExcelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WellnessDeliveryExcelExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_report_includes_hospital_summary_and_respects_tipo_entrega_filter(): void
    {
        $this->withoutMiddleware();

        $tipo = WellnessDeliveryType::create([
            'nombre' => 'Detalle día de la mujer',
            'activo' => true,
            'modo_acceso' => 'abierto',
            'fecha_desde' => '2026-03-01',
            'fecha_hasta' => '2026-03-31',
            'created_by' => null,
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => null,
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '100000001',
            'nombre_afiliado' => 'Afiliado Norte',
            'hospital' => 'Hospital Norte',
            'fecha_expedicion' => '2020-01-01',
            'beneficiarios' => [],
            'firma' => 'firma-1',
            'estado' => 'entregado',
            'cantidad_entregada' => 2,
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => null,
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '100000002',
            'nombre_afiliado' => 'Afiliado Sur',
            'hospital' => 'Hospital Sur',
            'fecha_expedicion' => '2020-01-02',
            'beneficiarios' => [],
            'firma' => 'firma-2',
            'estado' => 'pendiente',
        ]);

        $service = app(WellnessDeliveryExcelExportService::class);

        $filePath = $service->generateReport([
            'tipo_entrega' => $tipo->id,
            'estado' => null,
            'fecha_desde' => null,
            'fecha_hasta' => null,
        ], [
            'include_firmas' => false,
        ]);

        $this->assertFileExists($filePath);

        $spreadsheet = IOFactory::load($filePath);

        $summarySheet = $spreadsheet->getSheetByName('Resumen');
        $this->assertNotNull($summarySheet);

        $hasHospitalSummaryTitle = false;
        $hospitalNamesFound = [];

        for ($row = 1; $row <= 200; $row++) {
            $value = (string) $summarySheet->getCell("A{$row}")->getValue();
            if ($value === 'RESUMEN POR HOSPITAL') {
                $hasHospitalSummaryTitle = true;

                // Leer algunas filas siguientes para verificar que aparezcan los hospitales
                for ($checkRow = $row + 2; $checkRow <= $row + 10; $checkRow++) {
                    $hospitalValue = (string) $summarySheet->getCell("A{$checkRow}")->getValue();
                    if ($hospitalValue !== '') {
                        $hospitalNamesFound[] = $hospitalValue;
                    }
                }

                break;
            }
        }

        $this->assertTrue($hasHospitalSummaryTitle, 'No se encontró la sección de RESUMEN POR HOSPITAL en la hoja de Resumen');
        $this->assertNotEmpty($hospitalNamesFound, 'No se encontraron hospitales listados en el RESUMEN POR HOSPITAL');
        $this->assertContains('Hospital Norte', $hospitalNamesFound);
        $this->assertContains('Hospital Sur', $hospitalNamesFound);

        @unlink($filePath);
    }
}
