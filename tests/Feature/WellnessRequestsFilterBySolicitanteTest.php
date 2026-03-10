<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_index_can_be_filtered_by_solicitante_name(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo(['wellness_requests.view', 'wellness_requests.update_status']);

        $this->actingAs($user);

        $requesterJuan = User::factory()->create(['name' => 'Juan Pérez']);
        $requesterMaria = User::factory()->create(['name' => 'María González']);

        WellnessRequest::factory()->create([
            'requester_id' => $requesterJuan->id,
        ]);

        WellnessRequest::factory()->create([
            'requester_id' => $requesterMaria->id,
        ]);

        $response = $this->getJson('/api/wellness-requests?solicitante=María');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame(
            (string) $requesterMaria->id,
            $response->json('data.0.solicitante.solicitanteId')
        );
    }
}
