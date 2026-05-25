<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WellnessDeliveryRequest;
use App\Models\WellnessDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellnessDeliveryRequestsIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_index_can_be_filtered_by_solicitante(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('wellness_delivery.view');

        $this->actingAs($user);

        $tipo = WellnessDeliveryType::create([
            'nombre' => 'Detalle día de la mujer',
            'activo' => true,
            'modo_acceso' => 'abierto',
            'fecha_desde' => '2026-03-01',
            'fecha_hasta' => '2026-03-31',
            'created_by' => null,
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => 'legacy',
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '100000001',
            'nombre_afiliado' => 'Juan Pérez',
            'hospital' => 'Hospital Norte',
            'fecha_expedicion' => '2020-01-01',
            'beneficiarios' => [],
            'firma' => 'firma-1',
            'estado' => 'pendiente',
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => 'legacy',
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '100000002',
            'nombre_afiliado' => 'María González',
            'hospital' => 'Hospital Sur',
            'fecha_expedicion' => '2020-01-02',
            'beneficiarios' => [],
            'firma' => 'firma-2',
            'estado' => 'pendiente',
        ]);

        $this->assertSame(2, WellnessDeliveryRequest::count());
        $this->assertSame(
            1,
            WellnessDeliveryRequest::where('nombre_afiliado', 'like', '%María%')->count()
        );
        $this->assertSame(
            1,
            WellnessDeliveryRequest::query()->porSolicitante('María')->count()
        );

        $response = $this->getJson('/api/wellness-delivery-requests?solicitante=María');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_index_pagination_returns_correct_page_and_total(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('wellness_delivery.view');

        $this->actingAs($user);

        $tipo = WellnessDeliveryType::create([
            'nombre' => 'Kit escolar',
            'activo' => true,
            'modo_acceso' => 'abierto',
            'fecha_desde' => '2026-03-01',
            'fecha_hasta' => '2026-03-31',
            'created_by' => null,
        ]);

        for ($i = 1; $i <= 25; $i++) {
            WellnessDeliveryRequest::create([
                'wellness_delivery_type_id' => $tipo->id,
                'tipo_entrega' => 'legacy',
                'tipo_entrega_descripcion' => null,
                'documento_afiliado' => sprintf('100000%03d', $i),
                'nombre_afiliado' => "Afiliado {$i}",
                'hospital' => 'Hospital Norte',
                'fecha_expedicion' => '2020-01-01',
                'beneficiarios' => [],
                'firma' => "firma-{$i}",
                'estado' => 'pendiente',
            ]);
        }

        $response = $this->getJson('/api/wellness-delivery-requests?per_page=10&page=2');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'pagination' => [
                'current_page' => 2,
                'last_page' => 3,
                'per_page' => 10,
                'total' => 25,
            ],
        ]);
        $response->assertJsonCount(10, 'data');
    }

    public function test_index_can_be_filtered_by_documento_partial_match(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('wellness_delivery.view');

        $this->actingAs($user);

        $tipo = WellnessDeliveryType::create([
            'nombre' => 'Kit escolar',
            'activo' => true,
            'modo_acceso' => 'abierto',
            'fecha_desde' => '2026-03-01',
            'fecha_hasta' => '2026-03-31',
            'created_by' => null,
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => 'legacy',
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '1234567890',
            'nombre_afiliado' => 'Juan Pérez',
            'hospital' => 'Hospital Norte',
            'fecha_expedicion' => '2020-01-01',
            'beneficiarios' => [],
            'firma' => 'firma-1',
            'estado' => 'pendiente',
        ]);

        WellnessDeliveryRequest::create([
            'wellness_delivery_type_id' => $tipo->id,
            'tipo_entrega' => 'legacy',
            'tipo_entrega_descripcion' => null,
            'documento_afiliado' => '9876543210',
            'nombre_afiliado' => 'María González',
            'hospital' => 'Hospital Sur',
            'fecha_expedicion' => '2020-01-02',
            'beneficiarios' => [],
            'firma' => 'firma-2',
            'estado' => 'pendiente',
        ]);

        $response = $this->getJson('/api/wellness-delivery-requests?documento=45678');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'pagination' => [
                'total' => 1,
            ],
        ]);
        $response->assertJsonPath('data.0.documento_afiliado', '1234567890');
    }
}
