<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use App\Models\VotingSetting;
use App\Services\VoterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssemblyVoterSessionTest extends TestCase
{
    use RefreshDatabase;

    private Assembly $assembly;

    private AssemblyQuestion $question;

    protected function setUp(): void
    {
        parent::setUp();

        VotingSetting::current()->update(['active_mode' => 'assembly']);

        $this->assembly = Assembly::query()->create([
            'name' => 'Asamblea test',
            'is_active' => true,
            'allows_reactivation' => true,
        ]);

        $this->question = AssemblyQuestion::query()->create([
            'id' => 'q-1',
            'title' => 'Pregunta',
            'type' => 'SINGLE',
            'status' => 'OPEN',
            'allow_change_vote' => true,
            'assembly_id' => $this->assembly->id,
            'opened_at' => now(),
        ]);
    }

    private function attend(string $document): void
    {
        AssemblyAttendance::query()->create([
            'assembly_id' => $this->assembly->id,
            'document_number' => $document,
            'full_name' => 'DELEGADO '.$document,
            'authenticated_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function voterHeaders(string $document, ?int $assemblyId = null): array
    {
        $service = app(VoterSessionService::class);

        return [VoterSessionService::HEADER => $service->issue(
            'DOC',
            $document,
            '',
            $service->assemblyScope($assemblyId ?? $this->assembly->id),
            'DELEGADO '.$document
        )];
    }

    public function test_vote_requires_voter_token(): void
    {
        $this->attend('1001');

        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['agree']])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');

        $this->assertDatabaseCount('assembly_votes', 0);
    }

    public function test_client_supplied_voter_id_is_ignored_in_favor_of_token_identity(): void
    {
        $this->attend('1001');

        $this->postJson('/api/assembly/questions/q-1/votes', [
            'voterId' => 'DOC-9999',
            'voterName' => 'Suplantador',
            'selectedOptions' => ['agree'],
        ], $this->voterHeaders('1001'))->assertCreated();

        $this->assertDatabaseHas('assembly_votes', ['question_id' => 'q-1', 'voter_id' => '1001']);
        $this->assertDatabaseMissing('assembly_votes', ['voter_id' => 'DOC-9999']);
    }

    public function test_token_without_recorded_attendance_is_rejected(): void
    {
        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['agree']], $this->voterHeaders('2002'))
            ->assertStatus(401);

        $this->assertDatabaseCount('assembly_votes', 0);
    }

    public function test_token_from_another_assembly_is_rejected(): void
    {
        $this->attend('1001');

        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['agree']], $this->voterHeaders('1001', $this->assembly->id + 99))
            ->assertStatus(401);
    }

    public function test_changing_vote_only_affects_own_vote(): void
    {
        $this->attend('1001');
        $this->attend('1002');

        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['agree']], $this->voterHeaders('1002'))->assertCreated();
        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['disagree']], $this->voterHeaders('1001'))->assertCreated();
        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['disagree'], 'voterId' => '1002'], $this->voterHeaders('1001'))->assertCreated();

        $this->assertSame(['agree'], AssemblyVote::query()->where('voter_id', '1002')->firstOrFail()->selected_options);
        $this->assertSame(['disagree'], AssemblyVote::query()->where('voter_id', '1001')->firstOrFail()->selected_options);
    }

    public function test_my_vote_requires_token_and_returns_only_own_vote(): void
    {
        $this->attend('1001');
        $this->attend('1002');

        $this->postJson('/api/assembly/questions/q-1/votes', ['selectedOptions' => ['agree']], $this->voterHeaders('1002'))->assertCreated();

        $this->getJson('/api/assembly/questions/q-1/votes/me?voterId=1002')->assertStatus(401);

        $this->getJson('/api/assembly/questions/q-1/votes/me?voterId=1002', $this->voterHeaders('1001'))->assertNotFound();

        $this->getJson('/api/assembly/questions/q-1/votes/me', $this->voterHeaders('1002'))
            ->assertOk()
            ->assertJsonPath('data.voterId', '1002');
    }
}
