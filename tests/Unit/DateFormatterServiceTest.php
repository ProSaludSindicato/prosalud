<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\DateFormatterService;

class DateFormatterServiceTest extends TestCase
{
    private DateFormatterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DateFormatterService();
    }

    public function test_is_date_field_returns_true_for_known_date_fields()
    {
        $dateFields = [
            'fecha recibido',
            'Fecha Incio Incapacidad',
            'Fecha Fin Incapacidad',
            'FECHA ENVIO',
            'Fecha Expedicion',
            'FECHA EXPEDICION',
            'FECHA INGRESO',
            'FECHA RETIRO',
            'FECHA DE ENTREGA A CAMILA'
        ];

        foreach ($dateFields as $field) {
            $this->assertTrue($this->service->isDateField($field), "Field '{$field}' should be recognized as date field");
        }
    }

    public function test_is_date_field_returns_false_for_non_date_fields()
    {
        $nonDateFields = [
            'N° Radicado',
            'Tipo',
            'Numero Documento',
            'Nombres',
            'Cargo',
            'Hospital',
            'PROCESO',
            'ESTADO BD'
        ];

        foreach ($nonDateFields as $field) {
            $this->assertFalse($this->service->isDateField($field), "Field '{$field}' should not be recognized as date field");
        }
    }

    public function test_convert_date_format_handles_mm_dd_yyyy_format()
    {
        $testCases = [
            '1/1/2025' => '01/01/2025',
            '12/31/2024' => '31/12/2024',
            '6/15/2023' => '15/06/2023',
            '3/8/2022' => '08/03/2022'
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed to convert '{$input}' to '{$expected}'");
        }
    }

    public function test_convert_date_format_handles_english_format()
    {
        $testCases = [
            '20-Mar-25' => '20/03/2025',
            '15-Jan-24' => '15/01/2024',
            '31-Dec-23' => '31/12/2023',
            '1-Jun-22' => '01/06/2022'
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed to convert '{$input}' to '{$expected}'");
        }
    }

    public function test_convert_date_format_returns_original_string_on_parse_failure()
    {
        $invalidDates = [
            'invalid-date',
            'not-a-date',
            '2025-13-32',
            ''
        ];

        foreach ($invalidDates as $invalidDate) {
            $result = $this->service->convertDateFormat($invalidDate);
            $this->assertEquals($invalidDate, $result, "Should return original string for invalid date: '{$invalidDate}'");
        }
    }

    public function test_convert_record_dates_processes_all_date_fields()
    {
        $record = [
            'N° Radicado' => '001',
            'Tipo' => 'CC',
            'fecha recibido' => '1/15/2024',
            'Fecha Incio Incapacidad' => '2/20/2024',
            'Fecha Fin Incapacidad' => '3/25/2024',
            'FECHA ENVIO' => '15-Mar-24',
            'Nombres' => 'Juan Pérez'
        ];

        $result = $this->service->convertRecordDates($record);

        $this->assertEquals('15/01/2024', $result['fecha recibido']);
        $this->assertEquals('20/02/2024', $result['Fecha Incio Incapacidad']);
        $this->assertEquals('25/03/2024', $result['Fecha Fin Incapacidad']);
        $this->assertEquals('15/03/2024', $result['FECHA ENVIO']);
        $this->assertEquals('Juan Pérez', $result['Nombres']); // Non-date field unchanged
    }

    public function test_convert_record_dates_handles_empty_values()
    {
        $record = [
            'fecha recibido' => '',
            'Fecha Incio Incapacidad' => null,
            'FECHA ENVIO' => '   ',
            'Nombres' => 'Juan Pérez'
        ];

        $result = $this->service->convertRecordDates($record);

        $this->assertEquals('', $result['fecha recibido']);
        $this->assertNull($result['Fecha Incio Incapacidad']);
        $this->assertEquals('   ', $result['FECHA ENVIO']);
        $this->assertEquals('Juan Pérez', $result['Nombres']);
    }

    public function test_get_date_fields_returns_correct_list()
    {
        $expectedFields = [
            'fecha recibido',
            'Fecha Incio Incapacidad',
            'Fecha Fin Incapacidad',
            'FECHA ENVIO',
            'Fecha Expedicion',
            'FECHA EXPEDICION',
            'FECHA INGRESO',
            'FECHA RETIRO',
            'FECHA DE ENTREGA A CAMILA'
        ];

        $actualFields = $this->service->getDateFields();

        $this->assertEquals($expectedFields, $actualFields);
    }

    public function test_can_convert_date_returns_true_for_valid_dates()
    {
        $validDates = [
            '1/1/2025',
            '12/31/2024',
            '20-Mar-25',
            '15-Jan-24'
        ];

        foreach ($validDates as $date) {
            $this->assertTrue($this->service->canConvertDate($date), "Date '{$date}' should be convertible");
        }
    }

    public function test_can_convert_date_returns_false_for_invalid_dates()
    {
        $invalidDates = [
            'invalid-date',
            'not-a-date',
            '2025-13-32',
            ''
        ];

        foreach ($invalidDates as $date) {
            $this->assertFalse($this->service->canConvertDate($date), "Date '{$date}' should not be convertible");
        }
    }

    public function test_convert_date_format_handles_edge_cases()
    {
        $edgeCases = [
            '2/29/2024' => '29/02/2024', // Leap year
        ];

        foreach ($edgeCases as $input => $expected) {
            $result = $this->service->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed to convert edge case '{$input}' to '{$expected}'");
        }
    }

    public function test_convert_date_format_handles_whitespace()
    {
        $testCases = [
            '1/1/2025' => '01/01/2025',
            '20-Mar-25' => '20/03/2025'
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed to handle date '{$input}'");
        }
    }

    public function test_convert_record_dates_preserves_non_date_fields()
    {
        $record = [
            'N° Radicado' => '001',
            'Tipo' => 'CC',
            'Numero Documento' => '123456789',
            'Nombres' => 'Juan Pérez',
            'Cargo' => 'Enfermero',
            'Hospital' => 'Hospital Central',
            'fecha recibido' => '1/15/2024'
        ];

        $result = $this->service->convertRecordDates($record);

        // Non-date fields should remain unchanged
        $this->assertEquals('001', $result['N° Radicado']);
        $this->assertEquals('CC', $result['Tipo']);
        $this->assertEquals('123456789', $result['Numero Documento']);
        $this->assertEquals('Juan Pérez', $result['Nombres']);
        $this->assertEquals('Enfermero', $result['Cargo']);
        $this->assertEquals('Hospital Central', $result['Hospital']);

        // Date field should be converted
        $this->assertEquals('15/01/2024', $result['fecha recibido']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
