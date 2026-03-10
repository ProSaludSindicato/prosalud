<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WellnessActivityRealized;
use App\Models\WellnessRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellnessRequestsFilterBySolicitanteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_index_can_be_filtered_by_solicitante_name_and_activity_realized(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo(['wellness_requests.view', 'wellness_requests.update_status']);

        $this->actingAs($user);

        $requesterJuan = User::factory()->create(['name' => 'Juan Pérez']);
        $requesterMaria = User::factory()->create(['name' => 'María González']);

        $requestWithActivity = WellnessRequest::factory()->create([
            'requester_id' => $requesterJuan->id,
        ]);

        $requestWithoutActivity = WellnessRequest::factory()->create([
            'requester_id' => $requesterMaria->id,
        ]);

        WellnessActivityRealized::create([
            'wellness_request_id' => $requestWithActivity->id,
            'realized_date' => now()->toDateString(),
            'real_location' => 'Bello',
            'real_attendees_count' => 10,
            'realized_description' => 'Actividad realizada',
            'gift_delivered' => true,
        ]);

        // Filtro solo por nombre de solicitante
        $response = $this->getJson('/api/wellness-requests?solicitante=María');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Filtro por actividades no realizadas
        $response = $this->getJson('/api/wellness-requests?actividadesRealizadas=no_realizadas');
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('pagination.total'));

        // Filtro combinado: solicitante + actividades no realizadas
        $response = $this->getJson('/api/wellness-requests?solicitante=María&actividadesRealizadas=no_realizadas');
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('pagination.total'));
    }
}
