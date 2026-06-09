<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWithApiToken;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureApiTokenIsValid;
use App\Models\SocioDemographicSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocioDemographicSurveyBarrioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_show_endpoint_includes_barrio(): void
    {
        $this->withoutMiddleware([
            AuthenticateWithApiToken::class,
            EnsureApiTokenIsValid::class,
            CheckPermission::class,
        ]);

        $survey = SocioDemographicSurvey::query()->create([
            'id' => '1000000099',
            'survey_type' => 'active_affiliate',
            'correo' => 'barrio-test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '1000000099',
            'hospital' => 'HOSP1',
            'profesion' => 'Enfermera',
            'municipio' => 'medellin',
            'barrio' => 'LAURELES',
            'datos_sociodemograficos' => ['estado_civil' => 'soltero'],
            'datos_consumo' => ['consumo_licor' => 'no'],
            'condiciones_salud' => ['hipertension' => 'no'],
            'limitaciones_fisicas' => ['ninguna' => true],
            'recomendacion_restriccion_laboral' => 'no',
            'numero_documento_firma' => '1000000099',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('socio_demographic_surveys', [
            'id' => $survey->id,
            'barrio' => 'LAURELES',
        ]);

        $response = $this->getJson("/api/socio-demographic-surveys/{$survey->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.barrio', 'LAURELES');
    }

    public function test_store_validation_requires_barrio(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/socio-demographic-surveys', [
            'correo' => 'test@example.com',
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '1234567890',
            'recaptcha_token' => 'test-token',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['barrio']);
    }
}
