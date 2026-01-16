<?php

namespace App\Jobs;

use App\Mail\ConvenioFirmaNotification;
use App\Services\AfiliadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendConvenioFirmaEmailJob implements ShouldQueue
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
        public string $documento,
        public string $nombreArchivo,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AfiliadoService $afiliadoService): void
    {
        try {
            Log::info('Iniciando envío de correo de convenio para firmar', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
            ]);

            // Get affiliate information by document number
            $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);

            if (null === $afiliado) {
                Log::warning('Afiliado no encontrado para envío de correo de convenio', [
                    'documento' => $this->documento,
                    'nombre_archivo' => $this->nombreArchivo,
                ]);
                return;
            }

            // Check if affiliate has email
            $email = $afiliado['correo_personal'] ?? null;
            if (empty($email)) {
                Log::warning('Afiliado sin correo electrónico para envío de convenio', [
                    'documento' => $this->documento,
                    'nombre_archivo' => $this->nombreArchivo,
                ]);
                return;
            }

            // Get full name
            $nombreCompleto = $afiliado['nombre_completo'] ?? '';
            if (empty($nombreCompleto)) {
                $nombreCompleto = trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''));
            }

            if (empty($nombreCompleto)) {
                $nombreCompleto = 'Estimado afiliado';
            }

            // Send email
            Mail::to($email)->send(new ConvenioFirmaNotification(
                $nombreCompleto,
                $this->nombreArchivo
            ));

            Log::info('Correo de convenio enviado exitosamente', [
                'documento' => $this->documento,
                'email' => $email,
                'nombre_archivo' => $this->nombreArchivo,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al enviar correo de convenio', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
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
        Log::error('Job de envío de correo de convenio falló después de todos los intentos', [
            'documento' => $this->documento,
            'nombre_archivo' => $this->nombreArchivo,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

