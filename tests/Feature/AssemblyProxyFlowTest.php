<?php

namespace Tests\Feature;

use App\Events\VoteRegistered;
use App\Models\ApiToken;
use App\Models\Assembly;
use App\Models\AssemblyProxy;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use App\Models\User;
use App\Models\VotingSetting;
use App\Services\AssemblyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AssemblyProxyFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const HEADER = [
        'CEDULA', 'NOMBRE Y APELLIDOS', 'FECHA DE EXPEDICION', 'SEDE', 'ESTADO BD', 'PROCESO', 'CORREO',
        'RESPONSABLE CONVOCATORIA', 'ASISTENCIA', 'VOTACION', 'CC APODERADO', 'APODERADO', 'FECHA EXPE APODERADO',
        'ASISTENCIA APODERADO', 'OBSERVACIONES',
    ];

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('prosalud-private');
        Event::fake([VoteRegistered::class]);
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

    /**
     * @param  list<list<string>>|null  $rows
     */
    private function uploadDelegates(int $assemblyId, ?array $rows = null): TestResponse
    {
        $rows ??= [
            $this->delegateRow('11111111', 'Apoderado Uno', '1/14/2002'),
            $this->delegateRow('22222222', 'Representado Dos', '3/20/2005', ['11111111', 'Apoderado Uno', '1/14/2002']),
            $this->delegateRow('33333333', 'Representado Tres', '5/10/2008', ['11111111', 'Apoderado Uno', '1/14/2002']),
            $this->delegateRow('44444444', 'Delegado Cuatro', '7/15/2010'),
            $this->delegateRow('55555555', 'Representado Cinco', '2/11/2011', ['99999999', 'Apoderada Externa', '10/11/2012']),
        ];

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([self::HEADER, ...$rows]);
        $tempPath = sys_get_temp_dir().'/proxy_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $response = $this->asAdmin()->post("/api/assembly/assemblies/{$assemblyId}/delegates-file", [
            'file' => new UploadedFile($tempPath, 'delegados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);

        @unlink($tempPath);

        return $response;
    }

    /**
     * @param  array{0: string, 1: string, 2: string}|null  $proxy  CC APODERADO, APODERADO and FECHA EXPE APODERADO.
     * @return list<string>
     */
    private function delegateRow(string $document, string $name, string $issueDate, ?array $proxy = null): array
    {
        return [
            $document, $name, $issueDate, 'BELLO', 'Activo', 'AUXILIAR', '', 'LUZMA',
            $proxy ? 'PODER' : 'CONFIRMADO', '', $proxy[0] ?? '', $proxy[1] ?? '', $proxy[2] ?? '', '', $proxy ? 'PODER OK' : '',
        ];
    }

    private function createAssembly(): Assembly
    {
        $assembly = $this->asAdmin()->postJson('/api/assembly/assemblies', [
            'name' => 'Asamblea con poderes',
            'activate' => true,
        ])->assertCreated()->json('data');

        return Assembly::query()->findOrFail($assembly['id']);
    }

    private function createAssemblyWithDelegates(): Assembly
    {
        $assembly = $this->createAssembly();
        $this->uploadDelegates($assembly->id)->assertCreated();

        return $assembly;
    }

    private function register(string $document, string $issueDate): TestResponse
    {
        return $this->postJson('/api/activos/search-hospital', [
            'documento' => $document,
            'fecha_expedicion' => $issueDate,
            'signature' => self::SIGNATURE,
        ])->assertOk()->assertJsonPath('found', true);
    }

    private function declare(string $token): TestResponse
    {
        return $this->postJson('/api/assembly/proxies/declare', [], ['X-Voter-Token' => $token]);
    }

    private function vote(string $questionId, string $option, string $token): TestResponse
    {
        return $this->postJson("/api/assembly/questions/{$questionId}/votes", [
            'selectedOptions' => [$option],
        ], ['X-Voter-Token' => $token]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function startQuestion(array $overrides = []): array
    {
        return $this->asAdmin()->postJson('/api/assembly/live-question/start', $overrides)->assertCreated()->json('data');
    }

    public function test_validation_lists_delegates_assigned_in_file_without_registering_them(): void
    {
        $this->createAssemblyWithDelegates();

        $this->register('11111111', '2002-01-14')
            ->assertJsonPath('proxy.listed', [
                ['document' => '22222222', 'name' => 'Representado Dos'],
                ['document' => '33333333', 'name' => 'Representado Tres'],
            ])
            ->assertJsonPath('proxy.registered', [])
            ->assertJsonMissingPath('data.representados');

        $this->assertSame(0, AssemblyProxy::query()->count());
    }

    public function test_declared_proxy_votes_in_block_for_represented_delegates(): void
    {
        $this->createAssemblyWithDelegates();
        $token = $this->register('11111111', '2002-01-14')->json('voter_token');

        $this->declare($token)
            ->assertOk()
            ->assertJsonCount(2, 'data.registered')
            ->assertJsonPath('data.skipped', []);

        $this->getJson('/api/assembly/proxies/me', ['X-Voter-Token' => $token])
            ->assertOk()
            ->assertJsonCount(2, 'data.registered');

        $question = $this->startQuestion();

        $this->vote($question['id'], 'agree', $token)
            ->assertCreated()
            ->assertJsonCount(3, 'data.castFor')
            ->assertJsonPath('data.voterId', '11111111');

        $this->getJson("/api/assembly/questions/{$question['id']}/results")
            ->assertOk()
            ->assertJsonPath('data.totalVotes', 3)
            ->assertJsonPath('data.optionResults.0.votes', 3);

        $this->assertSame(3, AssemblyQuestion::query()->findOrFail($question['id'])->votes_count);
        $this->assertDatabaseHas('assembly_votes', ['voter_id' => '11111111', 'cast_by_document' => null, 'proxy_id' => null]);
        $this->assertDatabaseHas('assembly_votes', ['voter_id' => '22222222', 'cast_by_document' => '11111111']);
        $this->assertNotNull(AssemblyVote::query()->where('voter_id', '33333333')->value('proxy_id'));

        $this->vote($question['id'], 'disagree', $token)
            ->assertStatus(400)
            ->assertJsonPath('message', 'No se permite cambiar el voto');
    }

    public function test_changing_vote_updates_every_vote_cast_by_the_proxy(): void
    {
        $this->createAssemblyWithDelegates();
        $token = $this->register('11111111', '2002-01-14')->json('voter_token');
        $this->declare($token)->assertOk();
        $question = $this->startQuestion(['allowChangeVote' => true]);

        $this->vote($question['id'], 'agree', $token)->assertCreated();
        $this->vote($question['id'], 'disagree', $token)->assertCreated()->assertJsonCount(3, 'data.castFor');

        $this->assertSame(3, AssemblyVote::query()->where('question_id', $question['id'])->whereJsonContains('selected_options', 'disagree')->count());
        $this->assertSame(3, AssemblyQuestion::query()->findOrFail($question['id'])->votes_count);
    }

    public function test_declaration_skips_represented_delegate_who_already_attended_in_person(): void
    {
        $this->createAssemblyWithDelegates();
        $this->register('22222222', '2005-03-20');
        $token = $this->register('11111111', '2002-01-14')->json('voter_token');

        $this->declare($token)
            ->assertOk()
            ->assertJsonPath('data.registered', [['document' => '33333333', 'name' => 'Representado Tres']])
            ->assertJsonPath('data.skipped.0.document', '22222222')
            ->assertJsonPath('data.skipped.0.reason', 'attended_in_person');
    }

    public function test_represented_delegate_attending_in_person_revokes_proxy_and_keeps_cast_votes(): void
    {
        $this->createAssemblyWithDelegates();
        $proxyToken = $this->register('11111111', '2002-01-14')->json('voter_token');
        $this->declare($proxyToken)->assertOk();

        $question = $this->startQuestion();
        $this->vote($question['id'], 'agree', $proxyToken)->assertCreated();

        $representedToken = $this->register('22222222', '2005-03-20')->json('voter_token');

        $this->assertSame(AssemblyProxy::STATUS_REVOKED, AssemblyProxy::query()->where('represented_document', '22222222')->value('status'));

        $this->vote($question['id'], 'disagree', $representedToken)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Su voto en esta pregunta ya fue emitido por su apoderado');

        $nextQuestion = $this->startQuestion();
        $this->vote($nextQuestion['id'], 'agree', $proxyToken)->assertCreated()->assertJsonCount(2, 'data.castFor');
        $this->vote($nextQuestion['id'], 'disagree', $representedToken)->assertCreated()->assertJsonCount(1, 'data.castFor');

        $this->assertDatabaseHas('assembly_votes', ['question_id' => $nextQuestion['id'], 'voter_id' => '22222222', 'cast_by_document' => null]);
    }

    public function test_delegate_without_assigned_proxies_cannot_declare(): void
    {
        $this->createAssemblyWithDelegates();
        $token = $this->register('44444444', '2010-07-15')
            ->assertJsonPath('proxy.listed', [])
            ->json('voter_token');

        $this->declare($token)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_PROXIES_ASSIGNED');
    }

    public function test_declaration_requires_valid_voter_session(): void
    {
        $this->createAssemblyWithDelegates();

        $this->declare('token-invalido')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'VOTER_SESSION_INVALID');
    }

    public function test_admin_can_list_and_revoke_proxies(): void
    {
        $this->createAssemblyWithDelegates();
        $token = $this->register('11111111', '2002-01-14')->json('voter_token');
        $this->declare($token)->assertOk();

        $proxies = $this->asAdmin()->getJson('/api/assembly/proxies')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        $revokedId = collect($proxies)->firstWhere('representedDocument', '22222222')['id'];

        $this->asAdmin()->postJson("/api/assembly/proxies/{$revokedId}/revoke", ['reason' => 'Poder no presentado'])
            ->assertOk();
        $this->asAdmin()->postJson("/api/assembly/proxies/{$revokedId}/revoke")
            ->assertStatus(422);

        $this->assertDatabaseHas('assembly_proxies', ['id' => $revokedId, 'status' => 'revoked', 'revoked_reason' => 'Poder no presentado']);

        $question = $this->startQuestion();
        $this->vote($question['id'], 'agree', $token)->assertCreated()->assertJsonCount(2, 'data.castFor');

        $this->getJson('/api/assembly/quorum')
            ->assertOk()
            ->assertJsonPath('data.attendance', ['inPerson' => 1, 'byProxy' => 1, 'total' => 2]);
    }

    public function test_proxy_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/assembly/proxies')->assertUnauthorized();
        $this->postJson('/api/assembly/proxies/1/revoke')->assertUnauthorized();
    }

    public function test_upload_rejects_inconsistent_proxy_assignments(): void
    {
        $assembly = $this->createAssembly();

        $message = $this->uploadDelegates($assembly->id, [
            $this->delegateRow('11111111', 'Uno', '1/14/2002', ['11111111', 'Uno', '1/14/2002']),
            $this->delegateRow('22222222', 'Dos', '3/20/2005', ['33333333', 'Tres', '5/10/2008']),
            $this->delegateRow('33333333', 'Tres', '5/10/2008', ['44444444', 'Cuatro', '7/15/2010']),
            $this->delegateRow('44444444', 'Cuatro', '7/15/2010'),
            $this->delegateRow('55555555', 'Cinco', '2/11/2011', ['99999999', 'Externa', '']),
        ])->assertStatus(422)->json('message');

        $this->assertStringContainsString('11111111 figura como su propio apoderado', $message);
        $this->assertStringContainsString('el apoderado 33333333 también delega su voto', $message);
        $this->assertStringContainsString('99999999 no es delegado y no tiene FECHA EXPE APODERADO', $message);
        $this->assertStringNotContainsString('44444444', $message);
    }

    public function test_external_proxy_signs_attendance_and_votes_only_for_represented_delegates(): void
    {
        $assembly = $this->createAssemblyWithDelegates();

        $this->postJson('/api/activos/search-hospital', [
            'documento' => '99999999',
            'fecha_expedicion' => '2012-10-12',
            'signature' => self::SIGNATURE,
        ])->assertOk()->assertJsonPath('found', false);

        $token = $this->register('99999999', '2012-10-11')
            ->assertJsonPath('data.nombre_apellidos', 'Apoderada Externa')
            ->assertJsonPath('data.es_delegado', false)
            ->assertJsonPath('proxy.listed', [['document' => '55555555', 'name' => 'Representado Cinco']])
            ->json('voter_token');

        $this->assertFalse($assembly->attendances()->where('document_number', '99999999')->firstOrFail()->is_delegate);

        $this->declare($token)->assertOk()->assertJsonCount(1, 'data.registered');

        $question = $this->startQuestion();
        $this->vote($question['id'], 'agree', $token)
            ->assertCreated()
            ->assertJsonPath('data.castFor', [['document' => '55555555', 'name' => 'Representado Cinco']])
            ->assertJsonPath('data.voterId', '55555555');

        $this->assertDatabaseMissing('assembly_votes', ['voter_id' => '99999999']);
        $this->assertSame(1, AssemblyQuestion::query()->findOrFail($question['id'])->votes_count);

        $this->getJson("/api/assembly/questions/{$question['id']}/votes/me", ['X-Voter-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.selectedOptions', ['agree']);

        $this->getJson('/api/assembly/quorum')
            ->assertOk()
            ->assertJsonPath('data.attendance', ['inPerson' => 0, 'byProxy' => 1, 'total' => 1]);
    }

    public function test_external_proxy_without_active_proxies_cannot_vote(): void
    {
        $this->createAssemblyWithDelegates();
        $token = $this->register('99999999', '2012-10-11')->json('voter_token');
        $question = $this->startQuestion();

        $this->vote($question['id'], 'agree', $token)
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'NO_ACTIVE_VOTES');

        $this->assertSame(0, AssemblyVote::query()->count());
    }

    public function test_report_includes_proxies_and_vote_detail_sheets(): void
    {
        $assembly = $this->createAssemblyWithDelegates();
        $token = $this->register('11111111', '2002-01-14')->json('voter_token');
        $this->declare($token)->assertOk();
        $question = $this->startQuestion();
        $this->vote($question['id'], 'agree', $token)->assertCreated();

        $path = app(AssemblyReportService::class)->generateReport($assembly->id);
        $spreadsheet = IOFactory::load($path);
        @unlink($path);

        $proxiesSheet = $spreadsheet->getSheetByName('Poderes');
        $this->assertNotNull($proxiesSheet);
        $this->assertSame('11111111', (string) $proxiesSheet->getCell('B2')->getValue());
        $this->assertSame(1, (int) $proxiesSheet->getCell('J2')->getValue());

        $detailSheet = $spreadsheet->getSheetByName('Detalle de Votos');
        $this->assertNotNull($detailSheet);
        $this->assertSame(4, $detailSheet->getHighestDataRow());
        $this->assertSame('Tipo de Asistente', $spreadsheet->getSheetByName('Listado de Asistencia')->getCell('I1')->getValue());
    }
}
