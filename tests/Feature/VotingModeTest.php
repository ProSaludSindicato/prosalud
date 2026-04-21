<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\VotingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VotingModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
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

    /**
     * Make an authenticated PUT request using the cookie-based auth.
     * Note: putJson() ignores cookies unless withCredentials() is set,
     * so we use put() with JSON Accept header instead.
     *
     * @param  array<string, mixed>  $data
     */
    private function authenticatedPut(string $uri, array $data, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->put($uri, $data, ['Accept' => 'application/json']);
    }

    public function test_public_endpoint_returns_current_active_mode(): void
    {
        VotingSetting::current()->update(['active_mode' => 'none']);

        $this->getJson('/api/voting/mode')
            ->assertOk()
            ->assertJson(['active_mode' => 'none']);
    }

    public function test_public_endpoint_returns_candidate_mode_when_set(): void
    {
        VotingSetting::current()->update(['active_mode' => 'candidate']);

        $this->getJson('/api/voting/mode')
            ->assertOk()
            ->assertJson(['active_mode' => 'candidate']);
    }

    public function test_admin_can_switch_to_candidate_mode(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->authenticatedPut('/api/voting/mode', ['active_mode' => 'candidate'], $user)
            ->assertOk()
            ->assertJson(['success' => true, 'active_mode' => 'candidate']);

        $this->assertDatabaseHas('voting_settings', ['active_mode' => 'candidate']);
    }

    public function test_admin_can_switch_to_assembly_mode(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->authenticatedPut('/api/voting/mode', ['active_mode' => 'assembly'], $user)
            ->assertOk()
            ->assertJson(['success' => true, 'active_mode' => 'assembly']);

        $this->assertDatabaseHas('voting_settings', ['active_mode' => 'assembly']);
    }

    public function test_admin_can_disable_voting_with_none_mode(): void
    {
        VotingSetting::current()->update(['active_mode' => 'candidate']);

        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->authenticatedPut('/api/voting/mode', ['active_mode' => 'none'], $user)
            ->assertOk()
            ->assertJson(['success' => true, 'active_mode' => 'none']);

        $this->assertDatabaseHas('voting_settings', ['active_mode' => 'none']);
    }

    public function test_update_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->authenticatedPut('/api/voting/mode', ['active_mode' => 'candidate'], $user)
            ->assertForbidden();
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/voting/mode', ['active_mode' => 'candidate'])
            ->assertUnauthorized();
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->authenticatedPut('/api/voting/mode', ['active_mode' => 'invalid'], $user)
            ->assertUnprocessable();
    }

    public function test_missing_mode_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->authenticatedPut('/api/voting/mode', [], $user)
            ->assertUnprocessable();
    }
}
