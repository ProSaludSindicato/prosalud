<?php

namespace Tests\Feature;

use App\Models\WellnessDeliveryType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas del endpoint GET /api/kit-bienestar/current-type.
 *
 * Importante: el tipo activo depende de la fecha "hoy" en la timezone de la app.
 * Si la campaña tiene fecha_desde 2026-03-06 y fecha_hasta 2026-03-10,
 * el 2026-03-05 no hay campaña activa (empieza el 6). El API está bien;
 * para que aparezca hoy hay que poner fecha_desde <= hoy o esperar al 6.
 */
class WellnessDeliveryCurrentTypeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WellnessDeliveryType::create([
            'nombre' => 'Detalle día de la mujer',
            'activo' => true,
            'fecha_desde' => '2026-03-06',
            'fecha_hasta' => '2026-03-10',
            'created_by' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Cuando "hoy" es 5-mar-2026, la campaña (6–10 mar) aún no aplica → API responde que no hay */
    public function test_current_type_returns_no_campaign_when_today_is_before_fecha_desde(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 14:00:00', 'America/Bogota'));

        $response = $this->getJson('/api/kit-bienestar/current-type');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => 'No hay una campaña de entregas activa en este momento.',
            'data' => null,
        ]);
    }

    /** Cuando "hoy" es 7-mar-2026 (dentro del rango), la API devuelve el tipo activo */
    public function test_current_type_returns_campaign_when_today_is_in_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00', 'America/Bogota'));

        $response = $this->getJson('/api/kit-bienestar/current-type');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'nombre' => 'Detalle día de la mujer',
                'fecha_desde' => '2026-03-06',
                'fecha_hasta' => '2026-03-10',
            ],
        ]);
        $this->assertIsInt($response->json('data.id'));
    }

    /** Cuando "hoy" es el último día del rango (10-mar), la API devuelve el tipo */
    public function test_current_type_returns_campaign_on_last_day_of_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 23:30:00', 'America/Bogota'));

        $response = $this->getJson('/api/kit-bienestar/current-type');

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'data' => ['nombre' => 'Detalle día de la mujer']]);
    }

    /** Cuando "hoy" es 11-mar (fuera del rango), la API responde que no hay campaña */
    public function test_current_type_returns_no_campaign_when_today_is_after_fecha_hasta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00', 'America/Bogota'));

        $response = $this->getJson('/api/kit-bienestar/current-type');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'data' => null,
        ]);
    }
}
