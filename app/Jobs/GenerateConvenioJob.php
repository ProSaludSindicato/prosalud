<?php

namespace App\Jobs;

use App\Services\ConvenioGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateConvenioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300; // 5 minutos

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $convenioData,
        public ?string $email = null,
        public bool $sendEmail = false
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ConvenioGenerationService $convenioGenerationService): void
    {
        try {
            // Aumentar tiempo de ejecución para el job
            set_time_limit($this->timeout);
            ini_set('max_execution_time', (string) $this->timeout);

            Log::info('[CONVENIO JOB] Iniciando generación de convenio desde job asíncrono', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'send_email' => $this->sendEmail,
                'email' => $this->email,
            ]);

            // Generar convenio Word
            $resultado = $convenioGenerationService->generarConvenio($this->convenioData);

            Log::info('[CONVENIO JOB] Convenio generado exitosamente desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'nombre_archivo' => $resultado['nombre'],
                'ruta' => $resultado['ruta'],
            ]);

            // TODO: Cuando se habilite el envío de correo y conversión a PDF, agregar aquí
            // Por ahora solo se genera el Word y se guarda localmente
            if ($this->sendEmail && $this->email) {
                Log::warning('[CONVENIO JOB] El envío de correo está temporalmente deshabilitado durante la fase de desarrollo y pruebas', [
                    'documento' => $this->convenioData['numero_documento'] ?? null,
                    'email' => $this->email,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[CONVENIO JOB] Error generando convenio desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
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
        Log::error('[CONVENIO JOB] Job de generación de convenio falló después de todos los intentos', [
            'documento' => $this->convenioData['numero_documento'] ?? null,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

