<?php

namespace App\Jobs;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPresidentSigningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SignConvenioWithPresidentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Máximo de intentos (se lee de config en el constructor) */
    public int $tries;

    public function __construct(
        private readonly int $trackingId,
    ) {
        $this->tries = (int) config('convenio_auto_sign.max_retries', 3);
        $this->onQueue('convenio-president-signing');
    }

    /**
     * Clave única para evitar que el mismo convenio se firme dos veces en paralelo.
     */
    public function uniqueId(): string
    {
        return 'signing-president-'.$this->trackingId;
    }

    /**
     * Backoff entre reintentos (en segundos).
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return config('convenio_auto_sign.retry_backoff_seconds', [30, 120, 600]);
    }

    /**
     * Ejecuta el job: llama al servicio de firma presidencial.
     */
    public function handle(ConvenioPresidentSigningService $signingService): void
    {
        $tracking = ConvenioEmailTracking::find($this->trackingId);

        if ($tracking === null) {
            Log::warning('[PRESIDENT SIGN JOB] Tracking no encontrado, descartando job', [
                'tracking_id' => $this->trackingId,
            ]);

            return;
        }

        $signingService->signTracking($tracking);
    }

    /**
     * Callback cuando el job falla definitivamente (agotó reintentos).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[PRESIDENT SIGN JOB] Job fallido definitivamente', [
            'tracking_id' => $this->trackingId,
            'error' => $exception->getMessage(),
        ]);

        $tracking = ConvenioEmailTracking::find($this->trackingId);

        if ($tracking === null) {
            return;
        }

        // Solo actualizar si no fue ya marcado con error por el servicio
        if ($tracking->signing_estado === ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE) {
            $tracking->update([
                'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
                'president_sign_last_error' => 'Job fallido definitivamente: '.$exception->getMessage(),
            ]);
        }
    }
}
