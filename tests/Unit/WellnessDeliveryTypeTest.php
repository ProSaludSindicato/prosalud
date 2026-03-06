<?php

namespace Tests\Unit;

use App\Models\WellnessDeliveryType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellnessDeliveryTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Crear tipo con el mismo rango que en la captura: activo 06-mar a 10-mar 2026
        WellnessDeliveryType::create([
            'nombre' => 'Detalle día de la mujer',
            'activo' => true,
            'fecha_desde' => '2026-03-06',
            'fecha_hasta' => '2026-03-10',
            'created_by' => null,
        ]);
    }

    /** El 5 de marzo la campaña aún no inicia (fecha_desde es 06) → no hay tipo activo */
    public function test_get_activo_para_fecha_returns_null_before_fecha_desde(): void
    {
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-05');
        $this->assertNull($tipo);
    }

    /** El 6 de marzo (primer día del rango) debe devolver el tipo */
    public function test_get_activo_para_fecha_returns_type_on_fecha_desde(): void
    {
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-06');
        $this->assertInstanceOf(WellnessDeliveryType::class, $tipo);
        $this->assertSame('Detalle día de la mujer', $tipo->nombre);
    }

    /** Un día dentro del rango debe devolver el tipo */
    public function test_get_activo_para_fecha_returns_type_within_range(): void
    {
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-07');
        $this->assertInstanceOf(WellnessDeliveryType::class, $tipo);
        $this->assertSame('Detalle día de la mujer', $tipo->nombre);
    }

    /** El último día del rango (10-mar) debe devolver el tipo */
    public function test_get_activo_para_fecha_returns_type_on_fecha_hasta(): void
    {
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-10');
        $this->assertInstanceOf(WellnessDeliveryType::class, $tipo);
        $this->assertSame('Detalle día de la mujer', $tipo->nombre);
    }

    /** El 11 de marzo (después del rango) no hay tipo activo */
    public function test_get_activo_para_fecha_returns_null_after_fecha_hasta(): void
    {
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-11');
        $this->assertNull($tipo);
    }

    /** Tipo inactivo (activo=false) no se devuelve aunque la fecha esté en el rango */
    public function test_inactive_type_not_returned(): void
    {
        WellnessDeliveryType::create([
            'nombre' => 'Otra campaña',
            'activo' => false,
            'fecha_desde' => '2026-03-01',
            'fecha_hasta' => '2026-03-31',
            'created_by' => null,
        ]);
        $tipo = WellnessDeliveryType::getActivoParaFecha('2026-03-15');
        $this->assertNull($tipo);
    }
}
