<?php

namespace Tests\Feature;

use App\Jobs\GenerateBulkSurveyPdfChunkJob;
use App\Jobs\GenerateBulkSurveyPdfJob;
use App\Models\SocioDemographicSurvey;
use App\Models\User;
use App\Services\SocioDemographicSurveyBulkPdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkSurveyPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        config(['surveys.bulk_pdf.surveys_per_part' => 2]);
    }

    public function test_export_pdf_endpoint_dispatches_orchestrator_job(): void
    {
        $this->withoutMiddleware();
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $response = $this->postJson('/api/socio-demographic-surveys/export/pdf', [
            'year' => 2026,
            'date_range' => ['include_all' => true],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(GenerateBulkSurveyPdfJob::class);
    }

    public function test_orchestrator_dispatches_batch_with_one_job_per_chunk(): void
    {
        Bus::fake();

        $this->createSurvey('1000000001');
        $this->createSurvey('1000000002');
        $this->createSurvey('1000000003');

        $jobId = 'test-job-id';
        Cache::put("survey_report_pdf:{$jobId}", [
            'status' => 'processing',
            'created_at' => now()->toIso8601String(),
        ], now()->addHour());

        $filters = [
            'survey_type' => 'all',
            'date_range' => ['include_all' => true, 'start_date' => null, 'end_date' => null],
            'year' => 2026,
            'month' => null,
            'hospitals' => [],
            'hospital' => null,
            'numero_documento' => null,
            'tipo_documento' => null,
            'nombre' => null,
            'profesion' => null,
        ];

        $job = new GenerateBulkSurveyPdfJob($jobId, $filters, 1);
        $job->handle(app(SocioDemographicSurveyBulkPdfExportService::class));

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 2
                && $batch->jobs->every(fn ($queuedJob) => $queuedJob instanceof GenerateBulkSurveyPdfChunkJob);
        });

        $status = Cache::get("survey_report_pdf:{$jobId}");
        $this->assertSame('processing', $status['status']);
        $this->assertSame(3, $status['count']);
        $this->assertSame(2, $status['total_parts']);
        $this->assertSame(0, $status['completed_parts']);
    }

    public function test_orchestrator_marks_failed_when_no_surveys_match_filters(): void
    {
        Bus::fake();

        $jobId = 'empty-job-id';
        $filters = [
            'survey_type' => 'all',
            'date_range' => ['include_all' => true, 'start_date' => null, 'end_date' => null],
            'year' => 1999,
            'month' => null,
            'hospitals' => [],
            'hospital' => null,
            'numero_documento' => null,
            'tipo_documento' => null,
            'nombre' => null,
            'profesion' => null,
        ];

        $job = new GenerateBulkSurveyPdfJob($jobId, $filters, 1);
        $job->handle(app(SocioDemographicSurveyBulkPdfExportService::class));

        Bus::assertNothingBatched();

        $status = Cache::get("survey_report_pdf:{$jobId}");
        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('No se encontraron encuestas', $status['error']);
    }

    public function test_status_endpoint_returns_progress_fields_while_processing(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $jobId = 'progress-job-id';
        Cache::put("survey_report_pdf:{$jobId}", [
            'status' => 'processing',
            'count' => 1800,
            'completed_parts' => 12,
            'total_parts' => 90,
            'created_at' => now()->toIso8601String(),
        ], now()->addHour());

        $response = $this->getJson("/api/socio-demographic-surveys/export/pdf/status/{$jobId}");

        $response->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('count', 1800)
            ->assertJsonPath('completed_parts', 12)
            ->assertJsonPath('total_parts', 90);
    }

    public function test_status_endpoint_returns_error_when_job_failed(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $jobId = 'failed-job-id';
        Cache::put("survey_report_pdf:{$jobId}", [
            'status' => 'failed',
            'error' => 'Error al generar las partes del PDF: memory exhausted',
            'created_at' => now()->toIso8601String(),
        ], now()->addHour());

        $response = $this->getJson("/api/socio-demographic-surveys/export/pdf/status/{$jobId}");

        $response->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Error al generar las partes del PDF: memory exhausted');
    }

    public function test_chunk_job_dispatches_finalize_when_all_parts_complete(): void
    {
        Queue::fake();

        $exportService = app(SocioDemographicSurveyBulkPdfExportService::class);
        $jobId = 'finalize-job-id';
        $filters = [
            'survey_type' => 'all',
            'date_range' => ['include_all' => true, 'start_date' => null, 'end_date' => null],
            'year' => 2026,
            'month' => null,
            'hospitals' => [],
            'hospital' => null,
            'numero_documento' => null,
            'tipo_documento' => null,
            'nombre' => null,
            'profesion' => null,
        ];

        $exportService->putStatus($jobId, [
            'status' => 'processing',
            'count' => 2,
            'completed_parts' => 1,
            'total_parts' => 1,
            'base_file_name' => 'Encuestas_Sociodemograficas_test',
            'user_id' => 1,
            'finalize_dispatched' => false,
        ]);

        $exportService->maybeDispatchFinalize($jobId, $filters);

        Queue::assertPushed(\App\Jobs\FinalizeBulkSurveyPdfZipJob::class);

        $status = $exportService->getStatus($jobId);
        $this->assertTrue($status['finalize_dispatched']);
    }

    public function test_finalize_is_not_dispatched_twice(): void
    {
        Queue::fake();

        $exportService = app(SocioDemographicSurveyBulkPdfExportService::class);
        $jobId = 'duplicate-finalize-job-id';
        $filters = ['survey_type' => 'all', 'date_range' => ['include_all' => true]];

        $exportService->putStatus($jobId, [
            'status' => 'processing',
            'count' => 2,
            'completed_parts' => 1,
            'total_parts' => 1,
            'base_file_name' => 'Encuestas_test',
            'finalize_dispatched' => false,
        ]);

        $exportService->maybeDispatchFinalize($jobId, $filters);
        $exportService->maybeDispatchFinalize($jobId, $filters);

        Queue::assertPushed(\App\Jobs\FinalizeBulkSurveyPdfZipJob::class, 1);
    }

    public function test_status_endpoint_recovers_stuck_finalize_for_completed_parts(): void
    {
        $this->withoutMiddleware();
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('socio_demographic_surveys.view');
        $this->actingAs($user);

        $jobId = 'stuck-job-id';
        $filters = [
            'survey_type' => 'all',
            'date_range' => ['include_all' => true],
            'year' => 2026,
        ];

        Cache::put("survey_report_pdf:{$jobId}", [
            'status' => 'processing',
            'count' => 13,
            'completed_parts' => 1,
            'total_parts' => 1,
            'base_file_name' => 'Encuestas_test',
            'filters' => $filters,
        ], now()->addHour());

        $this->getJson("/api/socio-demographic-surveys/export/pdf/status/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(\App\Jobs\FinalizeBulkSurveyPdfZipJob::class);
    }

    public function test_recover_stuck_finalize_redispatches_after_timeout(): void
    {
        Queue::fake();

        $exportService = app(SocioDemographicSurveyBulkPdfExportService::class);
        $jobId = 'stuck-finalize-job-id';
        $filters = ['survey_type' => 'all', 'date_range' => ['include_all' => true]];

        $exportService->putStatus($jobId, [
            'status' => 'processing',
            'count' => 13,
            'completed_parts' => 1,
            'total_parts' => 1,
            'base_file_name' => 'Encuestas_test',
            'filters' => $filters,
            'finalize_dispatched' => true,
            'updated_at' => now()->subMinutes(5)->toIso8601String(),
        ]);

        $exportService->recoverStuckFinalize($jobId);

        Queue::assertPushed(\App\Jobs\FinalizeBulkSurveyPdfZipJob::class);
    }

    private function createSurvey(string $id): SocioDemographicSurvey
    {
        return SocioDemographicSurvey::query()->create([
            'id' => $id,
            'survey_type' => 'active_affiliate',
            'correo' => 'test@example.com',
            'tipo_documento' => 'CC',
            'numero_documento' => '1234567890',
            'hospital' => 'HOSP1',
            'profesion' => 'Enfermera',
            'datos_sociodemograficos' => ['estado_civil' => 'soltero'],
            'datos_consumo' => ['consumo_licor' => 'no'],
            'condiciones_salud' => ['hipertension' => 'no'],
            'limitaciones_fisicas' => ['ninguna' => true],
            'recomendacion_restriccion_laboral' => 'no',
            'numero_documento_firma' => '1234567890',
            'created_at' => now()->setYear(2026),
        ]);
    }
}
