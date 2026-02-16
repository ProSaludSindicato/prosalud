<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WellnessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WellnessRequestPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run permissions seeder
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_user_without_update_status_permission_can_only_see_own_requests()
    {
        // Create users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        // Give user1 only view permission (not update_status)
        $user1->givePermissionTo('wellness_requests.view');
        
        // Give user2 both permissions
        $user2->givePermissionTo(['wellness_requests.view', 'wellness_requests.update_status']);
        
        // Create wellness requests for each user
        $request1 = WellnessRequest::factory()->create(['requester_id' => $user1->id]);
        $request2 = WellnessRequest::factory()->create(['requester_id' => $user2->id]);
        
        // Test user1 can only see their own requests
        Sanctum::actingAs($user1);
        $response = $this->getJson('/api/wellness-requests');
        
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('pagination.total'));
        $this->assertEquals($request1->id, $response->json('data.0.id'));
        
        // Test user1 can see their own request details
        $response = $this->getJson("/api/wellness-requests/{$request1->id}");
        $response->assertStatus(200);
        
        // Test user1 cannot see other user's request details
        $response = $this->getJson("/api/wellness-requests/{$request2->id}");
        $response->assertStatus(403);
        
        // Test user2 can see all requests
        Sanctum::actingAs($user2);
        $response = $this->getJson('/api/wellness-requests');
        
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('pagination.total'));
    }

    public function test_user_without_update_status_permission_can_only_edit_own_requests()
    {
        // Create users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        // Give user1 only view and edit permissions (not update_status)
        $user1->givePermissionTo(['wellness_requests.view', 'wellness_requests.edit']);
        
        // Create wellness requests for each user
        $request1 = WellnessRequest::factory()->create(['requester_id' => $user1->id]);
        $request2 = WellnessRequest::factory()->create(['requester_id' => $user2->id]);
        
        // Test user1 can edit their own request
        Sanctum::actingAs($user1);
        $response = $this->putJson("/api/wellness-requests/{$request1->id}", [
            'nombreActividad' => 'Updated Activity Name',
        ]);
        
        $response->assertStatus(200);
        
        // Test user1 cannot edit other user's request
        $response = $this->putJson("/api/wellness-requests/{$request2->id}", [
            'nombreActividad' => 'Updated Activity Name',
        ]);
        
        $response->assertStatus(403);
    }

    public function test_user_with_update_status_permission_can_see_and_edit_all_requests()
    {
        // Create users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        // Give user1 all permissions
        $user1->givePermissionTo(['wellness_requests.view', 'wellness_requests.edit', 'wellness_requests.update_status']);
        
        // Create wellness requests for each user
        $request1 = WellnessRequest::factory()->create(['requester_id' => $user1->id]);
        $request2 = WellnessRequest::factory()->create(['requester_id' => $user2->id]);
        
        // Test user1 can see all requests
        Sanctum::actingAs($user1);
        $response = $this->getJson('/api/wellness-requests');
        
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('pagination.total'));
        
        // Test user1 can edit other user's request
        $response = $this->putJson("/api/wellness-requests/{$request2->id}", [
            'nombreActividad' => 'Updated Activity Name',
        ]);
        
        $response->assertStatus(200);
    }
}
