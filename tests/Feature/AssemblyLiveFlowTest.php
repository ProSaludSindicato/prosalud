<?php

namespace Tests\Feature;

use App\Events\VoteRegistered;
use App\Models\ApiToken;
use App\Models\Assembly;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use App\Models\QuorumConfig;
use App\Models\User;
use App\Models\VotingSetting;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AssemblyLiveFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('prosalud-private');
        VotingSetting::current()->update(['active_mode' => 'assembly']);

        $admin = User::factory()->create();
        $admin->givePermissionTo(['assembly.questions.manage', 'assembly.quorum.manage']);
        $this->adminToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $admin->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $this->adminToken),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function asAdmin(): static
    {
        return $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $this->adminToken);
    }

    private function createActiveAssemblyWithDelegates(): Assembly
    {
        $assembly = $this->asAdmin()->postJson('/api/assembly/assemblies', [
            'name' => 'Asamblea General 2026',
            'activate' => true,
        ])->assertCreated()->json('data');

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['CEDULA', 'NOMBRE Y APELLIDOS', 'SEDE', 'ESTADO BD', 'PROCESO', 'FECHA DE EXPEDICION'],
            ['11111111', 'Delegado Uno', 'BELLO', 'ACTIVO', 'AUXILIAR', '1/14/2002'],
            ['22222222', 'Delegado Dos', 'BELLO', 'ACTIVO', 'AUXILIAR', '3/20/2005'],
        ]);
        $tempPath = sys_get_temp_dir().'/flow_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $this->asAdmin()->post("/api/assembly/assemblies/{$assembly['id']}/delegates-file", [
            'file' => new UploadedFile($tempPath, 'delegados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertCreated();

        @unlink($tempPath);

        return Assembly::query()->findOrFail($assembly['id']);
    }

    private function registerDelegate(string $document, string $issueDate): string
    {
        $response = $this->postJson('/api/activos/search-hospital', [
            'documento' => $document,
            'fecha_expedicion' => $issueDate,
            'signature' => self::SIGNATURE,
        ])->assertOk()->assertJsonPath('found', true);

        return $response->json('voter_token');
    }

    private function vote(string $questionId, string $option, string $voterToken): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/assembly/questions/{$questionId}/votes", [
            'selectedOptions' => [$option],
        ], ['X-Voter-Token' => $voterToken]);
    }

    public function test_full_live_assembly_flow(): void
    {
        Event::fake([VoteRegistered::class]);

        $assembly = $this->createActiveAssemblyWithDelegates();

        $this->getJson('/api/assembly/current')->assertOk()->assertJsonPath('data.id', $assembly->id);

        $tokenOne = $this->registerDelegate('11111111', '2002-01-14');
        $tokenTwo = $this->registerDelegate('22222222', '2005-03-20');
        $this->assertNotEmpty($tokenOne);

        $this->postJson('/api/activos/search-hospital', [
            'documento' => '99999999',
            'fecha_expedicion' => '2002-01-14',
            'signature' => self::SIGNATURE,
        ])->assertOk()->assertJsonPath('found', false)->assertJsonMissingPath('voter_token');

        $this->asAdmin()->getJson('/api/assembly/attendance')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->assertSame('2002-01-14', $assembly->attendances()->where('document_number', '11111111')->firstOrFail()->issue_date_normalized?->toDateString());
        $this->assertNotNull($assembly->attendances()->where('document_number', '11111111')->value('signature_path'));

        $this->asAdmin()->putJson('/api/assembly/quorum', [
            'totalDelegates' => 2,
            'presentDelegates' => 2,
            'requiredPercentage' => 50,
        ])->assertOk()->assertJsonPath('data.verified', true);

        $question = $this->asAdmin()->postJson('/api/assembly/live-question/start', [
            'title' => '¿Aprueba el orden del día?',
            'timeLimit' => 60,
            'majorityType' => 'SIMPLE',
            'quorumRequired' => false,
            'allowChangeVote' => false,
            'resultsVisible' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN')->json('data');

        $this->getJson('/api/assembly/questions')
            ->assertOk()
            ->assertJsonPath('0.id', $question['id'])
            ->assertJsonPath('0.status', 'OPEN')
            ->assertJsonCount(2, '0.options');

        $this->vote($question['id'], 'agree', $tokenOne)->assertCreated();
        $this->vote($question['id'], 'disagree', $tokenOne)
            ->assertStatus(400)
            ->assertJsonPath('message', 'No se permite cambiar el voto');
        $this->vote($question['id'], 'agree', $tokenTwo)->assertCreated();

        Event::assertDispatchedTimes(VoteRegistered::class, 2);

        $this->getJson("/api/assembly/questions/{$question['id']}/votes/me", ['X-Voter-Token' => $tokenOne])
            ->assertOk()
            ->assertJsonPath('data.selectedOptions', ['agree']);

        $this->getJson("/api/assembly/questions/{$question['id']}/results")
            ->assertOk()
            ->assertJsonPath('data.totalVotes', 2)
            ->assertJsonPath('data.optionResults.0.votes', 2)
            ->assertJsonPath('data.optionResults.0.percentage', 100)
            ->assertJsonPath('data.majorityAchieved', true);

        $this->asAdmin()->postJson('/api/assembly/live-question/close')
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.votesCount', 2);

        $this->vote($question['id'], 'agree', $tokenTwo)
            ->assertStatus(400)
            ->assertJsonPath('message', 'La pregunta no está abierta para votación');

        $second = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pregunta #2')
            ->json('data');

        $this->vote($second['id'], 'disagree', $tokenOne)->assertCreated();

        $this->assertSame(3, AssemblyVote::query()->where('assembly_id', $assembly->id)->count());
    }

    public function test_starting_a_question_closes_the_previous_open_one(): void
    {
        $this->createActiveAssemblyWithDelegates();

        $first = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->assertCreated()->json('data');
        $second = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->assertCreated()->json('data');

        $this->assertSame('CLOSED', AssemblyQuestion::query()->findOrFail($first['id'])->status);
        $this->assertSame('OPEN', AssemblyQuestion::query()->findOrFail($second['id'])->status);
    }

    public function test_re_registering_a_delegate_does_not_duplicate_attendance(): void
    {
        $assembly = $this->createActiveAssemblyWithDelegates();

        $this->registerDelegate('11111111', '2002-01-14');
        $this->registerDelegate('11111111', '14/01/2002');

        $this->assertSame(1, $assembly->attendances()->count());
    }

    public function test_deleted_attendance_invalidates_voter_session(): void
    {
        $assembly = $this->createActiveAssemblyWithDelegates();
        $token = $this->registerDelegate('11111111', '2002-01-14');
        $question = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->json('data');

        $attendanceId = $assembly->attendances()->value('id');
        $this->asAdmin()->deleteJson("/api/assembly/attendance/{$attendanceId}")->assertOk();

        $this->vote($question['id'], 'agree', $token)
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');
    }

    public function test_vote_is_reported_as_saved_when_broadcast_fails(): void
    {
        $this->createActiveAssemblyWithDelegates();
        $token = $this->registerDelegate('11111111', '2002-01-14');
        $question = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->json('data');

        $this->mock(BroadcastFactory::class, function ($mock): void {
            $mock->shouldReceive('queue')->andThrow(new \RuntimeException('Reverb unavailable'));
        });

        $this->vote($question['id'], 'agree', $token)->assertCreated();

        $this->assertDatabaseHas('assembly_votes', ['question_id' => $question['id'], 'voter_id' => '11111111']);
    }

    public function test_concurrent_duplicate_vote_returns_conflict_instead_of_server_error(): void
    {
        Event::fake([VoteRegistered::class]);

        $assembly = $this->createActiveAssemblyWithDelegates();
        $token = $this->registerDelegate('11111111', '2002-01-14');
        $question = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->json('data');

        AssemblyVote::creating(function (AssemblyVote $vote) use ($assembly): void {
            DB::table('assembly_votes')->insert([
                'id' => (string) Str::uuid(),
                'question_id' => $vote->question_id,
                'assembly_id' => $assembly->id,
                'voter_id' => $vote->voter_id,
                'voter_name' => 'Solicitud concurrente',
                'selected_options' => json_encode(['agree']),
                'voted_at' => now(),
            ]);
        });

        $this->vote($question['id'], 'disagree', $token)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'VOTE_ALREADY_REGISTERED');

        $this->assertSame(0, AssemblyQuestion::query()->findOrFail($question['id'])->votes_count);
    }

    public function test_opening_a_quorum_required_question_without_quorum_returns_422_and_keeps_current_open(): void
    {
        $assembly = $this->createActiveAssemblyWithDelegates();
        $open = $this->asAdmin()->postJson('/api/assembly/live-question/start', [])->json('data');

        $pending = AssemblyQuestion::query()->create([
            'title' => 'Requiere quórum',
            'type' => 'SINGLE',
            'status' => 'PENDING',
            'quorum_required' => true,
            'assembly_id' => $assembly->id,
            'order' => 5,
        ]);

        $this->asAdmin()->postJson("/api/assembly/questions/{$pending->id}/open")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El quórum debe estar verificado para abrir esta pregunta');

        $this->assertSame('OPEN', AssemblyQuestion::query()->findOrFail($open['id'])->status);
        $this->assertSame('PENDING', $pending->fresh()->status);
    }

    public function test_creating_a_question_without_active_assembly_does_not_leave_a_transaction_open(): void
    {
        $this->asAdmin()->postJson('/api/assembly/questions', ['title' => 'Sin asamblea'])
            ->assertStatus(400);

        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_absolute_majority_uses_the_questions_assembly_quorum(): void
    {
        $assembly = $this->createActiveAssemblyWithDelegates();
        QuorumConfig::query()->create([
            'assembly_id' => $assembly->id,
            'total_delegates' => 10,
            'present_delegates' => 10,
            'required_percentage' => 50,
        ]);

        $question = $this->asAdmin()->postJson('/api/assembly/live-question/start', ['majorityType' => 'ABSOLUTE'])->json('data');
        $token = $this->registerDelegate('11111111', '2002-01-14');
        $this->vote($question['id'], 'agree', $token)->assertCreated();

        $this->getJson("/api/assembly/questions/{$question['id']}/results")
            ->assertOk()
            ->assertJsonPath('data.majorityAchieved', false);
    }
}
