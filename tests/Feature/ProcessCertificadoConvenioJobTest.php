<?php

namespace Tests\Feature;

use App\Jobs\ProcessCertificadoConvenioJob;
use App\Models\RequestForm;
use App\Services\CertificadoConvenioAutomaticoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessCertificadoConvenioJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_extends_execution_time_and_processes_simple_certificate(): void
    {
        $requestForm = RequestForm::factory()->create();

        $service = Mockery::mock(CertificadoConvenioAutomaticoService::class);
        $service->shouldReceive('procesarConRequestFormExistente')
            ->once()
            ->with(Mockery::on(fn (RequestForm $form) => $form->id === $requestForm->id));

        $job = new ProcessCertificadoConvenioJob($requestForm->id);
        $job->handle($service);

        $this->assertSame(300, $job->timeout);
    }

    public function test_job_resolves_compensaciones_in_background(): void
    {
        $requestForm = RequestForm::factory()->create();

        $service = Mockery::mock(CertificadoConvenioAutomaticoService::class);
        $service->shouldReceive('intentarProcesarAutomaticoConCompensaciones')
            ->once()
            ->with(Mockery::on(fn (RequestForm $form) => $form->id === $requestForm->id));

        $job = new ProcessCertificadoConvenioJob($requestForm->id, resolverCompensaciones: true);
        $job->handle($service);

        $this->assertTrue($job->resolverCompensaciones);
    }
}
