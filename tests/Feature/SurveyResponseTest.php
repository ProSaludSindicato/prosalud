<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Hospital;
use App\Models\Survey;
use App\Models\User;
use App\Services\AfiliadoService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SurveyResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    private function createActiveSurveyWithQuestion(array $surveyOverrides = [], array $questionOverrides = []): Survey
    {
        $survey = Survey::factory()->active()->create($surveyOverrides);
        $survey->questions()->create(array_merge([
            'type' => 'text',
            'label' => '¿Cómo se llama?',
            'is_required' => false,
            'order' => 0,
        ], $questionOverrides));

        return $survey;
    }

    private function responsePayload(Survey $survey, array $overrides = []): array
    {
        $question = $survey->questions()->first();

        return array_merge([
            'answers' => [
                ['question_id' => $question->id, 'value' => 'Respuesta de prueba'],
            ],
        ], $overrides);
    }

    public function test_can_get_public_survey_info(): void
    {
        $survey = $this->createActiveSurveyWithQuestion();

        $this->get("/api/surveys/{$survey->id}/info", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $survey->id)
            ->assertJsonStructure(['data' => ['id', 'title', 'questions']]);
    }

    public function test_survey_info_returns_404_for_draft_survey(): void
    {
        $survey = Survey::factory()->create(['status' => 'draft']);

        $this->get("/api/surveys/{$survey->id}/info", ['Accept' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_survey_info_returns_404_for_closed_survey(): void
    {
        $survey = Survey::factory()->closed()->create();

        $this->get("/api/surveys/{$survey->id}/info", ['Accept' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_verify_respondent_rejects_public_survey(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'public']);

        $this->postJson("/api/surveys/{$survey->id}/verify-respondent", [
            'respondent_document_type' => 'CC',
            'respondent_document_number' => '123',
            'fecha_expedicion' => '2010-01-01',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_verify_respondent_returns_404_for_draft_survey(): void
    {
        $survey = Survey::factory()->create(['status' => 'draft', 'access_type' => 'authenticated']);

        $this->postJson("/api/surveys/{$survey->id}/verify-respondent", [
            'respondent_document_type' => 'CC',
            'respondent_document_number' => '123',
            'fecha_expedicion' => '2010-01-01',
        ])->assertStatus(404);
    }

    public function test_verify_respondent_fails_when_affiliate_not_in_prosanet(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'authenticated']);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'affiliate_not_found',
                'afiliado' => null,
            ]);
        });

        $this->postJson("/api/surveys/{$survey->id}/verify-respondent", [
            'respondent_document_type' => 'CC',
            'respondent_document_number' => '9999999999',
            'fecha_expedicion' => '2010-01-01',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_verify_respondent_succeeds_for_valid_affiliate(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'authenticated']);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'success',
                'afiliado' => [
                    'nombres' => 'Juan',
                    'apellidos' => 'Pérez',
                    'hospital' => 'BELLO',
                ],
            ]);
        });

        $this->postJson("/api/surveys/{$survey->id}/verify-respondent", [
            'respondent_document_type' => 'CC',
            'respondent_document_number' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_verify_respondent_rejects_wrong_hospital_for_restricted_survey(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'BELLO']);
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'restricted']);
        $survey->hospitals()->sync([$hospital->id]);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'success',
                'afiliado' => [
                    'nombres' => 'María',
                    'apellidos' => 'López',
                    'hospital' => 'RIONEGRO',
                ],
            ]);
        });

        $this->postJson("/api/surveys/{$survey->id}/verify-respondent", [
            'respondent_document_type' => 'CC',
            'respondent_document_number' => '1234567890',
            'fecha_expedicion' => '2010-01-01',
        ])->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_can_submit_response_to_public_active_survey(): void
    {
        $survey = $this->createActiveSurveyWithQuestion();

        $this->post("/api/surveys/{$survey->id}/responses", $this->responsePayload($survey), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'submitted_at']]);

        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('survey_response_answers', 1);
    }

    public function test_cannot_submit_to_draft_survey(): void
    {
        $survey = Survey::factory()->create(['status' => 'draft']);
        $survey->questions()->create(['type' => 'text', 'label' => 'Test', 'is_required' => false, 'order' => 0]);

        $this->post("/api/surveys/{$survey->id}/responses", $this->responsePayload($survey), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_cannot_submit_to_closed_survey(): void
    {
        $survey = Survey::factory()->closed()->create();
        $survey->questions()->create(['type' => 'text', 'label' => 'Test', 'is_required' => false, 'order' => 0]);

        $this->post("/api/surveys/{$survey->id}/responses", $this->responsePayload($survey), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_duplicate_prevention_blocks_second_response(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['allows_multiple_responses' => false]);

        $payload = array_merge($this->responsePayload($survey), [
            'respondent_document_number' => '1234567890',
        ]);

        $this->post("/api/surveys/{$survey->id}/responses", $payload, ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->post("/api/surveys/{$survey->id}/responses", $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_duplicate_prevention_allows_multiple_when_enabled(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['allows_multiple_responses' => true]);

        $payload = array_merge($this->responsePayload($survey), [
            'respondent_document_number' => '1234567890',
        ]);

        $this->post("/api/surveys/{$survey->id}/responses", $payload, ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->post("/api/surveys/{$survey->id}/responses", $payload, ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertDatabaseCount('survey_responses', 2);
    }

    public function test_required_questions_must_be_answered(): void
    {
        $survey = Survey::factory()->active()->create();
        $survey->questions()->create(['type' => 'text', 'label' => 'Pregunta obligatoria', 'is_required' => true, 'order' => 0]);

        $this->post("/api/surveys/{$survey->id}/responses", ['answers' => []], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_survey_rejects_without_auth_data(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'authenticated']);

        $this->post("/api/surveys/{$survey->id}/responses", $this->responsePayload($survey), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_authenticated_survey_rejects_invalid_affiliate(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'authenticated']);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'affiliate_not_found',
                'afiliado' => null,
            ]);
        });

        $this->post("/api/surveys/{$survey->id}/responses", array_merge(
            $this->responsePayload($survey),
            [
                'respondent_document_type' => 'CC',
                'respondent_document_number' => '9999999',
                'fecha_expedicion' => '2010-01-01',
            ]
        ), ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_authenticated_survey_accepts_valid_affiliate(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'authenticated']);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'success',
                'afiliado' => [
                    'nombres' => 'Juan',
                    'apellidos' => 'Pérez',
                    'hospital' => 'BELLO',
                ],
            ]);
        });

        $this->post("/api/surveys/{$survey->id}/responses", array_merge(
            $this->responsePayload($survey),
            [
                'respondent_document_type' => 'CC',
                'respondent_document_number' => '1234567890',
                'fecha_expedicion' => '2010-01-01',
            ]
        ), ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertDatabaseHas('survey_responses', ['respondent_name' => 'Juan Pérez']);
    }

    public function test_restricted_survey_rejects_unlisted_hospital(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'BELLO']);
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'restricted']);
        $survey->hospitals()->sync([$hospital->id]);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'success',
                'afiliado' => [
                    'nombres' => 'María',
                    'apellidos' => 'López',
                    'hospital' => 'RIONEGRO',
                ],
            ]);
        });

        $this->post("/api/surveys/{$survey->id}/responses", array_merge(
            $this->responsePayload($survey),
            [
                'respondent_document_type' => 'CC',
                'respondent_document_number' => '1234567890',
                'fecha_expedicion' => '2010-01-01',
                'hospital' => 'RIONEGRO',
            ]
        ), ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_restricted_survey_accepts_listed_hospital(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'BELLO']);
        $survey = $this->createActiveSurveyWithQuestion(['access_type' => 'restricted']);
        $survey->hospitals()->sync([$hospital->id]);

        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->once()->andReturn(true);
            $mock->shouldReceive('authenticateAndGetAfiliadoDetailed')->once()->andReturn([
                'status' => 'success',
                'afiliado' => [
                    'nombres' => 'Juan',
                    'apellidos' => 'Pérez',
                    'hospital' => 'BELLO',
                ],
            ]);
        });

        $this->post("/api/surveys/{$survey->id}/responses", array_merge(
            $this->responsePayload($survey),
            [
                'respondent_document_type' => 'CC',
                'respondent_document_number' => '1234567890',
                'fecha_expedicion' => '2010-01-01',
            ]
        ), ['Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_signature_required_but_not_sent_returns_422(): void
    {
        $survey = $this->createActiveSurveyWithQuestion(['requires_signature' => true]);

        $this->post("/api/surveys/{$survey->id}/responses", $this->responsePayload($survey), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_responses_for_survey(): void
    {
        $survey = $this->createActiveSurveyWithQuestion();
        $survey->responses()->create(['submitted_at' => now()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get("/api/surveys/{$survey->id}/responses", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_admin_can_view_individual_response_with_answers(): void
    {
        $survey = $this->createActiveSurveyWithQuestion();
        $response = $survey->responses()->create(['submitted_at' => now()]);
        $question = $survey->questions()->first();
        $response->answers()->create(['question_id' => $question->id, 'value' => 'Test answer']);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get("/api/surveys/{$survey->id}/responses/{$response->id}", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $response->id)
            ->assertJsonStructure(['data' => ['answers']]);
    }

    public function test_export_initiates_async_job_and_returns_202(): void
    {
        $survey = $this->createActiveSurveyWithQuestion();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('surveys.export');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post("/api/surveys/{$survey->id}/export", [], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['job_id', 'status']);
    }
}
