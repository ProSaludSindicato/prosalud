<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Hospital;
use App\Models\Survey;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SurveyManagementTest extends TestCase
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

    private function makeUserWithPermission(string $permission): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function surveyPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Encuesta de Prueba',
            'description' => 'Descripción de prueba',
            'access_type' => 'public',
            'status' => 'draft',
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'questions' => [
                [
                    'type' => 'text',
                    'label' => '¿Cuál es su nombre?',
                    'is_required' => true,
                    'order' => 0,
                ],
                [
                    'type' => 'single_choice',
                    'label' => '¿Cuál es su área?',
                    'is_required' => false,
                    'order' => 1,
                    'options' => [
                        ['value' => 'medicina', 'label' => 'Medicina'],
                        ['value' => 'enfermeria', 'label' => 'Enfermería'],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_unauthenticated_cannot_list_surveys(): void
    {
        $this->get('/api/surveys', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_user_without_surveys_view_cannot_list_surveys(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get('/api/surveys', ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_user_without_surveys_manage_cannot_create_survey(): void
    {
        $user = $this->makeUserWithPermission('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload(), ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_user_with_view_permission_can_list_surveys(): void
    {
        Survey::factory()->active()->create();
        $user = $this->makeUserWithPermission('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get('/api/surveys', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_with_manage_permission_can_create_survey(): void
    {
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload(), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'title', 'status', 'questions']]);

        $this->assertDatabaseHas('surveys', ['title' => 'Encuesta de Prueba']);
        $this->assertDatabaseCount('survey_questions', 2);
    }

    public function test_creating_restricted_survey_syncs_hospitals(): void
    {
        $hospital = Hospital::factory()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload([
                'access_type' => 'restricted',
                'hospital_ids' => [$hospital->id],
            ]), ['Accept' => 'application/json'])
            ->assertStatus(201);

        $survey = Survey::first();
        $this->assertTrue($survey->hospitals->contains($hospital));
    }

    public function test_can_view_survey_detail(): void
    {
        $survey = Survey::factory()->create();
        $user = $this->makeUserWithPermission('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get("/api/surveys/{$survey->id}", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $survey->id);
    }

    public function test_can_update_survey_questions(): void
    {
        $survey = Survey::factory()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $survey->questions()->create(['type' => 'text', 'label' => 'Old Question', 'is_required' => false, 'order' => 0]);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->put("/api/surveys/{$survey->id}", [
                'title' => 'Updated Title',
                'questions' => [
                    ['type' => 'number', 'label' => 'New Question', 'is_required' => true, 'order' => 0],
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseMissing('survey_questions', ['label' => 'Old Question']);
        $this->assertDatabaseHas('survey_questions', ['label' => 'New Question', 'survey_id' => $survey->id]);
    }

    public function test_can_update_survey_with_ranking_question_type(): void
    {
        $survey = Survey::factory()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->put("/api/surveys/{$survey->id}", [
                'questions' => [
                    [
                        'type' => 'ranking',
                        'label' => 'Ordene prioridades',
                        'is_required' => true,
                        'order' => 0,
                        'ranking_unique_priority' => false,
                        'options' => [
                            ['value' => 'A', 'label' => 'Primera'],
                            ['value' => 'B', 'label' => 'Segunda'],
                        ],
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseHas('survey_questions', [
            'survey_id' => $survey->id,
            'type' => 'ranking',
            'label' => 'Ordene prioridades',
            'ranking_unique_priority' => 0,
        ]);
    }

    public function test_can_create_survey_with_yes_no_question_type(): void
    {
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $payload = $this->surveyPayload();
        $payload['questions'][] = [
            'type' => 'yes_no',
            'label' => '¿Está de acuerdo?',
            'is_required' => true,
            'order' => 2,
        ];

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $payload, ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertDatabaseHas('survey_questions', [
            'type' => 'yes_no',
            'label' => '¿Está de acuerdo?',
        ]);
    }

    public function test_status_transition_draft_to_active(): void
    {
        $survey = Survey::factory()->create(['status' => 'draft']);
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->patch("/api/surveys/{$survey->id}/status", ['status' => 'active'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('surveys', ['id' => $survey->id, 'status' => 'active']);
    }

    public function test_status_transition_active_to_closed(): void
    {
        $survey = Survey::factory()->active()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->patch("/api/surveys/{$survey->id}/status", ['status' => 'closed'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_cannot_delete_active_survey(): void
    {
        $survey = Survey::factory()->active()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->delete("/api/surveys/{$survey->id}", [], ['Accept' => 'application/json'])
            ->assertStatus(409);

        $this->assertDatabaseHas('surveys', ['id' => $survey->id]);
    }

    public function test_can_delete_draft_survey(): void
    {
        $survey = Survey::factory()->create(['status' => 'draft']);
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->delete("/api/surveys/{$survey->id}", [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('surveys', ['id' => $survey->id]);
    }

    public function test_creating_survey_requires_at_least_one_question(): void
    {
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload(['questions' => []]), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_can_duplicate_survey_as_draft(): void
    {
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $original = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload([
                'title' => 'Encuesta Original',
                'status' => 'active',
            ]), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('data');

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post("/api/surveys/{$original['id']}/duplicate", [], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Copia de Encuesta Original')
            ->json('data');

        $this->assertNotEquals($original['id'], $response['id']);
        $this->assertCount(count($original['questions']), $response['questions']);
        $this->assertNull($response['start_date']);
        $this->assertNull($response['end_date']);
    }

    public function test_duplicate_copies_hospital_associations_for_restricted_survey(): void
    {
        $hospital = Hospital::factory()->create();
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $original = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload([
                'access_type' => 'restricted',
                'hospital_ids' => [$hospital->id],
            ]), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('data');

        $copy = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post("/api/surveys/{$original['id']}/duplicate", [], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('data');

        $copySurvey = Survey::find($copy['id']);
        $this->assertTrue($copySurvey->hospitals->contains($hospital->id));
    }

    public function test_user_without_manage_permission_cannot_duplicate_survey(): void
    {
        $user = $this->makeUserWithPermission('surveys.manage');
        $token = $this->apiCookieForUser($user);

        $original = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/surveys', $this->surveyPayload(), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('data');

        $viewUser = $this->makeUserWithPermission('surveys.view');
        $viewToken = $this->apiCookieForUser($viewUser);

        $this->withUnencryptedCookie('prosalud_auth_token', $viewToken)
            ->post("/api/surveys/{$original['id']}/duplicate", [], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_filter_options_returns_hospitals(): void
    {
        Hospital::factory()->create(['name' => 'Hospital Test']);
        $user = $this->makeUserWithPermission('surveys.view');
        $token = $this->apiCookieForUser($user);

        $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->get('/api/surveys/filter-options', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['hospitals', 'statuses', 'access_types']]);
    }
}
