<?php

namespace Tests\Feature;

use App\Models\CandidateVotingPeriod;
use App\Models\Vote;
use App\Models\VotingSetting;
use App\Services\AfiliadoService;
use App\Services\ExcelReaderService;
use App\Services\VoterSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateVotingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $period = CandidateVotingPeriod::factory()->active()->create([
            'election_key' => 'delegados-2026-1',
            'name' => 'Elección Delegados 2026-1',
        ]);

        VotingSetting::current()->update([
            'active_mode' => 'candidate',
            'active_candidate_election_key' => $period->election_key,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function searchCandidateVotingPayload(): array
    {
        return [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function voterHeaders(string $documentNumber = '1111111111', string $hospital = 'Bello'): array
    {
        $service = app(VoterSessionService::class);

        return [VoterSessionService::HEADER => $service->issue(
            'CC',
            $documentNumber,
            $hospital,
            $service->candidateScope('delegados-2026-1')
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function votePayload(string $documentNumber = '1111111111', string $hospital = 'Bello'): array
    {
        return [
            'voter' => [
                'documentType' => 'CC',
                'documentNumber' => $documentNumber,
                'hospital' => $hospital,
                'position' => 'Afiliado',
            ],
            'candidate' => [
                'id' => '888',
                'name' => 'Candidato Uno',
                'position' => 'Proceso',
                'hospital' => 'Bello',
            ],
            'timestamp' => '2025-01-15T12:00:00.000Z',
        ];
    }

    public function test_search_candidate_voting_returns_503_when_activos_file_unavailable(): void
    {
        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(false);
        });

        $response = $this->postJson('/api/activos/search-candidate-voting', $this->searchCandidateVotingPayload());

        $response->assertStatus(503);
    }

    public function test_search_candidate_voting_returns_affiliate_when_found(): void
    {
        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliado')
                ->once()
                ->andReturn([
                    'nombres' => 'Juan',
                    'apellidos' => 'Pérez',
                    'hospital' => 'Bello',
                ]);
        });

        $response = $this->postJson('/api/activos/search-candidate-voting', $this->searchCandidateVotingPayload());

        $response->assertOk()
            ->assertJsonStructure(['voter_token'])
            ->assertJson([
                'success' => true,
                'found' => true,
                'data' => [
                    'nombre_apellidos' => 'Juan Pérez',
                    'hospital' => 'Bello',
                ],
            ]);
    }

    public function test_store_vote_returns_409_when_voter_already_voted(): void
    {
        Vote::query()->create([
            'voter_document_type' => 'CC',
            'voter_document_number' => '1234567890',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_election_key' => 'delegados-2026-1',
            'candidate_id' => '999',
            'candidate_name' => 'Candidato',
            'candidate_position' => 'Delegado',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $this->mock(ExcelReaderService::class, function ($mock) {
            $mock->shouldReceive('isDelegadosFileAvailable')->never();
            $mock->shouldReceive('getDelegadoByCedula')->never();
        });

        $response = $this->postJson('/api/votes', [
            'voter' => [
                'documentType' => 'CC',
                'documentNumber' => '1234567890',
                'hospital' => 'Bello',
                'position' => 'Afiliado',
            ],
            'candidate' => [
                'id' => '888',
                'name' => 'Otro',
                'position' => 'X',
                'hospital' => 'Bello',
            ],
            'timestamp' => '2025-01-15T12:00:00.000Z',
        ], $this->voterHeaders('1234567890'));

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'ALREADY_VOTED');
    }

    public function test_store_vote_returns_201_when_valid(): void
    {
        $this->mock(ExcelReaderService::class, function ($mock) {
            $mock->shouldReceive('isDelegadosFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('getDelegadoByCedula')
                ->once()
                ->with('888')
                ->andReturn([
                    'cedula' => '888',
                    'nombre_apellidos' => 'Candidato Uno',
                    'sede' => 'Bello',
                    'proceso' => 'Proceso',
                ]);
        });

        $response = $this->postJson('/api/votes', [
            'voter' => [
                'documentType' => 'CC',
                'documentNumber' => '1111111111',
                'hospital' => 'Bello',
                'position' => 'Afiliado',
            ],
            'candidate' => [
                'id' => '888',
                'name' => 'Candidato Uno',
                'position' => 'Proceso',
                'hospital' => 'Bello',
            ],
            'timestamp' => '2025-01-15T12:00:00.000Z',
        ], $this->voterHeaders());

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('votes', [
            'voter_document_number' => '1111111111',
            'candidate_id' => '888',
            'candidate_election_key' => 'delegados-2026-1',
        ]);
    }

    public function test_store_vote_returns_422_when_candidate_hospital_mismatch(): void
    {
        $this->mock(ExcelReaderService::class, function ($mock) {
            $mock->shouldReceive('isDelegadosFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('getDelegadoByCedula')
                ->once()
                ->andReturn([
                    'cedula' => '888',
                    'nombre_apellidos' => 'Candidato Uno',
                    'sede' => 'Rionegro',
                    'proceso' => 'Proceso',
                ]);
        });

        $response = $this->postJson('/api/votes', [
            'voter' => [
                'documentType' => 'CC',
                'documentNumber' => '1111111111',
                'hospital' => 'Bello',
                'position' => 'Afiliado',
            ],
            'candidate' => [
                'id' => '888',
                'name' => 'Candidato Uno',
                'position' => 'Proceso',
                'hospital' => 'Bello',
            ],
            'timestamp' => '2025-01-15T12:00:00.000Z',
        ], $this->voterHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'CANDIDATE_HOSPITAL_MISMATCH');
    }

    public function test_store_vote_returns_401_without_voter_token(): void
    {
        $this->postJson('/api/votes', $this->votePayload())
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');

        $this->assertDatabaseCount('votes', 0);
    }

    public function test_store_vote_returns_401_with_tampered_or_foreign_scope_token(): void
    {
        $service = app(VoterSessionService::class);
        $foreignScope = [VoterSessionService::HEADER => $service->issue('CC', '1111111111', 'Bello', 'candidate:otra-eleccion')];

        $this->postJson('/api/votes', $this->votePayload(), $foreignScope)->assertStatus(401);
        $this->postJson('/api/votes', $this->votePayload(), [VoterSessionService::HEADER => 'basura'])->assertStatus(401);
    }

    public function test_store_vote_returns_401_when_voter_token_expired(): void
    {
        $headers = $this->voterHeaders();

        $this->travel(361)->minutes();

        $this->postJson('/api/votes', $this->votePayload(), $headers)
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');
    }

    public function test_voter_token_remains_valid_after_five_hours(): void
    {
        $service = app(VoterSessionService::class);
        $scope = $service->candidateScope('delegados-2026-1');
        $token = $service->issue('CC', '1111111111', 'Bello', $scope);

        $this->travel(5)->hours();

        $this->assertSame('1111111111', $service->verify($token, $scope)['document_number'] ?? null);
    }

    public function test_voter_token_lifetime_follows_configuration(): void
    {
        config(['app.voter_session_ttl_minutes' => 30]);
        $headers = $this->voterHeaders();

        $this->travel(31)->minutes();

        $this->postJson('/api/votes', $this->votePayload(), $headers)->assertStatus(401);
    }

    public function test_store_vote_returns_403_when_document_differs_from_token(): void
    {
        $this->postJson('/api/votes', $this->votePayload('2222222222'), $this->voterHeaders('1111111111'))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'VOTER_SESSION_MISMATCH');

        $this->assertDatabaseCount('votes', 0);
    }

    public function test_store_vote_uses_hospital_from_token_not_from_body(): void
    {
        $this->mock(ExcelReaderService::class, function ($mock) {
            $mock->shouldReceive('isDelegadosFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('getDelegadoByCedula')->once()->andReturn([
                'cedula' => '888',
                'nombre_apellidos' => 'Candidato Uno',
                'sede' => 'Rionegro',
                'proceso' => 'Proceso',
            ]);
        });

        $this->postJson('/api/votes', $this->votePayload('1111111111', 'Rionegro'), $this->voterHeaders('1111111111', 'Bello'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CANDIDATE_HOSPITAL_MISMATCH');

        $this->assertDatabaseCount('votes', 0);
    }

    public function test_votes_table_rejects_duplicate_voter_in_same_election(): void
    {
        $attributes = [
            'voter_document_type' => 'CC',
            'voter_document_number' => '5555555555',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_election_key' => 'delegados-2026-1',
            'candidate_id' => '999',
            'candidate_name' => 'Candidato',
            'candidate_position' => 'Delegado',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
        ];

        Vote::query()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        Vote::query()->create($attributes);
    }

    public function test_same_voter_can_vote_in_a_different_election(): void
    {
        $attributes = [
            'voter_document_type' => 'CC',
            'voter_document_number' => '5555555555',
            'voter_hospital' => 'Bello',
            'voter_position' => 'Afiliado',
            'candidate_id' => '999',
            'candidate_name' => 'Candidato',
            'candidate_position' => 'Delegado',
            'candidate_hospital' => 'Bello',
            'vote_timestamp' => now(),
        ];

        Vote::query()->create($attributes + ['candidate_election_key' => 'delegados-2026-1']);
        Vote::query()->create($attributes + ['candidate_election_key' => 'delegados-2027-1']);

        $this->assertDatabaseCount('votes', 2);
    }
}
