<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use App\Services\AfiliadoService;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioRateLimiter;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SendConvenioManualEmailRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('prosalud-private');
        RateLimiter::clear('convenio-prosanet-lookup');
        RateLimiter::clear('convenio-email-send');
        config([
            'convenios.delivery_mode' => 'production',
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => false,
        ]);
    }

    private function newJob(string $uuid): SendConvenioManualEmailJob
    {
        $pdf = storage_path('app/temp/convenios/tests/retry-'.uniqid('', true).'.pdf');
        if (! is_dir(dirname($pdf))) {
            mkdir(dirname($pdf), 0755, true);
        }
        file_put_contents($pdf, '%PDF-1.4 retry');

        $job = new SendConvenioManualEmailJob(
            documento: '1234567890',
            nombreArchivo: 'BELLO - TEST USER - 1234567890.pdf',
            rutaArchivoPdf: $pdf,
            nombreConvenio: 'BELLO',
            optionalEmail: 'afiliado@example.com',
        );

        $queueJob = Mockery::mock(QueueJob::class)->shouldIgnoreMissing();
        $queueJob->shouldReceive('uuid')->andReturn($uuid);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $job->setJob($queueJob);

        return $job;
    }

    private function runJob(SendConvenioManualEmailJob $job): void
    {
        $afiliadoService = Mockery::mock(AfiliadoService::class);
        $afiliadoService->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn(null);

        $job->handle(
            $afiliadoService,
            app(ConvenioDigitalSigningService::class),
            app(ConvenioPdfStorageService::class),
            app(\App\Services\ConvenioPdfIntegrityService::class),
        );
    }

    public function test_release_on_email_rate_limit_does_not_leave_one_tracking_row_per_attempt(): void
    {
        config(['convenios.emails_per_minute' => 1]);
        ConvenioRateLimiter::hitEmail();

        $this->runJob($this->newJob('uuid-rate-limit'));
        $this->runJob($this->newJob('uuid-rate-limit'));

        $this->assertSame(1, ConvenioEmailTracking::query()->where('documento', '1234567890')->count());

        RateLimiter::clear('convenio-email-send');
        $this->runJob($this->newJob('uuid-rate-limit'));

        $this->assertSame(1, ConvenioEmailTracking::query()->where('documento', '1234567890')->count());
        $this->assertSame('enviado', ConvenioEmailTracking::query()->firstOrFail()->estado);
        Mail::assertSent(ConvenioManualNotification::class, 1);
    }

    public function test_retry_after_the_email_was_sent_does_not_send_it_again(): void
    {
        $this->runJob($this->newJob('uuid-sent'));
        Mail::assertSent(ConvenioManualNotification::class, 1);

        Cache::put('convenio-email-job-tracking:uuid-sent', ConvenioEmailTracking::query()->firstOrFail()->id, now()->addHour());

        $this->runJob($this->newJob('uuid-sent'));

        Mail::assertSent(ConvenioManualNotification::class, 1);
        $this->assertSame(1, ConvenioEmailTracking::query()->count());
    }

    public function test_different_jobs_still_create_their_own_tracking_rows(): void
    {
        $this->runJob($this->newJob('uuid-a'));
        $this->runJob($this->newJob('uuid-b'));

        $this->assertSame(2, ConvenioEmailTracking::query()->count());
        Mail::assertSent(ConvenioManualNotification::class, 2);
    }
}
