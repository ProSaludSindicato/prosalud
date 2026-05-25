<?php

namespace Tests\Feature;

use App\Enums\WellnessHospitalScope;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\WellnessHospitalAssignment;
use App\Models\WellnessRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WellnessRequestHospitalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function apiCookieForUser(User $user): string
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    private function authenticatedGet(string $uri, User $user): TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get($uri, ['Accept' => 'application/json']);
    }

    private function authenticatedPut(string $uri, array $data, User $user): TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->put($uri, $data, ['Accept' => 'application/json']);
    }

    public function test_coordinator_sees_own_and_hospital_requests_without_update_status(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->givePermissionTo(['wellness_requests.view', 'wellness_requests.edit']);

        WellnessHospitalAssignment::create([
            'user_id' => $coordinator->id,
            'hospital_scope' => WellnessHospitalScope::Bello,
        ]);

        $otherUser = User::factory()->create();

        $ownRequest = WellnessRequest::factory()->create([
            'requester_id' => $coordinator->id,
            'cost_center' => 'Rionegro',
        ]);

        $belloRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Bello',
        ]);

        $rionegroRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Rionegro',
        ]);

        $response = $this->authenticatedGet('/api/wellness-requests', $coordinator);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownRequest->id, $ids);
        $this->assertContains($belloRequest->id, $ids);
        $this->assertNotContains($rionegroRequest->id, $ids);

        $this->authenticatedGet("/api/wellness-requests/{$belloRequest->id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('data.esPropia', false);

        $this->authenticatedGet("/api/wellness-requests/{$ownRequest->id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('data.esPropia', true);

        $this->authenticatedGet("/api/wellness-requests/{$rionegroRequest->id}", $coordinator)
            ->assertForbidden();
    }

    public function test_coordinator_with_la_maria_scope_sees_all_la_maria_cost_centers(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->givePermissionTo('wellness_requests.view');

        WellnessHospitalAssignment::create([
            'user_id' => $coordinator->id,
            'hospital_scope' => WellnessHospitalScope::LaMaria,
        ]);

        $otherUser = User::factory()->create();

        $laMariaRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'La Maria asistencial',
        ]);

        $belloRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Bello',
        ]);

        $response = $this->authenticatedGet('/api/wellness-requests', $coordinator);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($laMariaRequest->id, $ids);
        $this->assertNotContains($belloRequest->id, $ids);
    }

    public function test_coordinator_cannot_edit_other_users_requests(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->givePermissionTo(['wellness_requests.view', 'wellness_requests.edit']);

        WellnessHospitalAssignment::create([
            'user_id' => $coordinator->id,
            'hospital_scope' => WellnessHospitalScope::Bello,
        ]);

        $otherUser = User::factory()->create();

        $belloRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Bello',
        ]);

        $this->authenticatedPut("/api/wellness-requests/{$belloRequest->id}", [
            'nombreActividad' => 'Actividad actualizada',
        ], $coordinator)->assertForbidden();
    }

    public function test_coordinator_with_carisma_scope_sees_carisma_requests(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->givePermissionTo('wellness_requests.view');

        WellnessHospitalAssignment::create([
            'user_id' => $coordinator->id,
            'hospital_scope' => WellnessHospitalScope::Carisma,
        ]);

        $otherUser = User::factory()->create();

        $carismaRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Carisma',
        ]);

        $belloRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Bello',
        ]);

        $response = $this->authenticatedGet('/api/wellness-requests', $coordinator);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($carismaRequest->id, $ids);
        $this->assertNotContains($belloRequest->id, $ids);
    }

    public function test_user_with_update_status_still_sees_all_requests(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(['wellness_requests.view', 'wellness_requests.update_status']);

        $otherUser = User::factory()->create();

        WellnessRequest::factory()->count(2)->create(['requester_id' => $otherUser->id]);

        $this->authenticatedGet('/api/wellness-requests', $admin)
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_user_with_todos_scope_sees_all_hospitals_but_cannot_edit_others(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['wellness_requests.view', 'wellness_requests.edit']);

        WellnessHospitalAssignment::create([
            'user_id' => $viewer->id,
            'hospital_scope' => WellnessHospitalScope::Todos,
        ]);

        $otherUser = User::factory()->create();

        $belloRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Bello',
        ]);

        $rionegroRequest = WellnessRequest::factory()->create([
            'requester_id' => $otherUser->id,
            'cost_center' => 'Rionegro',
        ]);

        $response = $this->authenticatedGet('/api/wellness-requests', $viewer);
        $response->assertOk();
        $this->assertSame(2, $response->json('pagination.total'));

        $this->authenticatedGet("/api/wellness-requests/{$belloRequest->id}", $viewer)
            ->assertOk();

        $this->authenticatedPut("/api/wellness-requests/{$belloRequest->id}", [
            'nombreActividad' => 'Actividad actualizada',
        ], $viewer)->assertForbidden();

        $this->authenticatedPut("/api/wellness-requests/{$rionegroRequest->id}", [
            'nombreActividad' => 'Otra actividad',
        ], $viewer)->assertForbidden();
    }
}
