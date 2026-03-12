<?php

namespace Tests\Feature;

use App\Jobs\BackfillNewEntrySurveyHospitalFromConvenioJob;
use App\Models\SocioDemographicSurvey;
use App\Services\AfiliadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BackfillNewEntrySurveyHospitalFromConvenioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_job_with_options(): void
    {
        SocioDemographicSurvey::create([
            'id' => '1000000001',
            'survey_type' => 'new_entry',
            'correo' => 'test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '123456789',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'hospital' => 'E.S.E. HOSPITAL LA MARÍA',
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

        Queue::fake();

        $this->artisan('surveys:backfill-new-entry-hospital-from-convenio', [
            '--force' => true,
            '--limit' => 50,
            '--dry' => true,
        ])
            ->assertExitCode(0);

        Queue::assertPushed(BackfillNewEntrySurveyHospitalFromConvenioJob::class, function (BackfillNewEntrySurveyHospitalFromConvenioJob $job) {
            return $job->limit === 50 && $job->dry === true;
        });
    }

    public function test_job_updates_hospital_when_afiliado_is_active_and_has_convenio(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000001',
            'survey_type' => 'new_entry',
            'correo' => 'test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '123456789',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'hospital' => 'E.S.E. HOSPITAL LA MARÍA',
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

        $job = new BackfillNewEntrySurveyHospitalFromConvenioJob(limit: 10, dry: false);
        $job->handle(app(AfiliadoService::class));

        $survey->refresh();

        $this->assertSame('HLM - GRUPO 1', $survey->hospital);
    }

    public function test_job_updates_hospital_when_survey_hospital_is_empty(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000004',
            'survey_type' => 'new_entry',
            'correo' => 'empty@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '555666777',
            'nombres' => 'Luis',
            'apellidos' => 'Martín',
            'hospital' => '',
            'profesion' => 'AUXILIAR',
            'datos_sociodemograficos' => [],
            'datos_consumo' => [],
            'condiciones_salud' => [],
            'limitaciones_fisicas' => [],
            'recomendacion_restriccion_laboral' => '',
            'numero_documento_firma' => '555666777',
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
                'hospital' => 'E.S.ECARISMA',
            ]);

        $this->app->instance(AfiliadoService::class, $afiliadoServiceMock);

        $job = new BackfillNewEntrySurveyHospitalFromConvenioJob(limit: 10, dry: false);
        $job->handle(app(AfiliadoService::class));

        $survey->refresh();

        $this->assertSame('E.S.ECARISMA', $survey->hospital);
    }

    public function test_job_skips_surveys_when_afiliado_is_not_active(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000002',
            'survey_type' => 'new_entry',
            'correo' => 'inactive@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '987654321',
            'nombres' => 'Ana',
            'apellidos' => 'López',
            'hospital' => 'E.S.E. HOSPITAL LA MARÍA',
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

        $job = new BackfillNewEntrySurveyHospitalFromConvenioJob(limit: 10, dry: false);
        $job->handle(app(AfiliadoService::class));

        $survey->refresh();

        $this->assertSame('E.S.E. HOSPITAL LA MARÍA', $survey->hospital);
    }

    public function test_job_skips_surveys_when_hospital_is_not_in_allowed_legacy_list(): void
    {
        $survey = SocioDemographicSurvey::create([
            'id' => '1000000003',
            'survey_type' => 'new_entry',
            'correo' => 'other@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '111222333',
            'nombres' => 'Pedro',
            'apellidos' => 'García',
            'hospital' => 'HLM - GRUPO 1',
            'profesion' => 'AUXILIAR',
            'datos_sociodemograficos' => [],
            'datos_consumo' => [],
            'condiciones_salud' => [],
            'limitaciones_fisicas' => [],
            'recomendacion_restriccion_laboral' => '',
            'numero_documento_firma' => '111222333',
            'pais_nacimiento' => 'CO',
            'created_at' => now(),
        ]);

        $afiliadoServiceMock = Mockery::mock(AfiliadoService::class);
        $afiliadoServiceMock->shouldReceive('getAfiliadoByDocumentoOnly')->never();

        $this->app->instance(AfiliadoService::class, $afiliadoServiceMock);

        $job = new BackfillNewEntrySurveyHospitalFromConvenioJob(limit: 10, dry: false);
        $job->handle(app(AfiliadoService::class));

        $survey->refresh();

        $this->assertSame('HLM - GRUPO 1', $survey->hospital);
    }
}
