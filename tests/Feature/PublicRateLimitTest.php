<?php

namespace Tests\Feature;

use App\Models\CandidateVotingPeriod;
use App\Models\Vote;
use App\Models\VotingSetting;
use App\Services\AfiliadoService;
use App\Services\RateLimitIdentity;
use App\Services\VoterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $period = CandidateVotingPeriod::factory()->active()->create(['election_key' => 'delegados-2026-1']);

        VotingSetting::current()->update([
            'active_mode' => 'candidate',
            'active_candidate_election_key' => $period->election_key,
        ]);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliado')->andReturn(null);
        });
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $document): array
    {
        return ['tipo_documento' => 'CC', 'documento' => $document, 'fecha_expedicion' => '2010-01-01'];
    }

    public function test_rotating_user_agent_does_not_bypass_the_per_document_limit(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withHeaders(['User-Agent' => "agent-{$attempt}"])
                ->postJson('/api/activos/search-candidate-voting', $this->payload('1234567890'))
                ->assertOk();
        }

        $this->withHeaders(['User-Agent' => 'agent-fresh'])
            ->postJson('/api/activos/search-candidate-voting', $this->payload('1234567890'))
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limit_exceeded');
    }

    public function test_document_formatting_variants_share_the_same_limit(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/activos/search-candidate-voting', $this->payload('1.234.567.890'))->assertOk();
        }

        $this->postJson('/api/activos/search-candidate-voting', $this->payload('1234567890'))->assertStatus(429);
    }

    public function test_other_documents_are_not_blocked_by_one_documents_limit(): void
    {
        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $this->postJson('/api/activos/search-candidate-voting', $this->payload('1111111111'));
        }

        $this->postJson('/api/activos/search-candidate-voting', $this->payload('2222222222'))->assertOk();
    }

    public function test_rate_limit_identity_extracts_document_from_known_fields(): void
    {
        $this->assertSame('123', RateLimitIdentity::document(Request::create('/x', 'POST', ['numero_documento' => ' 1-2-3 '])));
        $this->assertSame('456', RateLimitIdentity::document(Request::create('/x', 'POST', ['voter' => ['documentNumber' => '456']])));
        $this->assertNull(RateLimitIdentity::document(Request::create('/x', 'POST', ['otro' => '1'])));
    }

    public function test_check_vote_requires_voter_token(): void
    {
        $this->getJson('/api/votes/check?document_type=CC&document_number=1234567890')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');
    }

    public function test_check_vote_reports_status_for_the_token_identity_only(): void
    {
        Vote::query()->create([
            'voter_document_type' => 'CC',
            'voter_document_number' => '1111111111',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_election_key' => 'delegados-2026-1',
            'candidate_id' => '999',
            'candidate_name' => 'Candidato',
            'candidate_position' => 'Delegado',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
        ]);

        $service = app(VoterSessionService::class);
        $headers = fn (string $document) => [VoterSessionService::HEADER => $service->issue(
            'CC',
            $document,
            'Bello',
            $service->candidateScope('delegados-2026-1')
        )];

        $this->getJson('/api/votes/check?document_type=CC&document_number=2222222222', $headers('1111111111'))
            ->assertOk()
            ->assertJsonPath('has_voted', true);

        $this->getJson('/api/votes/check?document_type=CC&document_number=1111111111', $headers('2222222222'))
            ->assertOk()
            ->assertJsonPath('has_voted', false);
    }
}
