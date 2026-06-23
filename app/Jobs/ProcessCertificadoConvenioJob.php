<?php

namespace App\Jobs;

use App\Models\RequestForm;
use App\Services\CertificadoConvenioAutomaticoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCertificadoConvenioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(
        public string $requestFormId,
        public ?array $compensaciones = null,
        public bool $resolverCompensaciones = false,
    ) {}

    public function handle(CertificadoConvenioAutomaticoService $certificadoService): void
    {
        set_time_limit($this->timeout);
        ini_set('max_execution_time', (string) $this->timeout);

        try {
            Log::info('Iniciando procesamiento automático de certificado desde Job', [
                'request_id' => $this->requestFormId,
                'resolver_compensaciones' => $this->resolverCompensaciones,
                'tiene_compensaciones' => $this->compensaciones !== null,
            ]);

            $requestForm = RequestForm::find($this->requestFormId);

            if (! $requestForm) {
                Log::warning('RequestForm no encontrado para procesamiento automático', [
                    'request_id' => $this->requestFormId,
                ]);

                return;
            }

            if ($this->resolverCompensaciones) {
                $certificadoService->intentarProcesarAutomaticoConCompensaciones($requestForm);

                Log::info('Procesamiento automático de certificado completado exitosamente desde Job', [
                    'request_id' => $this->requestFormId,
                    'path' => 'resolverCompensaciones',
                ]);

                return;
            }

            if ($this->compensaciones !== null) {
                $certificadoService->procesarConRequestFormExistenteYCompensaciones(
                    $requestForm,
                    $this->compensaciones
                );

                Log::info('Procesamiento automático de certificado completado exitosamente desde Job', [
                    'request_id' => $this->requestFormId,
                    'path' => 'compensaciones',
                ]);

                return;
            }

            $certificadoService->procesarConRequestFormExistente($requestForm);

            Log::info('Procesamiento automático de certificado completado exitosamente desde Job', [
                'request_id' => $this->requestFormId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en procesamiento automático de certificado (job)', [
                'request_id' => $this->requestFormId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job de procesamiento automático de certificado falló después de todos los intentos', [
            'request_id' => $this->requestFormId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
