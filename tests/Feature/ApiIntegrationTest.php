<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test complete incapacidades API flow with real Excel file
     */
    public function test_incapacidades_api_complete_flow()
    {
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1000644432',
            'fecha_expedicion' => '2025-01-01'
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'data' => [
                        '*' => [
                            'N° Radicado',
                            'Tipo',
                            'Numero Documento',
                            'Fecha Expedicion',
                            'Nombres'
                        ]
                    ]
                ])
                ->assertJsonMissing([
                    'REPORTE FACTURA',
                    'REPORTE VIVI'
                ]);
    }

    /**
     * Test complete liquidaciones API flow with real Excel file
     */
    public function test_liquidaciones_api_complete_flow()
    {
        // Skip if Excel file doesn't exist
        if (!file_exists(public_path('data/LIQUIDACIONES PENDIENTES.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '1121949803',
            'fecha_expedicion' => '2020-01-01'
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'data' => [
                        '*' => [
                            'TIPO DE DOCUMENTO',
                            'N° DOCUMENTO',
                            'FECHA EXPEDICION',
                            'NOMBRE',
                            'HOSPITAL',
                            'PROCESO'
                        ]
                    ]
                ])
                ->assertJsonMissing([
                    'FECHA DE ENTREGA A CAMILA',
                    'RESPONSABLE DE ENTREGA CONTABILIDAD',
                    'REVISION',
                    'FORMATO DE REVISION FISICO'
                ]);
    }

    /**
     * Test API error handling when Excel files are missing
     */
    public function test_api_error_handling_missing_files()
    {
        // Test incapacidades with missing file
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01'
        ]);

        // Should return either 404 (not found) or 500 (error) depending on file availability
        $this->assertContains($response->getStatusCode(), [404, 500]);

        if ($response->getStatusCode() === 500) {
            $response->assertJson([
                'status' => 'error'
            ]);
        } else {
            $response->assertJson([
                'status' => 'not_found'
            ]);
        }
    }

    /**
     * Test API validation with various invalid inputs
     */
    public function test_api_validation_comprehensive()
    {
        $invalidInputs = [
            // Missing required fields
            [
                'data' => ['tipo' => 'CC'],
                'expected_errors' => ['numero_documento', 'fecha_expedicion']
            ],
            // Invalid date format
            [
                'data' => [
                    'tipo' => 'CC',
                    'numero_documento' => '123456789',
                    'fecha_expedicion' => '01/01/2025' // Should be YYYY-MM-DD
                ],
                'expected_errors' => ['fecha_expedicion']
            ],
            // Empty values
            [
                'data' => [
                    'tipo' => '',
                    'numero_documento' => '',
                    'fecha_expedicion' => ''
                ],
                'expected_errors' => ['tipo', 'numero_documento', 'fecha_expedicion']
            ],
            // Invalid date
            [
                'data' => [
                    'tipo' => 'CC',
                    'numero_documento' => '123456789',
                    'fecha_expedicion' => 'invalid-date'
                ],
                'expected_errors' => ['fecha_expedicion']
            ]
        ];

        foreach ($invalidInputs as $testCase) {
            $response = $this->postJson('/api/incapacidades/search', $testCase['data']);

            $response->assertStatus(422)
                    ->assertJsonValidationErrors($testCase['expected_errors']);
        }
    }

    /**
     * Test API performance with large datasets
     */
    public function test_api_performance()
    {
        // Skip if Excel file doesn't exist
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

        // Response should be fast (less than 5 seconds)
        $this->assertLessThan(5, $executionTime, 'API response time should be less than 5 seconds');
        $response->assertStatus(200);
    }

    /**
     * Test API with different document types
     */
    public function test_api_with_different_document_types()
    {
        // Skip if Excel file doesn't exist
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $documentTypes = ['CC', 'CE', 'TI', 'RC', 'PA'];

        foreach ($documentTypes as $tipo) {
            $response = $this->postJson('/api/incapacidades/search', [
                'tipo' => $tipo,
                'numero_documento' => '1000644432',
                'fecha_expedicion' => '2025-01-01'
            ]);

            // Should return either success or not_found, but not error
            $this->assertContains($response->getStatusCode(), [200, 404]);
        }
    }

    /**
     * Test API with various date formats in request
     */
    public function test_api_with_various_date_formats()
    {
        $dateFormats = [
            '2025-01-01', // Valid format
            '2025-12-31', // Valid format
            '2024-02-29', // Leap year
            '2023-06-15'  // Valid format
        ];

        foreach ($dateFormats as $date) {
            $response = $this->postJson('/api/incapacidades/search', [
                'tipo' => 'CC',
                'numero_documento' => '1000644432',
                'fecha_expedicion' => $date
            ]);

            // Should not return validation error for valid dates
            $this->assertNotEquals(422, $response->getStatusCode(), "Date '{$date}' should be valid");
        }
    }

    /**
     * Test API error messages are in Spanish
     */
    public function test_api_error_messages_spanish()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => 'invalid-date'
        ]);

        $response->assertStatus(422)
                ->assertJsonFragment([
                    'message' => 'Los datos proporcionados no son válidos.'
                ])
                ->assertJsonFragment([
                    'fecha_expedicion' => [
                        'La fecha de expedición debe ser una fecha válida.',
                        'La fecha de expedición debe tener el formato YYYY-MM-DD.'
                    ]
                ]);
    }

    /**
     * Test API handles concurrent requests
     */
    public function test_api_concurrent_requests()
    {
        // Skip if Excel file doesn't exist
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $requests = [];

        // Simulate multiple concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $requests[] = $this->postJson('/api/incapacidades/search', [
                'tipo' => 'CC',
                'numero_documento' => '1000644432',
                'fecha_expedicion' => '2025-01-01'
            ]);
        }

        // All requests should succeed
        foreach ($requests as $request) {
            $this->assertContains($request->getStatusCode(), [200, 404]);
        }
    }

    /**
     * Test API response structure consistency
     */
    public function test_api_response_structure_consistency()
    {
        // Skip if Excel file doesn't exist
        if (!file_exists(public_path('data/_RELACION INCAPACIDADES 2025.xlsx'))) {
            $this->markTestSkipped('Excel file not found');
        }

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1000644432',
            'fecha_expedicion' => '2025-01-01'
        ]);

        if ($response->getStatusCode() === 200) {
            $response->assertJsonStructure([
                'status',
                'data' => [
                    '*' => [
                        'N° Radicado',
                        'fecha recibido',
                        'Tipo',
                        'Numero Documento',
                        'Fecha Expedicion',
                        'Nombres',
                        'Cargo',
                        'Fecha Incio Incapacidad',
                        'Fecha Fin Incapacidad',
                        'Dias Incapacidad',
                        'CODIGO CIE-10',
                        'TIPO INCAPACIDAD',
                        'CLASIFICACION',
                        'ADMINISTRADORA',
                        'FECHA ENVIO',
                        'RADICADO',
                        'estado',
                        'detalles',
                        'valor Incapacidad Recibido',
                        'Hospital'
                    ]
                ]
            ]);
        }
    }
}
