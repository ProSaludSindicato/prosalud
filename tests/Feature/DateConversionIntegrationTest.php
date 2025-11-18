<?php

namespace Tests\Feature;

use App\Services\DateFormatterService;
use Tests\TestCase;

class DateConversionIntegrationTest extends TestCase
{
    private DateFormatterService $dateFormatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dateFormatter = new DateFormatterService();
    }

    /**
     * Test comprehensive date conversion scenarios.
     */
    public function testComprehensiveDateConversionScenarios()
    {
        $testCases = [
            // MM/DD/YYYY format
            '2/15/2025' => '15/02/2025',
            '12/31/2024' => '31/12/2024',
            '1/1/2025' => '01/01/2025',

            // English format (d-M-y)
            '20-Mar-25' => '20/03/2025',
            '15-Jan-24' => '15/01/2024',
            '31-Dec-25' => '31/12/2025',

            // Edge cases
            '1/1/2025' => '01/01/2025',  // Single digit month/day
            '12/25/2024' => '25/12/2024',  // Christmas
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->dateFormatter->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed conversion for: $input");
        }
    }

    /**
     * Test all English month abbreviations.
     */
    public function testAllEnglishMonthAbbreviations()
    {
        $months = [
            'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
            'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
            'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
        ];

        foreach ($months as $month => $expectedMonth) {
            $input = "15-{$month}-25";
            $expected = "15/{$expectedMonth}/2025";

            $result = $this->dateFormatter->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Failed for month: $month");
        }
    }

    /**
     * Test date field detection.
     */
    public function testDateFieldDetection()
    {
        $dateFields = [
            'fecha recibido',
            'Fecha Incio Incapacidad',
            'Fecha Fin Incapacidad',
            'FECHA ENVIO',
        ];

        $nonDateFields = [
            'Nombres',
            'Cargo',
            'estado',
            'Hospital',
            'ADMINISTRADORA',
        ];

        foreach ($dateFields as $field) {
            $this->assertTrue($this->dateFormatter->isDateField($field), "Should detect as date field: $field");
        }

        foreach ($nonDateFields as $field) {
            $this->assertFalse($this->dateFormatter->isDateField($field), "Should not detect as date field: $field");
        }
    }

    /**
     * Test record date conversion with mixed formats.
     */
    public function testRecordDateConversionMixedFormats()
    {
        $record = [
            'N° Radicado' => '001',
            'Nombres' => 'Juan Pérez',
            'fecha recibido' => '2/15/2025',           // MM/DD/YYYY
            'Fecha Incio Incapacidad' => '2/17/2025',  // MM/DD/YYYY
            'Fecha Fin Incapacidad' => '2/20/2025',   // MM/DD/YYYY
            'FECHA ENVIO' => '25-Mar-25',             // English format
            'estado' => 'PAGADA',
            'Cargo' => 'AUXILIAR',
        ];

        $result = $this->dateFormatter->convertRecordDates($record);

        // Verify non-date fields remain unchanged
        $this->assertEquals('001', $result['N° Radicado']);
        $this->assertEquals('Juan Pérez', $result['Nombres']);
        $this->assertEquals('PAGADA', $result['estado']);
        $this->assertEquals('AUXILIAR', $result['Cargo']);

        // Verify date fields are converted
        $this->assertEquals('15/02/2025', $result['fecha recibido']);
        $this->assertEquals('17/02/2025', $result['Fecha Incio Incapacidad']);
        $this->assertEquals('20/02/2025', $result['Fecha Fin Incapacidad']);
        $this->assertEquals('25/03/2025', $result['FECHA ENVIO']);
    }

    /**
     * Test invalid date handling.
     */
    public function testInvalidDateHandling()
    {
        $invalidDates = [
            'invalid-date',
            '2025-03-20',  // ISO format (not supported)
            'random-text',
            '',
        ];

        foreach ($invalidDates as $invalidDate) {
            $result = $this->dateFormatter->convertDateFormat($invalidDate);
            $this->assertEquals($invalidDate, $result, "Should return original for invalid date: $invalidDate");
        }
    }

    /**
     * Test canConvertDate validation.
     */
    public function testCanConvertDateValidation()
    {
        // Valid dates
        $this->assertTrue($this->dateFormatter->canConvertDate('2/15/2025'));
        $this->assertTrue($this->dateFormatter->canConvertDate('20-Mar-25'));
        $this->assertTrue($this->dateFormatter->canConvertDate('31-Dec-24'));

        // Invalid dates
        $this->assertFalse($this->dateFormatter->canConvertDate('invalid-date'));
        $this->assertFalse($this->dateFormatter->canConvertDate('2025-03-20'));  // ISO format
        $this->assertFalse($this->dateFormatter->canConvertDate('random-text'));
    }

    /**
     * Test edge cases for date conversion.
     */
    public function testEdgeCasesDateConversion()
    {
        $edgeCases = [
            // Leap year
            '2/29/2024' => '29/02/2024',  // Valid leap year

            // Year boundaries
            '12/31/2024' => '31/12/2024',
            '1/1/2025' => '01/01/2025',

            // Single digit months/days
            '1/1/2025' => '01/01/2025',
            '12/1/2024' => '01/12/2024',
        ];

        foreach ($edgeCases as $input => $expected) {
            $result = $this->dateFormatter->convertDateFormat($input);
            $this->assertEquals($expected, $result, "Edge case failed for: $input");
        }
    }
}
