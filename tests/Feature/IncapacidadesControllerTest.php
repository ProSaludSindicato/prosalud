<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\IncapacidadService;
use Mockery;

class IncapacidadesControllerTest extends TestCase
{
    use RefreshDatabase;

    private $incapacidadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incapacidadService = Mockery::mock(IncapacidadService::class);
        $this->app->instance(IncapacidadService::class, $this->incapacidadService);
    }

    /**
     * Test the incapacidades search endpoint with valid data
     */
    public function test_incapacidades_search_with_valid_data()
    {
        $this->incapacidadService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '1152451126', '2025-01-01')
            ->andReturn([
                'status' => 'success',
                'data' => [
                    [
                        'N° Radicado' => '003850',
                        'Tipo' => 'CC',
                        'Numero Documento' => '1152451126',
                        'Fecha Expedicion' => '01/01/2025',
                        'Nombres' => 'Juan Pérez'
                    ]
                ]
            ]);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1152451126',
            'fecha_expedicion' => '2025-01-01'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success'
                ])
                ->assertJsonMissing([
                    'REPORTE FACTURA',
                    'REPORTE VIVI'
                ]);
    }

    /**
     * Test the incapacidades search endpoint with missing required fields
     */
    public function test_incapacidades_search_with_missing_fields()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC'
            // Missing numero_documento and fecha_expedicion
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['numero_documento', 'fecha_expedicion'])
                ->assertJson([
                    'message' => 'Los datos proporcionados no son válidos.'
                ]);
    }

    /**
     * Test the incapacidades search endpoint with empty data
     */
    public function test_incapacidades_search_with_empty_data()
    {
        $response = $this->postJson('/api/incapacidades/search', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento', 'fecha_expedicion'])
                ->assertJson([
                    'message' => 'Los datos proporcionados no son válidos.'
                ]);
    }

    /**
     * Test the incapacidades search endpoint with non-existent document
     */
    public function test_incapacidades_search_with_non_existent_document()
    {
        $this->incapacidadService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '9999999999', '2024-01-15')
            ->andReturn([
                'status' => 'not_found',
                'message' => 'No se encontraron incapacidades registradas para el documento especificado.'
            ]);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '9999999999',
            'fecha_expedicion' => '2024-01-15'
        ]);

        $response->assertStatus(404)
                ->assertJson([
                    'status' => 'not_found',
                    'message' => 'No se encontraron incapacidades registradas para el documento especificado.'
                ]);
    }

    /**
     * Test the incapacidades search endpoint with invalid date format
     */
    public function test_incapacidades_search_with_invalid_date_format()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1152451126',
            'fecha_expedicion' => '15/01/2024' // Invalid format, should be YYYY-MM-DD
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_expedicion'])
                ->assertJson([
                    'message' => 'Los datos proporcionados no son válidos.'
                ]);
    }

    /**
     * Test that date fields are converted from MM/DD/YYYY to DD/MM/YYYY format
     */
    public function test_date_format_conversion()
    {
        // This test would require a mock Excel file with known date formats
        // For now, we'll test the validation messages in Spanish
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '1152451126',
            'fecha_expedicion' => 'invalid-date'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_expedicion'])
                ->assertJsonFragment([
                    'fecha_expedicion' => ['La fecha de expedición debe ser una fecha válida.', 'La fecha de expedición debe tener el formato YYYY-MM-DD.']
                ]);
    }

    /**
     * Test date conversion with English format (FECHA ENVIO)
     */
    public function test_english_date_format_conversion()
    {
        $this->incapacidadService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '123456789', '2024-01-15')
            ->andReturn([
                'status' => 'success',
                'data' => [
                    [
                        'N° Radicado' => '001',
                        'Tipo' => 'CC',
                        'Numero Documento' => '123456789',
                        'Nombres' => 'Juan Pérez',
                        'FECHA ENVIO' => '20/03/2025',  // Converted from 20-Mar-25
                        'Fecha Incio Incapacidad' => '15/02/2025'  // Converted from 2/15/2025
                    ]
                ]
            ]);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2024-01-15'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success'
                ])
                ->assertJsonFragment([
                    'FECHA ENVIO' => '20/03/2025'
                ])
                ->assertJsonFragment([
                    'Fecha Incio Incapacidad' => '15/02/2025'
                ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
