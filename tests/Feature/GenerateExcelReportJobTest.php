<?php

namespace Tests\Feature;

use App\Enums\ExcelReport;
use App\Jobs\GenerateExcelReportJob;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerateExcelReportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config(['filesystems.excel_reports_disk' => 'prosalud-private']);
    }

    public function test_start_marks_report_as_processing_and_queues_job(): void
    {
        Queue::fake();

        $jobId = GenerateExcelReportJob::start(ExcelReport::VaccinationSurvey, ['date_range' => ['include_all' => true]], [], 7);

        $this->assertSame('processing', cache()->get(ExcelReport::VaccinationSurvey->cacheKey($jobId))['status']);
        Queue::assertPushed(GenerateExcelReportJob::class, fn (GenerateExcelReportJob $job) => $job->report === ExcelReport::VaccinationSurvey
            && $job->jobId === $jobId
            && $job->userId === 7);
    }

    public function test_handle_stores_report_and_status_on_shared_disk(): void
    {
        $jobId = '11111111-1111-4111-8111-111111111111';
        $job = new GenerateExcelReportJob(ExcelReport::VaccinationSurvey, $jobId, ['date_range' => ['include_all' => true]]);
        $job->handle(app(AuditLogService::class));

        cache()->flush();
        $status = GenerateExcelReportJob::status(ExcelReport::VaccinationSurvey, $jobId);

        $this->assertSame('completed', $status['status']);
        $this->assertSame('prosalud-private', $status['disk']);
        $this->assertStringStartsWith("reports/vaccination-surveys/{$jobId}/Reporte_Encuesta_Vacunacion_ProSalud_", $status['file_path']);
        Storage::disk('prosalud-private')->assertExists($status['file_path']);
        Storage::disk('prosalud-private')->assertExists("reports/vaccination-surveys/{$jobId}/status.json");
    }

    public function test_status_falls_back_to_cache_and_rejects_invalid_job_ids(): void
    {
        $jobId = '22222222-2222-4222-8222-222222222222';
        cache()->put(ExcelReport::SstDelivery->cacheKey($jobId), ['status' => 'processing']);
        cache()->put(ExcelReport::SstDelivery->cacheKey('../otro'), ['status' => 'completed']);

        $this->assertSame('processing', GenerateExcelReportJob::status(ExcelReport::SstDelivery, $jobId)['status']);
        $this->assertNull(GenerateExcelReportJob::status(ExcelReport::SstDelivery, '../otro'));
        $this->assertNull(GenerateExcelReportJob::status(ExcelReport::SstDelivery, '33333333-3333-4333-8333-333333333333'));
    }

    public function test_audited_reports_are_logged_after_completion(): void
    {
        $this->mock(AuditLogService::class, function (MockInterface $mock) {
            $mock->shouldReceive('logBusinessProcess')
                ->once()
                ->withArgs(fn (string $process, string $action, array $context) => $process === 'wellness_request'
                    && $action === 'excel_export'
                    && $context['job_id'] === '44444444-4444-4444-8444-444444444444'
                    && $context['user_id'] === 3);
        });

        $jobId = '44444444-4444-4444-8444-444444444444';
        $job = new GenerateExcelReportJob(ExcelReport::WellnessRequest, $jobId, [], ['include_images' => true], 3);
        $job->handle(app(AuditLogService::class));

        $this->assertSame('completed', GenerateExcelReportJob::status(ExcelReport::WellnessRequest, $jobId)['status']);
    }

    public function test_failed_marks_report_as_failed(): void
    {
        $jobId = '55555555-5555-4555-8555-555555555555';
        $job = new GenerateExcelReportJob(ExcelReport::SocioDemographicSurvey, $jobId, []);
        $job->failed(new \RuntimeException('Timeout'));

        cache()->flush();
        $status = GenerateExcelReportJob::status(ExcelReport::SocioDemographicSurvey, $jobId);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Timeout', $status['error']);
    }

    public function test_each_report_keeps_its_own_cache_key_and_directory(): void
    {
        $this->assertSame('sst_report:abc', ExcelReport::SstDelivery->cacheKey('abc'));
        $this->assertSame('survey_report:abc', ExcelReport::SocioDemographicSurvey->cacheKey('abc'));
        $this->assertSame('vaccination_survey_report:abc', ExcelReport::VaccinationSurvey->cacheKey('abc'));
        $this->assertSame('wellness_delivery_report:abc', ExcelReport::WellnessDelivery->cacheKey('abc'));
        $this->assertSame('wellness_request_report:abc', ExcelReport::WellnessRequest->cacheKey('abc'));
        $this->assertSame('reports/wellness-requests/abc', ExcelReport::WellnessRequest->storageDirectory('abc'));
    }
}
