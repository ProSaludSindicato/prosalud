<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\IncapacidadService;
use App\Services\ExcelReaderService;
use App\Services\DateFormatterService;
use Mockery;

class IncapacidadServiceTest extends TestCase
{
    private IncapacidadService $service;
    private $excelReader;
    private $dateFormatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->excelReader = \Mockery::mock(ExcelReaderService::class);
        $this->dateFormatter = \Mockery::mock(DateFormatterService::class);
        $this->service = new IncapacidadService($this->excelReader, $this->dateFormatter);
    }

    public function test_search_by_document_returns_success_when_records_found()
    {
        $excelData = [
            ['N° Radicado', 'fecha recibido', 'Tipo', 'Numero Documento', 'Nombres', 'REPORTE FACTURA', 'REPORTE VIVI'],
            ['001', '12/09/24', 'CC', '123456789', 'Juan Pérez', 'SI', 'si'],
            ['002', '23/10/24', 'CC', '987654321', 'María García', 'NO', 'no']
        ];

        $this->excelReader
            ->shouldReceive('readIncapacidadesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->andReturn(false);

        $result = $this->service->searchByDocument('CC', '123456789', '2024-01-15');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);

        $record = $result['data'][0];
        $this->assertArrayNotHasKey('REPORTE FACTURA', $record);
        $this->assertArrayNotHasKey('REPORTE VIVI', $record);
    }

    public function test_search_by_document_returns_not_found_when_no_records()
    {
        $this->excelReader
            ->shouldReceive('readIncapacidadesFile')
            ->once()
            ->andReturn([]);

        $result = $this->service->searchByDocument('CC', '999999999', '2024-01-15');

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('No se pudo leer', $result['message']);
    }

    public function test_search_by_document_handles_excel_reader_exception()
    {
        $this->excelReader
            ->shouldReceive('readIncapacidadesFile')
            ->once()
            ->andThrow(new \Exception('Excel error'));

        $result = $this->service->searchByDocument('CC', '123456789', '2024-01-15');

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Error interno', $result['message']);
    }

    public function test_internal_fields_are_filtered_from_response()
    {
        $excelData = [
            ['N° Radicado', 'fecha recibido', 'Tipo', 'Numero Documento', 'Nombres', 'REPORTE FACTURA', 'REPORTE VIVI', 'estado'],
            ['001', '12/09/24', 'CC', '123456789', 'Juan Pérez', 'SI', 'si', 'PAGADA']
        ];

        $this->excelReader
            ->shouldReceive('readIncapacidadesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->andReturn(false);

        $result = $this->service->searchByDocument('CC', '123456789', '2024-01-15');

        $this->assertEquals('success', $result['status']);
        $record = $result['data'][0];

        $this->assertArrayNotHasKey('REPORTE FACTURA', $record);
        $this->assertArrayNotHasKey('REPORTE VIVI', $record);

        $this->assertEquals('001', $record['N° Radicado']);
        $this->assertEquals('CC', $record['Tipo']);
        $this->assertEquals('123456789', $record['Numero Documento']);
        $this->assertEquals('Juan Pérez', $record['Nombres']);
        $this->assertEquals('PAGADA', $record['estado']);
    }

    public function test_date_conversion_with_english_format()
    {
        $excelData = [
            ['N° Radicado', 'fecha recibido', 'Tipo', 'Numero Documento', 'Nombres', 'FECHA ENVIO', 'Fecha Incio Incapacidad'],
            ['001', '12/09/24', 'CC', '123456789', 'Juan Pérez', '20-Mar-25', '2/15/2025']
        ];

        $this->excelReader
            ->shouldReceive('readIncapacidadesFile')
            ->once()
            ->andReturn($excelData);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('fecha recibido')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('FECHA ENVIO')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('Fecha Incio Incapacidad')
            ->andReturn(true);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('N° Radicado')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('Tipo')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('Numero Documento')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('isDateField')
            ->with('Nombres')
            ->andReturn(false);

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('12/09/24')
            ->andReturn('09/12/2024');

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('20-Mar-25')
            ->andReturn('20/03/2025');

        $this->dateFormatter
            ->shouldReceive('convertDateFormat')
            ->with('2/15/2025')
            ->andReturn('15/02/2025');

        $result = $this->service->searchByDocument('CC', '123456789', '2024-01-15');

        $this->assertEquals('success', $result['status']);
        $record = $result['data'][0];

        $this->assertEquals('20/03/2025', $record['FECHA ENVIO']);  // English format converted
        $this->assertEquals('15/02/2025', $record['Fecha Incio Incapacidad']);  // MM/DD/YYYY converted
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
