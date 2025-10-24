<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\ExcelReaderService;
use App\Services\DateFormatterService;
use App\Services\IncapacidadService;
use App\Services\LiquidacionService;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test Excel file reading performance
     */
    public function test_excel_file_reading_performance()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $excelReader = new ExcelReaderService();
        
        $startTime = microtime(true);
        $data = $excelReader->readIncapacidadesFile();
        $endTime = microtime(true);
        
        $executionTime = $endTime - $startTime;
        
        // Should read Excel file in less than 3 seconds
        $this->assertLessThan(3, $executionTime, 'Excel file reading should be fast');
        $this->assertNotEmpty($data, 'Excel data should not be empty');
    }

    /**
     * Test liquidaciones Excel file reading performance
     */
    public function test_liquidaciones_excel_file_reading_performance()
    {
        if (!file_exists(public_path('data/LIQUIDACIONES PENDIENTES.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $excelReader = new ExcelReaderService();
        
        $startTime = microtime(true);
        $data = $excelReader->readLiquidacionesFile();
        $endTime = microtime(true);
        
        $executionTime = $endTime - $startTime;
        
        // Should read Excel file in less than 3 seconds
        $this->assertLessThan(3, $executionTime, 'Excel file reading should be fast');
        $this->assertNotEmpty($data, 'Excel data should not be empty');
    }

    /**
     * Test date formatting performance with large dataset
     */
    public function test_date_formatting_performance()
    {
        $dateFormatter = new DateFormatterService();
        
        // Create a large dataset of dates to format
        $dates = [];
        for ($i = 0; $i < 1000; $i++) {
            $dates[] = [
                'fecha recibido' => '1/15/2024',
                'Fecha Incio Incapacidad' => '2/20/2024',
                'Fecha Fin Incapacidad' => '3/25/2024',
                'FECHA ENVIO' => '15-Mar-24',
                'Nombres' => 'Juan Pérez ' . $i
            ];
        }
        
        $startTime = microtime(true);
        
        foreach ($dates as $record) {
            $dateFormatter->convertRecordDates($record);
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should format 1000 records in less than 2 seconds
        $this->assertLessThan(2, $executionTime, 'Date formatting should be fast');
    }

    /**
     * Test incapacidades service performance
     */
    public function test_incapacidades_service_performance()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $excelReader = new ExcelReaderService();
        $dateFormatter = new DateFormatterService();
        $service = new IncapacidadService($excelReader, $dateFormatter);
        
        $startTime = microtime(true);
        $result = $service->searchByDocument('CC', '1000644432', '2025-01-01');
        $endTime = microtime(true);
        
        $executionTime = $endTime - $startTime;
        
        // Should complete search in less than 5 seconds
        $this->assertLessThan(5, $executionTime, 'Incapacidades service should be fast');
        $this->assertIsArray($result);
    }

    /**
     * Test liquidaciones service performance
     */
    public function test_liquidaciones_service_performance()
    {
        if (!file_exists(public_path('data/LIQUIDACIONES PENDIENTES.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $excelReader = new ExcelReaderService();
        $dateFormatter = new DateFormatterService();
        $service = new LiquidacionService($excelReader, $dateFormatter);
        
        $startTime = microtime(true);
        $result = $service->searchByDocument('CC', '1121949803', '2020-01-01');
        $endTime = microtime(true);
        
        $executionTime = $endTime - $startTime;
        
        // Should complete search in less than 5 seconds
        $this->assertLessThan(5, $executionTime, 'Liquidaciones service should be fast');
        $this->assertIsArray($result);
    }

    /**
     * Test API endpoint performance
     */
    public function test_api_endpoint_performance()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $startTime = microtime(true);
        
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1000644432',
            'fecha_expedicion' => '2025-01-01'
        ]);
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should complete API request in less than 6 seconds
        $this->assertLessThan(6, $executionTime, 'API endpoint should be fast');
        $this->assertContains($response->getStatusCode(), [200, 404]);
    }

    /**
     * Test memory usage during Excel processing
     */
    public function test_memory_usage_during_excel_processing()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $initialMemory = memory_get_usage();
        
        $excelReader = new ExcelReaderService();
        $data = $excelReader->readIncapacidadesFile();
        
        $finalMemory = memory_get_usage();
        $memoryUsed = $finalMemory - $initialMemory;
        
        // Should use less than 50MB for Excel processing
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be reasonable');
        $this->assertNotEmpty($data);
    }

    /**
     * Test concurrent API requests performance
     */
    public function test_concurrent_api_requests_performance()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $startTime = microtime(true);
        
        // Simulate 5 concurrent requests
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/incapacidades/search', [
                'tipo' => 'CC',
                'numero_documento' => '1000644432',
                'fecha_expedicion' => '2025-01-01'
            ]);
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should handle 5 concurrent requests in less than 10 seconds
        $this->assertLessThan(10, $executionTime, 'Concurrent requests should be handled efficiently');
        
        // All responses should be valid
        foreach ($responses as $response) {
            $this->assertContains($response->getStatusCode(), [200, 404]);
        }
    }

    /**
     * Test date validation performance with various formats
     */
    public function test_date_validation_performance()
    {
        $dateFormatter = new DateFormatterService();
        
        $testDates = [
            '1/1/2025',
            '12/31/2024',
            '20-Mar-25',
            '15-Jan-24',
            'invalid-date',
            '32/13/2025',
            'not-a-date'
        ];
        
        $startTime = microtime(true);
        
        foreach ($testDates as $date) {
            $dateFormatter->canConvertDate($date);
            $dateFormatter->convertDateFormat($date);
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should process all dates in less than 1 second
        $this->assertLessThan(1, $executionTime, 'Date validation should be fast');
    }

    /**
     * Test Excel file availability check performance
     */
    public function test_excel_file_availability_check_performance()
    {
        $excelReader = new ExcelReaderService();
        
        $startTime = microtime(true);
        
        // Check file availability multiple times
        for ($i = 0; $i < 100; $i++) {
            $excelReader->isFileAvailable();
            $excelReader->isLiquidacionesFileAvailable();
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should check file availability 200 times in less than 1 second
        $this->assertLessThan(1, $executionTime, 'File availability checks should be fast');
    }

    /**
     * Test service instantiation performance
     */
    public function test_service_instantiation_performance()
    {
        $startTime = microtime(true);
        
        // Create multiple service instances
        for ($i = 0; $i < 100; $i++) {
            $excelReader = new ExcelReaderService();
            $dateFormatter = new DateFormatterService();
            $incapacidadService = new IncapacidadService($excelReader, $dateFormatter);
            $liquidacionService = new LiquidacionService($excelReader, $dateFormatter);
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should create 400 service instances in less than 2 seconds
        $this->assertLessThan(2, $executionTime, 'Service instantiation should be fast');
    }

    /**
     * Test API response size performance
     */
    public function test_api_response_size_performance()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $startTime = microtime(true);
        
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1000644432',
            'fecha_expedicion' => '2025-01-01'
        ]);
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should generate response in reasonable time
        $this->assertLessThan(6, $executionTime, 'API response generation should be fast');
        
        if ($response->getStatusCode() === 200) {
            $responseSize = strlen($response->getContent());
            
            // Response should be reasonable size (less than 1MB)
            $this->assertLessThan(1024 * 1024, $responseSize, 'API response should be reasonable size');
        }
    }
}
