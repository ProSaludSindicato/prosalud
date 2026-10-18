<?php

namespace Tests\Unit;

use App\Services\AfiliadoService;
use App\Services\DateFormatterService;
use App\Services\ExcelReaderService;
use App\Services\LiquidacionService;
use Tests\TestCase;

class LiquidacionServiceTest extends TestCase
{
    private LiquidacionService $service;

    private $excelReader;

    private $dateFormatter;

    private $afiliadoService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->excelReader = \Mockery::mock(ExcelReaderService::class);
        $this->dateFormatter = \Mockery::mock(DateFormatterService::class);
        $this->afiliadoService = \Mockery::mock(AfiliadoService::class);
        $this->service = new LiquidacionService($this->excelReader, $this->dateFormatter, $this->afiliadoService);
    }

    private function expectValidAfiliado(): void
    {
        $this->afiliadoService
            ->shouldReceive('authenticateAndGetAfiliado')
            ->once()
            ->andReturn(['documento' => 'validated']);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function test_search_by_document_returns_success_when_records_found()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE', 'HOSPITAL', 'PROCESO', 'FECHA INGRESO', 'FECHA RETIRO', 'ESTADO BD', 'REVISION', 'FORMATO DE REVISION FISICO', 'CONVENIOS', 'N° CONVENIOS FIRMADOS', 'N° CONVENIOS PENDIENTES', 'SOLICITUD AFILIACION', 'ACTA DE ENTENDIMIENTO', 'ACTA DE COMPROMISO', 'MOTIVO DE RETIRO', 'CARTA RETIRO', 'DTOS PENDIENTES', 'OBSERVACIONES', 'FECHA DE ENTREGA A CAMILA', 'RESPONSABLE DE ENTREGA CONTABILIDAD'],
            ['CC', '1121949803', '1/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID', 'HMFS - BELLO', 'ENFERMERO(A) PROFESIONAL - URGENCIAS', '7/1/2023', '7/31/2025', 'Retirado', 'SINDY', 'OK', '3', '3', '0', 'PT', 'PT', 'PT', 'RETIRO LIBRE Y VOLUNTARIO', 'OK', 'ACTAS - SOLICITUD DE AFILIACION', 'DOCUMENTOS PENDIENTES', '8/28/2025', 'SINDY'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->andReturn(false);

        $this->expectValidAfiliado();

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);

        $record = $result['data'][0];
        $this->assertArrayNotHasKey('FECHA DE ENTREGA A CAMILA', $record);
        $this->assertArrayNotHasKey('RESPONSABLE DE ENTREGA CONTABILIDAD', $record);
        $this->assertArrayNotHasKey('REVISION', $record);
        $this->assertArrayNotHasKey('FORMATO DE REVISION FISICO', $record);
    }

    public function test_search_by_document_returns_not_found_when_no_records()
    {
        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn([]);

        $result = $this->service->searchByDocument('CC', '999999999', '2020-01-01');

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('No se pudo leer', $result['message']);
    }

    public function test_search_by_document_handles_excel_reader_exception()
    {
        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andThrow(new \Exception('Excel error'));

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Error interno', $result['message']);
    }

    public function test_internal_fields_are_filtered_from_response()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE', 'HOSPITAL', 'PROCESO', 'FECHA INGRESO', 'FECHA RETIRO', 'ESTADO BD', 'REVISION', 'FORMATO DE REVISION FISICO', 'CONVENIOS', 'N° CONVENIOS FIRMADOS', 'N° CONVENIOS PENDIENTES', 'SOLICITUD AFILIACION', 'ACTA DE ENTENDIMIENTO', 'ACTA DE COMPROMISO', 'MOTIVO DE RETIRO', 'CARTA RETIRO', 'DTOS PENDIENTES', 'OBSERVACIONES', 'FECHA DE ENTREGA A CAMILA', 'RESPONSABLE DE ENTREGA CONTABILIDAD'],
            ['CC', '1121949803', '1/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID', 'HMFS - BELLO', 'ENFERMERO(A) PROFESIONAL - URGENCIAS', '7/1/2023', '7/31/2025', 'Retirado', 'SINDY', 'OK', '3', '3', '0', 'PT', 'PT', 'PT', 'RETIRO LIBRE Y VOLUNTARIO', 'OK', 'ACTAS - SOLICITUD DE AFILIACION', 'DOCUMENTOS PENDIENTES', '8/28/2025', 'SINDY'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->andReturn(false);

        $this->expectValidAfiliado();

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('success', $result['status']);
        $record = $result['data'][0];

        // Check that internal fields are filtered out
        $this->assertArrayNotHasKey('FECHA DE ENTREGA A CAMILA', $record);
        $this->assertArrayNotHasKey('RESPONSABLE DE ENTREGA CONTABILIDAD', $record);
        $this->assertArrayNotHasKey('REVISION', $record);
        $this->assertArrayNotHasKey('FORMATO DE REVISION FISICO', $record);

        // Check that public fields are present
        $this->assertEquals('CC', $record['TIPO DE DOCUMENTO']);
        $this->assertEquals('1121949803', $record['N° DOCUMENTO']);
        $this->assertEquals('RAMIREZ MORALES CRISTIAN DAVID', $record['NOMBRE']);
        $this->assertEquals('HMFS - BELLO', $record['HOSPITAL']);
        $this->assertEquals('Retirado', $record['ESTADO BD']);
    }

    public function test_date_conversion_with_liquidaciones_format()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE', 'FECHA INGRESO', 'FECHA RETIRO'],
            ['CC', '1121949803', '1/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID', '7/1/2023', '7/31/2025'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('FECHA EXPEDICION')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('FECHA INGRESO')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('FECHA RETIRO')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('TIPO DE DOCUMENTO')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('N° DOCUMENTO')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('NOMBRE')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('1/1/2020')
            ->andReturn('01/01/2020');

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('7/1/2023')
            ->andReturn('01/07/2023');

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('7/31/2025')
            ->andReturn('31/07/2025');

        $this->expectValidAfiliado();

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('success', $result['status']);
        $record = $result['data'][0];

        $this->assertArrayNotHasKey('FECHA EXPEDICION', $record);
        $this->assertEquals('01/07/2023', $record['FECHA INGRESO']);
        $this->assertEquals('31/07/2025', $record['FECHA RETIRO']);
    }

    public function test_search_by_document_returns_all_records_of_validated_afiliado()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE'],
            ['CC', '1121949803', '1/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID'],
            ['CC', '1121949803', '2/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID OTRO'],
            ['CC', '987654321', '2/1/2020', 'MARIA GARCIA'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->andReturn(false);

        $this->expectValidAfiliado();

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('success', $result['status']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('RAMIREZ MORALES CRISTIAN DAVID', $result['data'][0]['NOMBRE']);
        $this->assertEquals('RAMIREZ MORALES CRISTIAN DAVID OTRO', $result['data'][1]['NOMBRE']);
    }

    public function test_search_by_document_returns_error_when_afiliado_validation_fails()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE'],
            ['CC', '1121949803', '1/1/2020', 'RAMIREZ MORALES CRISTIAN DAVID'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->afiliadoService
            ->shouldReceive('authenticateAndGetAfiliado')
            ->once()
            ->with('CC', '1121949803', '2000-01-02')
            ->andReturn(null);

        $result = $this->service->searchByDocument('CC', '1121949803', '2000-01-02');

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('no son válidos', $result['message']);
    }

    public function test_search_by_document_returns_not_found_when_validated_afiliado_has_no_records()
    {
        $excelData = [
            ['TIPO DE DOCUMENTO', 'N° DOCUMENTO', 'FECHA EXPEDICION', 'NOMBRE'],
            ['CC', '987654321', '2/1/2020', 'MARIA GARCIA'],
        ];

        $this->excelReader
            ->shouldReceive('readLiquidacionesFile')
            ->once()
            ->andReturn($excelData);

        $this->expectValidAfiliado();

        $result = $this->service->searchByDocument('CC', '1121949803', '2020-01-01');

        $this->assertEquals('not_found', $result['status']);
        $this->assertStringContainsString('No se encontraron', $result['message']);
    }
}
