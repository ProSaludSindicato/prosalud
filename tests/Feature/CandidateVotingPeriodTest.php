<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CandidateVotingPeriod;
use App\Models\User;
use App\Models\Vote;
use App\Models\VotingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CandidateVotingPeriodTest extends TestCase
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

    public function test_public_current_endpoint_returns_null_when_no_active_period(): void
    {
        $this->getJson('/api/candidate-voting-periods/current')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    public function test_index_allows_user_with_statistics_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('votes.statistics.view');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/candidate-voting-periods', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_index_allows_user_with_audit_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('votes.audit.view');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/candidate-voting-periods', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_create_and_activate_period(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/candidate-voting-periods', [
                'name' => '2026-1',
                'activate' => true,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.name', '2026-1');

        $this->assertDatabaseHas('candidate_voting_periods', [
            'name' => '2026-1',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('voting_settings', [
            'active_candidate_election_key' => '2026-1',
        ]);
    }

    public function test_closing_active_period_clears_key_and_disables_candidate_mode(): void
    {
        $period = CandidateVotingPeriod::factory()->active()->create([
            'election_key' => '2026-1',
            'name' => '2026-1',
        ]);
        VotingSetting::current()->update([
            'active_mode' => 'candidate',
            'active_candidate_election_key' => $period->election_key,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->patch("/api/candidate-voting-periods/{$period->id}/close", [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('voting_settings', [
            'active_mode' => 'none',
            'active_candidate_election_key' => null,
        ]);
    }

    public function test_statistics_only_show_votes_from_active_period(): void
    {
        CandidateVotingPeriod::factory()->create([
            'election_key' => '2025-2',
            'name' => '2025-2',
            'is_active' => false,
        ]);
        CandidateVotingPeriod::factory()->active()->create([
            'election_key' => '2026-1',
            'name' => '2026-1',
        ]);
        VotingSetting::current()->update([
            'active_mode' => 'candidate',
            'active_candidate_election_key' => '2026-1',
        ]);

        Vote::query()->create([
            'voter_document_type' => 'CC',
            'voter_document_number' => '1010',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_election_key' => '2025-2',
            'candidate_id' => '1',
            'candidate_name' => 'Viejo',
            'candidate_position' => 'P',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now()->subMonths(4),
        ]);

        Vote::query()->create([
            'voter_document_type' => 'CC',
            'voter_document_number' => '2020',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_election_key' => '2026-1',
            'candidate_id' => '2',
            'candidate_name' => 'Actual',
            'candidate_position' => 'P',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('votes.statistics.view');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get('/api/votes/hospital-statistics', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('statistics.total_votes', 1)
            ->assertJsonPath('selected_candidate_election_key', '2026-1');
    }

    public function test_duplicate_period_name_is_rejected(): void
    {
        CandidateVotingPeriod::factory()->create([
            'election_key' => '2026-1',
            'name' => '2026-1',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('voting.mode.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/candidate-voting-periods', ['name' => '2026-1'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }
}
