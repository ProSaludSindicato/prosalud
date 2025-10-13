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

    public function test_is_date_field_returns_true_for_date_fields()
    {
        $this->assertTrue($this->service->isDateField('fecha recibido'));
        $this->assertTrue($this->service->isDateField('Fecha Incio Incapacidad'));
        $this->assertTrue($this->service->isDateField('Fecha Fin Incapacidad'));
        $this->assertTrue($this->service->isDateField('FECHA ENVIO'));
    }

    public function test_is_date_field_returns_false_for_non_date_fields()
    {
        $this->assertFalse($this->service->isDateField('Nombres'));
        $this->assertFalse($this->service->isDateField('Cargo'));
        $this->assertFalse($this->service->isDateField('estado'));
    }

    public function test_convert_date_format_converts_mm_dd_yyyy_correctly()
    {
        $result = $this->service->convertDateFormat('2/15/2025');
        $this->assertEquals('15/02/2025', $result);
    }

    public function test_convert_date_format_converts_english_format_correctly()
    {
        $result = $this->service->convertDateFormat('20-Mar-25');
        $this->assertEquals('20/03/2025', $result);
    }

    public function test_convert_date_format_handles_various_english_months()
    {
        $testCases = [
            '15-Jan-25' => '15/01/2025',
            '20-Feb-25' => '20/02/2025',
            '10-Mar-25' => '10/03/2025',
            '25-Apr-25' => '25/04/2025',
            '30-May-25' => '30/05/2025',
            '12-Jun-25' => '12/06/2025',
            '18-Jul-25' => '18/07/2025',
            '22-Aug-25' => '22/08/2025',
            '05-Sep-25' => '05/09/2025',
            '28-Oct-25' => '28/10/2025',
            '14-Nov-25' => '14/11/2025',
            '31-Dec-25' => '31/12/2025'
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->service->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed for input: $input");
        }
    }

    public function test_convert_date_format_handles_invalid_date()
    {
        $result = $this->service->convertDateFormat('invalid-date');
        $this->assertEquals('invalid-date', $result);
    }

    public function test_convert_record_dates_converts_mixed_date_formats()
    {
        $record = [
            'Nombres' => 'Juan Pérez',
            'Fecha Incio Incapacidad' => '2/15/2025',  // MM/DD/YYYY format
            'Fecha Fin Incapacidad' => '2/17/2025',    // MM/DD/YYYY format
            'FECHA ENVIO' => '20-Mar-25',              // English format
            'estado' => 'PAGADA'
        ];

        $result = $this->service->convertRecordDates($record);

        $this->assertEquals('Juan Pérez', $result['Nombres']);
        $this->assertEquals('15/02/2025', $result['Fecha Incio Incapacidad']);
        $this->assertEquals('17/02/2025', $result['Fecha Fin Incapacidad']);
        $this->assertEquals('20/03/2025', $result['FECHA ENVIO']);  // Converted from English
        $this->assertEquals('PAGADA', $result['estado']);
    }

    public function test_can_convert_date_returns_true_for_valid_dates()
    {
        // MM/DD/YYYY format
        $this->assertTrue($this->service->canConvertDate('2/15/2025'));
        $this->assertTrue($this->service->canConvertDate('12/31/2024'));
        
        // English format
        $this->assertTrue($this->service->canConvertDate('20-Mar-25'));
        $this->assertTrue($this->service->canConvertDate('15-Jan-24'));
    }

    public function test_can_convert_date_returns_false_for_invalid_dates()
    {
        $this->assertFalse($this->service->canConvertDate('invalid-date'));
        $this->assertFalse($this->service->canConvertDate('2025-03-20'));   // ISO format
        $this->assertFalse($this->service->canConvertDate('random-text'));
    }
}
