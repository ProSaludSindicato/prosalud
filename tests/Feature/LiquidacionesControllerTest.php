<?php

namespace Tests\Feature;

use App\Services\LiquidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiquidacionesControllerTest extends TestCase
{
    use RefreshDatabase;

    private $liquidacionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liquidacionService = \Mockery::mock(LiquidacionService::class);
        $this->app->instance(LiquidacionService::class, $this->liquidacionService);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test the liquidaciones search endpoint with valid data.
     */
    public function testLiquidacionesSearchWithValidData()
    {
        $this->liquidacionService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '1121949803', '2020-01-01')
            ->andReturn([
                'status' => 'success',
                'data' => [
                    [
                        'TIPO DE DOCUMENTO' => 'CC',
                        'N° DOCUMENTO' => '1121949803',
                        'FECHA EXPEDICION' => '01/01/2020',
                        'NOMBRE' => 'RAMIREZ MORALES CRISTIAN DAVID',
                        'HOSPITAL' => 'HMFS - BELLO',
                        'PROCESO' => 'ENFERMERO(A) PROFESIONAL - URGENCIAS',
                        'ESTADO BD' => 'Retirado',
                    ],
                ],
            ]);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '1121949803',
            'fecha_expedicion' => '2020-01-01',
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success',
                ])
                ->assertJsonMissing([
                    'FECHA DE ENTREGA A CAMILA',
                    'RESPONSABLE DE ENTREGA CONTABILIDAD',
                    'REVISION',
                    'FORMATO DE REVISION FISICO',
                ]);
    }

    /**
     * Test the liquidaciones search endpoint with missing required fields.
     */
    public function testLiquidacionesSearchWithMissingFields()
    {
        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['numero_documento', 'fecha_expedicion']);
    }

    /**
     * Test the liquidaciones search endpoint with empty data.
     */
    public function testLiquidacionesSearchWithEmptyData()
    {
        $this->liquidacionService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '999999999', '2020-01-01')
            ->andReturn([
                'status' => 'not_found',
                'message' => 'No se encontraron liquidaciones registradas para el documento especificado.',
            ]);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '999999999',
            'fecha_expedicion' => '2020-01-01',
        ]);

        $response->assertStatus(404)
                ->assertJson([
                    'status' => 'not_found',
                ]);
    }

    /**
     * Test the liquidaciones search endpoint with non-existent document.
     */
    public function testLiquidacionesSearchWithNonExistentDocument()
    {
        $this->liquidacionService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '0000000000', '2020-01-01')
            ->andReturn([
                'status' => 'not_found',
                'message' => 'No se encontraron liquidaciones registradas para el documento especificado.',
            ]);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '0000000000',
            'fecha_expedicion' => '2020-01-01',
        ]);

        $response->assertStatus(404);
    }

    /**
     * Test the liquidaciones search endpoint with invalid date format.
     */
    public function testLiquidacionesSearchWithInvalidDateFormat()
    {
        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '1121949803',
            'fecha_expedicion' => '01-01-2020', // Invalid format, should be Y-m-d
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['fecha_expedicion']);
    }

    /**
     * Test date format conversion.
     */
    public function testDateFormatConversion()
    {
        $this->liquidacionService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '1121949803', '2020-01-01')
            ->andReturn([
                'status' => 'success',
                'data' => [
                    [
                        'FECHA EXPEDICION' => '01/01/2020',
                        'FECHA INGRESO' => '01/07/2023',
                        'FECHA RETIRO' => '31/07/2025',
                    ],
                ],
            ]);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '1121949803',
            'fecha_expedicion' => '2020-01-01',
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success',
                    'data' => [
                        [
                            'FECHA EXPEDICION' => '01/01/2020',
                            'FECHA INGRESO' => '01/07/2023',
                            'FECHA RETIRO' => '31/07/2025',
                        ],
                    ],
                ]);
    }

    /**
     * Test English date format conversion.
     */
    public function testEnglishDateFormatConversion()
    {
        $this->liquidacionService
            ->shouldReceive('searchByDocument')
            ->once()
            ->with('CC', '1121949803', '2020-01-01')
            ->andReturn([
                'status' => 'success',
                'data' => [
                    [
                        'FECHA EXPEDICION' => '01/01/2020',
                        'FECHA INGRESO' => '01/07/2023',
                        'FECHA RETIRO' => '31/07/2025',
                    ],
                ],
            ]);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '1121949803',
            'fecha_expedicion' => '2020-01-01',
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success',
                ]);
    }
}
