<?php

namespace Tests\Feature;

use App\Models\VotingSetting;
use App\Services\AfiliadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VotingModeGatesTest extends TestCase
{
    use RefreshDatabase;

    private function enableMode(string $mode): void
    {
        VotingSetting::current()->update(['active_mode' => $mode]);
    }

    public function test_candidate_endpoints_return_403_when_mode_is_none(): void
    {
        $this->enableMode('none');

        $this->postJson('/api/activos/search-candidate-voting', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->assertForbidden()
            ->assertJson(['error_code' => 'CANDIDATE_VOTING_DISABLED']);
    }

    public function test_candidate_endpoints_return_403_when_mode_is_assembly(): void
    {
        $this->enableMode('assembly');

        $this->postJson('/api/activos/search-candidate-voting', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->assertForbidden()
            ->assertJson(['error_code' => 'CANDIDATE_VOTING_DISABLED']);
    }

    public function test_submit_vote_returns_403_when_candidate_mode_is_disabled(): void
    {
        $this->enableMode('none');

        $this->postJson('/api/votes', [
            'voter' => [
                'documentType' => 'CC',
                'documentNumber' => '1111111111',
                'hospital' => 'Bello',
                'position' => 'Afiliado',
            ],
            'candidate' => [
                'id' => '888',
                'name' => 'Candidato',
                'position' => 'Proceso',
                'hospital' => 'Bello',
            ],
            'timestamp' => '2025-01-15T12:00:00.000Z',
        ])->assertForbidden()
            ->assertJson(['error_code' => 'CANDIDATE_VOTING_DISABLED']);
    }

    public function test_check_vote_returns_403_when_candidate_mode_is_disabled(): void
    {
        $this->enableMode('none');

        $this->getJson('/api/votes/check?document_type=CC&document_number=1234567890')
            ->assertForbidden()
            ->assertJson(['error_code' => 'CANDIDATE_VOTING_DISABLED']);
    }

    public function test_delegados_endpoints_return_403_when_candidate_mode_is_disabled(): void
    {
        $this->enableMode('none');

        $this->getJson('/api/delegados/by-sede?sede=Bello')
            ->assertForbidden()
            ->assertJson(['error_code' => 'CANDIDATE_VOTING_DISABLED']);
    }

    public function test_assembly_search_hospital_returns_403_when_mode_is_none(): void
    {
        $this->enableMode('none');

        $this->postJson('/api/activos/search-hospital', [
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
            'signature' => 'data:image/png;base64,xx',
        ])->assertForbidden()
            ->assertJson(['error_code' => 'ASSEMBLY_VOTING_DISABLED']);
    }

    public function test_assembly_search_hospital_returns_403_when_mode_is_candidate(): void
    {
        $this->enableMode('candidate');

        $this->postJson('/api/activos/search-hospital', [
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
            'signature' => 'data:image/png;base64,xx',
        ])->assertForbidden()
            ->assertJson(['error_code' => 'ASSEMBLY_VOTING_DISABLED']);
    }

    public function test_candidate_endpoints_pass_when_mode_is_candidate(): void
    {
        $this->enableMode('candidate');

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(false);
        });

        $this->postJson('/api/activos/search-candidate-voting', [
            'tipo_documento' => 'CC',
            'documento' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->assertStatus(503);
    }

    public function test_submit_assembly_vote_returns_403_when_assembly_mode_is_disabled(): void
    {
        $this->enableMode('candidate');

        $this->postJson('/api/assembly/questions/fake-id/votes', [
            'voterId' => 'CC-1234567890',
            'voterName' => 'Test Voter',
            'selectedOptions' => ['agree'],
        ])->assertForbidden()
            ->assertJson(['error_code' => 'ASSEMBLY_VOTING_DISABLED']);
    }
}
