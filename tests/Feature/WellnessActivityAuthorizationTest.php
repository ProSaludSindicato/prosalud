<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\WellnessActivityEvidence;
use App\Models\WellnessActivityRealized;
use App\Models\WellnessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WellnessActivityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function actingAsApiUser(User $user): void
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_user_cannot_register_activity_on_a_request_they_do_not_own(): void
    {
        $owner = $this->userWith('wellness_requests.edit');
        $intruder = $this->userWith('wellness_requests.edit');
        $request = WellnessRequest::factory()->create(['requester_id' => $owner->id, 'status' => 'resolved']);

        $this->actingAsApiUser($intruder);

        $this->postJson("/api/wellness-requests/{$request->id}/activity-realized", [])->assertForbidden();
        $this->putJson("/api/wellness-requests/{$request->id}/activity-realized", [])->assertForbidden();

        $this->assertDatabaseCount('wellness_activity_realized', 0);
    }

    public function test_owner_passes_authorization_when_registering_activity(): void
    {
        $owner = $this->userWith('wellness_requests.edit');
        $request = WellnessRequest::factory()->create(['requester_id' => $owner->id, 'status' => 'resolved']);

        $this->actingAsApiUser($owner);

        $this->assertNotSame(403, $this->postJson("/api/wellness-requests/{$request->id}/activity-realized", [])->getStatusCode());
    }

    public function test_user_with_update_status_can_edit_any_request_activity(): void
    {
        $owner = $this->userWith('wellness_requests.edit');
        $reviewer = $this->userWith('wellness_requests.edit', 'wellness_requests.update_status');
        $request = WellnessRequest::factory()->create(['requester_id' => $owner->id, 'status' => 'resolved']);

        $this->actingAsApiUser($reviewer);

        $this->assertNotSame(403, $this->postJson("/api/wellness-requests/{$request->id}/activity-realized", [])->getStatusCode());
    }

    public function test_user_cannot_publish_an_activity_of_a_request_outside_their_scope(): void
    {
        $owner = $this->userWith('wellness_requests.edit');
        $intruder = $this->userWith('wellness_activity.publish');
        $request = WellnessRequest::factory()->create(['requester_id' => $owner->id, 'status' => 'resolved']);

        $this->actingAsApiUser($intruder);

        $activity = WellnessActivityRealized::query()->create([
            'wellness_request_id' => $request->id,
            'realized_date' => now()->toDateString(),
            'real_location' => 'Sede',
            'real_attendees_count' => 3,
            'realized_description' => 'Descripción',
            'gift_delivered' => false,
        ]);
        $evidence = WellnessActivityEvidence::query()->create([
            'activity_realized_id' => $activity->id,
            'image_url' => 'evidencias/x.jpg',
            'order' => 1,
        ]);

        $this->postJson("/api/wellness-requests/{$request->id}/publish-to-gallery", [
            'evidencias_seleccionadas' => [$evidence->id],
            'title' => 'Titulo',
            'category' => 'Salud',
        ])->assertForbidden();

        $this->assertFalse((bool) $activity->fresh()->published_to_gallery);
    }
}
