<?php

namespace Tests\Feature;

use App\Models\SocioDemographicSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocioDemographicSurveyIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_index_includes_labor_restriction_flag_in_list_items(): void
    {
        $this->withoutMiddleware();

        $this->createSurvey('1000000001', 'si');
        $this->createSurvey('1000000002', 'no');

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/socio-demographic-surveys?year=2026');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = collect($response->json('data'));

        $withRestriction = $data->firstWhere('id', '1000000001');
        $withoutRestriction = $data->firstWhere('id', '1000000002');

        $this->assertTrue($withRestriction['tiene_restriccion_laboral']);
        $this->assertFalse($withoutRestriction['tiene_restriccion_laboral']);
    }

    public function test_index_filters_surveys_with_labor_restriction(): void
    {
        $this->withoutMiddleware();

        $this->createSurvey('1000000001', 'si');
        $this->createSurvey('1000000002', 'no');
        $this->createSurvey('1000000003', 'si');

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/socio-demographic-surveys?year=2026&recomendacion_restriccion_laboral=si');

        $response->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(['1000000001', '1000000003'], $ids);
    }

    public function test_index_filters_surveys_without_labor_restriction(): void
    {
        $this->withoutMiddleware();

        $this->createSurvey('1000000001', 'si');
        $this->createSurvey('1000000002', 'no');

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/socio-demographic-surveys?year=2026&recomendacion_restriccion_laboral=no');

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', '1000000002')
            ->assertJsonPath('data.0.tiene_restriccion_laboral', false);
    }

    private function createSurvey(string $id, string $recomendacionRestriccionLaboral): SocioDemographicSurvey
    {
        return SocioDemographicSurvey::query()->create([
            'id' => $id,
            'survey_type' => 'active_affiliate',
            'correo' => "test-{$id}@example.com",
            'tipo_documento' => 'CC',
            'numero_documento' => $id,
            'hospital' => 'HOSP1',
            'profesion' => 'Enfermera',
            'datos_sociodemograficos' => ['estado_civil' => 'soltero'],
            'datos_consumo' => ['consumo_licor' => 'no'],
            'condiciones_salud' => ['hipertension' => 'no'],
            'limitaciones_fisicas' => ['ninguna' => true],
            'recomendacion_restriccion_laboral' => $recomendacionRestriccionLaboral,
            'numero_documento_firma' => $id,
            'created_at' => now()->setYear(2026),
        ]);
    }
}
