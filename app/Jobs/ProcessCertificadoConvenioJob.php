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

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $requestFormId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CertificadoConvenioAutomaticoService $certificadoService): void
    {
        try {
            Log::info('Iniciando procesamiento automático de certificado desde Job', [
                'request_id' => $this->requestFormId,
            ]);

            $requestForm = RequestForm::find($this->requestFormId);
            
            if (!$requestForm) {
                Log::warning('RequestForm no encontrado para procesamiento automático', [
                    'request_id' => $this->requestFormId,
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
            throw $e; // Re-lanzar para que Laravel lo marque como fallido y pueda reintentar
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job de procesamiento automático de certificado falló después de todos los intentos', [
            'request_id' => $this->requestFormId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
