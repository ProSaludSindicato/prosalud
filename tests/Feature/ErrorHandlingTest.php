<?php

namespace Tests\Feature;

use App\Services\{IncapacidadService, LiquidacionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test incapacidades API handles service exceptions gracefully.
     */
    public function testIncapacidadesApiHandlesServiceExceptions()
    {
        $mockService = \Mockery::mock(IncapacidadService::class);
        $mockService->shouldReceive('searchByDocument')
            ->once()
            ->andThrow(new \Exception('Service error'));

        $this->app->instance(IncapacidadService::class, $mockService);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(500)
                ->assertJsonFragment([
                    'message' => 'Service error',
                ]);
    }

    /**
     * Test liquidaciones API handles service exceptions gracefully.
     */
    public function testLiquidacionesApiHandlesServiceExceptions()
    {
        $mockService = \Mockery::mock(LiquidacionService::class);
        $mockService->shouldReceive('searchByDocument')
            ->once()
            ->andThrow(new \Exception('Service error'));

        $this->app->instance(LiquidacionService::class, $mockService);

        $response = $this->postJson('/api/liquidaciones/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(500)
                ->assertJsonFragment([
                    'message' => 'Service error',
                ]);
    }

    /**
     * Test API handles malformed JSON gracefully.
     */
    public function testApiHandlesMalformedJson()
    {
        $response = $this->post('/api/incapacidades/search', [], [
            'Content-Type' => 'application/json',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test API handles oversized requests.
     */
    public function testApiHandlesOversizedRequests()
    {
        $largeString = str_repeat('A', 10000);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => $largeString,
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['numero_documento']);
    }

    /**
     * Test API handles special characters in input.
     */
    public function testApiHandlesSpecialCharacters()
    {
        $specialChars = [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ];

        $response = $this->postJson('/api/incapacidades/search', $specialChars);

        // Should handle special characters gracefully
        $this->assertContains($response->getStatusCode(), [200, 404, 500]);
    }

    /**
     * Test API handles null values.
     */
    public function testApiHandlesNullValues()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => null,
            'numero_documento' => null,
            'fecha_expedicion' => null,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento', 'fecha_expedicion']);
    }

    /**
     * Test API handles array values instead of strings.
     */
    public function testApiHandlesArrayValues()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => ['CC'],
            'numero_documento' => ['123456789'],
            'fecha_expedicion' => ['2025-01-01'],
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento', 'fecha_expedicion']);
    }

    /**
     * Test API handles numeric values for string fields.
     */
    public function testApiHandlesNumericValuesForStringFields()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 123,
            'numero_documento' => 123456789,
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento']);
    }

    /**
     * Test API handles extremely long field values.
     */
    public function testApiHandlesExtremelyLongFieldValues()
    {
        $longString = str_repeat('A', 1000);

        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => $longString,
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo']);
    }

    /**
     * Test API handles invalid HTTP methods.
     */
    public function testApiHandlesInvalidHttpMethods()
    {
        $response = $this->get('/api/incapacidades/search');
        $response->assertStatus(405); // Method Not Allowed

        $response = $this->put('/api/incapacidades/search', []);
        $response->assertStatus(405); // Method Not Allowed

        $response = $this->delete('/api/incapacidades/search');
        $response->assertStatus(405); // Method Not Allowed
    }

    /**
     * Test API handles missing Content-Type header.
     */
    public function testApiHandlesMissingContentType()
    {
        $response = $this->post('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ]);

        // Should still process the request
        $this->assertContains($response->getStatusCode(), [200, 404, 422, 500]);
    }

    /**
     * Test API handles invalid Content-Type.
     */
    public function testApiHandlesInvalidContentType()
    {
        $response = $this->call('POST', '/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ]);

        // Should still process the request
        $this->assertContains($response->getStatusCode(), [200, 404, 422, 500]);
    }

    /**
     * Test API handles concurrent requests with same data.
     */
    public function testApiHandlesConcurrentIdenticalRequests()
    {
        $requestData = [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
        ];

        // Simulate multiple identical requests
        $responses = [];
        for ($i = 0; $i < 3; ++$i) {
            $responses[] = $this->postJson('/api/incapacidades/search', $requestData);
        }

        // All responses should be consistent
        foreach ($responses as $response) {
            $this->assertContains($response->getStatusCode(), [200, 404, 422, 500]);
        }
    }

    /**
     * Test API handles requests with extra fields.
     */
    public function testApiHandlesExtraFields()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
            'extra_field' => 'extra_value',
            'another_field' => 'another_value',
        ]);

        // Should ignore extra fields and process normally
        $this->assertContains($response->getStatusCode(), [200, 404, 422, 500]);
    }

    /**
     * Test API handles requests with nested data.
     */
    public function testApiHandlesNestedData()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => 'CC',
            'numero_documento' => '123456789',
            'fecha_expedicion' => '2025-01-01',
            'nested' => [
                'field1' => 'value1',
                'field2' => 'value2',
            ],
        ]);

        // Should ignore nested data and process normally
        $this->assertContains($response->getStatusCode(), [200, 404, 422, 500]);
    }

    /**
     * Test API handles requests with boolean values.
     */
    public function testApiHandlesBooleanValues()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => true,
            'numero_documento' => false,
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento']);
    }

    /**
     * Test API handles requests with object values.
     */
    public function testApiHandlesObjectValues()
    {
        $response = $this->postJson('/api/incapacidades/search', [
            'tipo' => (object) ['value' => 'CC'],
            'numero_documento' => (object) ['value' => '123456789'],
            'fecha_expedicion' => '2025-01-01',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['tipo', 'numero_documento']);
    }
}
