<?php

namespace Tests\Feature;

use App\Enums\ExcelReport;
use App\Jobs\GenerateExcelReportJob;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AsyncExcelExportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('prosalud-private');

        $this->user = User::factory()->create();
        foreach (['vaccination_surveys.view', 'socio_demographic_surveys.view', 'wellness_delivery.view', 'wellness_requests.view', 'wellness_requests.export_all', 'dotacion.view'] as $permission) {
            Permission::findOrCreate($permission);
            $this->user->givePermissionTo($permission);
        }

        $this->plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $this->user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $this->plainToken),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function authenticatedPost(string $uri, array $body): TestResponse
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('prosalud_auth_token', $this->plainToken)
            ->postJson($uri, $body);
    }

    private function authenticatedGet(string $uri): TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->plainToken)
            ->get($uri, ['Accept' => 'application/json']);
    }

    private function assertReportQueued(TestResponse $response, ExcelReport $report): void
    {
        $response->assertAccepted()->assertJsonPath('success', true);
        $jobId = $response->json('job_id');

        Queue::assertPushed(GenerateExcelReportJob::class, fn (GenerateExcelReportJob $job) => $job->report === $report
            && $job->jobId === $jobId
            && $job->userId === $this->user->id);
        $this->assertSame('processing', cache()->get($report->cacheKey($jobId))['status']);
    }

    public function test_vaccination_export_is_queued(): void
    {
        Queue::fake();

        $response = $this->authenticatedPost('/api/encuesta-vacunacion/export/excel', ['date_range' => ['include_all' => true]]);

        $this->assertReportQueued($response, ExcelReport::VaccinationSurvey);
    }

    public function test_socio_demographic_export_with_signatures_is_queued(): void
    {
        Queue::fake();

        $response = $this->authenticatedPost('/api/socio-demographic-surveys/export/excel', [
            'date_range' => ['include_all' => true],
            'include_signatures' => true,
        ]);

        $this->assertReportQueued($response, ExcelReport::SocioDemographicSurvey);
    }

    public function test_wellness_delivery_export_with_signatures_is_queued(): void
    {
        Queue::fake();

        $response = $this->authenticatedPost('/api/wellness-delivery-requests/export', ['include_firmas' => true]);

        $this->assertReportQueued($response, ExcelReport::WellnessDelivery);
    }

    public function test_wellness_requests_export_with_images_is_queued(): void
    {
        Queue::fake();

        $response = $this->authenticatedPost('/api/wellness-requests/export/excel', ['include_images' => true]);

        $this->assertReportQueued($response, ExcelReport::WellnessRequest);
        $response->assertJsonPath('check_status_url', url('/api/wellness-requests/export/status/'.$response->json('job_id')));
    }

    public function test_wellness_requests_export_without_images_still_downloads_directly(): void
    {
        Queue::fake();

        $response = $this->authenticatedPost('/api/wellness-requests/export/excel', ['include_images' => false]);

        $response->assertOk();
        $this->assertStringContainsString('Reporte_Bienestar_ProSalud_', $response->headers->get('content-disposition'));
        Queue::assertNothingPushed();
    }

    public function test_wellness_requests_export_status_and_download(): void
    {
        Storage::disk('prosalud-private')->put('reports/wellness-requests/11111111-1111-4111-8111-111111111111/reporte.xlsx', 'xlsx-content');
        cache()->put(ExcelReport::WellnessRequest->cacheKey('11111111-1111-4111-8111-111111111111'), [
            'status' => 'completed',
            'disk' => 'prosalud-private',
            'file_path' => 'reports/wellness-requests/11111111-1111-4111-8111-111111111111/reporte.xlsx',
            'file_name' => 'reporte.xlsx',
        ]);

        $this->authenticatedGet('/api/wellness-requests/export/status/11111111-1111-4111-8111-111111111111')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('download_url', url('/api/wellness-requests/export/download/11111111-1111-4111-8111-111111111111'));

        $download = $this->authenticatedGet('/api/wellness-requests/export/download/11111111-1111-4111-8111-111111111111');
        $download->assertOk();
        $this->assertSame('xlsx-content', $download->streamedContent());
    }

    public function test_wellness_requests_export_status_reports_missing_and_pending_jobs(): void
    {
        cache()->put(ExcelReport::WellnessRequest->cacheKey('22222222-2222-4222-8222-222222222222'), ['status' => 'processing']);

        $this->authenticatedGet('/api/wellness-requests/export/status/unknown')->assertNotFound();
        $this->authenticatedGet('/api/wellness-requests/export/download/unknown')->assertNotFound();
        $this->authenticatedGet('/api/wellness-requests/export/download/22222222-2222-4222-8222-222222222222')->assertStatus(400);
    }

    public function test_status_and_download_work_when_cache_is_not_shared_with_the_worker(): void
    {
        $jobId = '44444444-4444-4444-8444-444444444444';
        GenerateExcelReportJob::putStatus(ExcelReport::SstDelivery, $jobId, ['status' => 'processing']);
        Storage::disk('prosalud-private')->put("reports/sst-delivery/{$jobId}/reporte.xlsx", 'sst-content');
        Storage::disk('prosalud-private')->put("reports/sst-delivery/{$jobId}/status.json", json_encode([
            'status' => 'completed',
            'disk' => 'prosalud-private',
            'file_path' => "reports/sst-delivery/{$jobId}/reporte.xlsx",
            'file_name' => 'reporte.xlsx',
        ]));

        $this->authenticatedGet("/api/dotacion-epp/reports/deliveries/status/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        cache()->flush();

        $download = $this->authenticatedGet("/api/dotacion-epp/reports/deliveries/download/{$jobId}");
        $download->assertOk();
        $this->assertSame('sst-content', $download->streamedContent());
    }

    public function test_download_reads_report_from_disk_saved_in_status(): void
    {
        Storage::disk('prosalud-private')->put('reports/sst-delivery/33333333-3333-4333-8333-333333333333/reporte.xlsx', 'sst-content');
        cache()->put(ExcelReport::SstDelivery->cacheKey('33333333-3333-4333-8333-333333333333'), [
            'status' => 'completed',
            'disk' => 'prosalud-private',
            'file_path' => 'reports/sst-delivery/33333333-3333-4333-8333-333333333333/reporte.xlsx',
            'file_name' => 'reporte.xlsx',
        ]);

        $download = $this->authenticatedGet('/api/dotacion-epp/reports/deliveries/download/33333333-3333-4333-8333-333333333333');

        $download->assertOk();
        $this->assertSame('sst-content', $download->streamedContent());
    }
}
