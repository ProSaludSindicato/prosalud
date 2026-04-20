<?php

namespace Tests\Feature;

use App\Models\CandidateVotingPeriod;
use App\Models\Vote;
use App\Models\VotingSetting;
use App\Services\AfiliadoService;
use App\Services\ExcelReaderService;
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
        ]);

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
        ]);

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
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'CANDIDATE_HOSPITAL_MISMATCH');
    }
}
