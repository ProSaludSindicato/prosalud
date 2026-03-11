<?php

namespace Tests\Feature;

use App\Models\SocioDemographicSurvey;
use App\Services\AfiliadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackfillNewEntrySurveyHospitalFromConvenioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_hospital_for_new_entry_surveys_when_afiliado_is_active_and_has_convenio(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000001',
            'survey_type' => 'new_entry',
            'correo' => 'test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '123456789',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'hospital' => 'E.S.E HOSPITAL LA MARÍA',
            'profesion' => 'AUXILIAR',
            'datos_sociodemograficos' => [],
            'datos_consumo' => [],
            'condiciones_salud' => [],
            'limitaciones_fisicas' => [],
            'recomendacion_restriccion_laboral' => '',
            'numero_documento_firma' => '123456789',
            'pais_nacimiento' => 'CO',
            'created_at' => now(),
        ]);

        $afiliadoServiceMock = Mockery::mock(AfiliadoService::class);
        $afiliadoServiceMock
            ->shouldReceive('getAfiliadoByDocumentoOnly')
            ->once()
            ->with($survey->numero_documento)
            ->andReturn([
                'documento' => $survey->numero_documento,
                'tipo_documento' => $survey->tipo_documento,
                'nombres' => $survey->nombres,
                'apellidos' => $survey->apellidos,
                'correo_personal' => $survey->correo,
                'nombre_completo' => "{$survey->nombres} {$survey->apellidos}",
                'estado' => 'ACTIVO',
                'hospital' => 'HLM - GRUPO 1',
            ]);

        $this->app->instance(AfiliadoService::class, $afiliadoServiceMock);

        $this->artisan('surveys:backfill-new-entry-hospital-from-convenio', [
            '--force' => true,
            '--limit' => 10,
        ])
            ->assertExitCode(0);

        $survey->refresh();

        $this->assertSame('HLM - GRUPO 1', $survey->hospital);
    }

    public function test_it_skips_surveys_when_afiliado_is_not_active(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000002',
            'survey_type' => 'new_entry',
            'correo' => 'inactive@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '987654321',
            'nombres' => 'Ana',
            'apellidos' => 'López',
            'hospital' => 'E.S.E HOSPITAL LA MARÍA',
            'profesion' => 'AUXILIAR',
            'datos_sociodemograficos' => [],
            'datos_consumo' => [],
            'condiciones_salud' => [],
            'limitaciones_fisicas' => [],
            'recomendacion_restriccion_laboral' => '',
            'numero_documento_firma' => '987654321',
            'pais_nacimiento' => 'CO',
            'created_at' => now(),
        ]);

        $afiliadoServiceMock = Mockery::mock(AfiliadoService::class);
        $afiliadoServiceMock
            ->shouldReceive('getAfiliadoByDocumentoOnly')
            ->once()
            ->with($survey->numero_documento)
            ->andReturn([
                'documento' => $survey->numero_documento,
                'tipo_documento' => $survey->tipo_documento,
                'nombres' => $survey->nombres,
                'apellidos' => $survey->apellidos,
                'correo_personal' => $survey->correo,
                'nombre_completo' => "{$survey->nombres} {$survey->apellidos}",
                'estado' => 'INACTIVO',
                'hospital' => 'HLM - GRUPO 1',
            ]);

        $this->app->instance(AfiliadoService::class, $afiliadoServiceMock);

        $this->artisan('surveys:backfill-new-entry-hospital-from-convenio', [
            '--force' => true,
            '--limit' => 10,
        ])
            ->assertExitCode(0);

        $survey->refresh();

        $this->assertSame('E.S.E HOSPITAL LA MARÍA', $survey->hospital);
    }
}
