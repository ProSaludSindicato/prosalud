<?php

namespace Tests\Feature;

use App\Models\Vote;
use App\Services\AfiliadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillVoterIdentityOnVotesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createVote(string $documentNumber, ?string $voterPosition, ?string $voterFullName = null, string $electionKey = 'delegados-2026-1'): Vote
    {
        return Vote::query()->create([
            'voter_document_type' => 'CC',
            'voter_document_number' => $documentNumber,
            'voter_full_name' => $voterFullName,
            'voter_hospital' => 'Bello',
            'voter_position' => $voterPosition,
            'candidate_election_key' => $electionKey,
            'candidate_id' => '888',
            'candidate_name' => 'Candidato Uno',
            'candidate_position' => 'Proceso',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
    }

    public function test_it_fills_name_and_position_from_prosanet(): void
    {
        $vote = $this->createVote('1005061664', 'DANIELA ALEJANDRA ROZO PIMENTEL');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('getVoterAuditInfoFromApi')
                ->once()
                ->with('1005061664')
                ->andReturn(['nombre_completo' => 'DANIELA ALEJANDRA ROZO PIMENTEL', 'cargo' => 'ENFERMERO(A)']);
        });

        $this->artisan('votes:backfill-voter-identity', ['--force' => true])->assertSuccessful();

        $vote->refresh();
        $this->assertSame('DANIELA ALEJANDRA ROZO PIMENTEL', $vote->voter_full_name);
        $this->assertSame('ENFERMERO(A)', $vote->voter_position);
    }

    public function test_it_keeps_the_stored_name_when_affiliate_is_not_found(): void
    {
        $vote = $this->createVote('1216718743', 'SINDY KATHERINE REYES OSSA');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('getVoterAuditInfoFromApi')->once()->andReturn(null);
        });

        $this->artisan('votes:backfill-voter-identity', ['--force' => true])->assertSuccessful();

        $vote->refresh();
        $this->assertSame('SINDY KATHERINE REYES OSSA', $vote->voter_full_name);
        $this->assertNull($vote->voter_position);
    }

    public function test_it_leaves_votes_pending_when_prosanet_is_unavailable(): void
    {
        $vote = $this->createVote('1035428555', 'MARIA ALEJANDRA GARCIA MESA');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('getVoterAuditInfoFromApi')->once()->andReturn(false);
        });

        $this->artisan('votes:backfill-voter-identity', ['--force' => true])->assertSuccessful();

        $vote->refresh();
        $this->assertNull($vote->voter_full_name);
        $this->assertSame('MARIA ALEJANDRA GARCIA MESA', $vote->voter_position);
    }

    public function test_it_skips_votes_that_already_have_a_name_and_respects_election_filter(): void
    {
        $alreadyFilled = $this->createVote('111', 'AUXILIAR', 'PERSONA UNO');
        $otherElection = $this->createVote('222', 'PERSONA DOS', null, 'delegados-2025-1');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldNotReceive('getVoterAuditInfoFromApi');
        });

        $this->artisan('votes:backfill-voter-identity', ['--force' => true, '--election' => 'delegados-2026-1'])
            ->expectsOutput('No hay votos sin nombre de votante.')
            ->assertSuccessful();

        $this->assertSame('AUXILIAR', $alreadyFilled->refresh()->voter_position);
        $this->assertNull($otherElection->refresh()->voter_full_name);
    }

    public function test_dry_run_does_not_call_prosanet_or_update(): void
    {
        $vote = $this->createVote('333', 'PERSONA TRES');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldNotReceive('getVoterAuditInfoFromApi');
        });

        $this->artisan('votes:backfill-voter-identity', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($vote->refresh()->voter_full_name);
    }
}
