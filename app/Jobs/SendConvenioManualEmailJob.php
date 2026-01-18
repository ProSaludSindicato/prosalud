<?php

namespace App\Jobs;

use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use App\Services\AfiliadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendConvenioManualEmailJob implements ShouldQueue
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
        public string $rutaArchivoPdf,
        public string $nombreConvenio,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AfiliadoService $afiliadoService): void
    {
        $trackingId = null;

        try {
            Log::info('Iniciando envío de correo de convenio manual', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
            ]);

            // Get affiliate information by document number
            $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);

            if (null === $afiliado) {
                $errorMessage = 'Afiliado no encontrado para el documento: ' . $this->documento;
                Log::warning($errorMessage, [
                    'documento' => $this->documento,
                    'nombre_archivo' => $this->nombreArchivo,
                ]);

                // Create tracking record with error
                $tracking = $this->createTrackingRecord($afiliado, $errorMessage);
                if ($tracking) {
                    $tracking->marcarComoFallido($errorMessage);
                }
                return;
            }

            // Check if affiliate has email
            $email = $afiliado['correo_personal'] ?? null;
            if (empty($email)) {
                $errorMessage = 'Afiliado sin correo electrónico registrado: ' . $this->documento;
                Log::warning($errorMessage, [
                    'documento' => $this->documento,
                    'nombre_archivo' => $this->nombreArchivo,
                ]);

                // Create tracking record with error
                $tracking = $this->createTrackingRecord($afiliado, $errorMessage);
                if ($tracking) {
                    $tracking->marcarComoFallido($errorMessage);
                }
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

            // Create tracking record before sending
            $tracking = $this->createTrackingRecord($afiliado, null);
            $trackingId = $tracking->id;

            // Verify PDF file exists
            if (!file_exists($this->rutaArchivoPdf)) {
                $errorMessage = 'Archivo PDF no encontrado: ' . $this->rutaArchivoPdf;
                Log::error($errorMessage, [
                    'documento' => $this->documento,
                    'ruta_archivo' => $this->rutaArchivoPdf,
                ]);
                
                $tracking->marcarComoFallido($errorMessage);
                return;
            }

            // Create mailable and attach PDF
            $mailable = new ConvenioManualNotification(
                $nombreCompleto,
                $this->documento,
                $this->nombreConvenio
            );

            $mailable->attachPdfFromPath($this->rutaArchivoPdf);

            // Send email
            Mail::to($email)->send($mailable);

            // Mark as sent successfully
            $tracking->marcarComoEnviado();

            Log::info('Correo de convenio manual enviado exitosamente', [
                'tracking_id' => $trackingId,
                'documento' => $this->documento,
                'email' => $email,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = 'Error al enviar correo: ' . $e->getMessage();
            Log::error('Error al enviar correo de convenio manual', [
                'tracking_id' => $trackingId,
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update tracking with error if exists
            if ($trackingId) {
                $tracking = ConvenioEmailTracking::find($trackingId);
                if ($tracking) {
                    $tracking->marcarComoFallido($errorMessage);
                }
            }

            throw $e; // Re-lanzar para que Laravel lo marque como fallido y pueda reintentar
        }
    }

    /**
     * Create tracking record
     */
    private function createTrackingRecord(?array $afiliado, ?string $errorMessage): ConvenioEmailTracking
    {
        $emailAfiliado = $afiliado['correo_personal'] ?? null;
        $nombreAfiliado = '';
        
        if ($afiliado) {
            $nombreAfiliado = $afiliado['nombre_completo'] ?? '';
            if (empty($nombreAfiliado)) {
                $nombreAfiliado = trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''));
            }
        }

        return ConvenioEmailTracking::create([
            'documento' => $this->documento,
            'nombre_afiliado' => $nombreAfiliado ?: 'No disponible',
            'email_afiliado' => $emailAfiliado ?: 'No disponible',
            'nombre_convenio' => $this->nombreConvenio,
            'nombre_archivo' => $this->nombreArchivo,
            'ruta_archivo_pdf' => $this->rutaArchivoPdf,
            'estado' => 'pendiente',
            'error_message' => $errorMessage,
            'intentos' => 0,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job de envío de correo de convenio manual falló después de todos los intentos', [
            'documento' => $this->documento,
            'nombre_archivo' => $this->nombreArchivo,
            'nombre_convenio' => $this->nombreConvenio,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Try to find and update tracking record
        $tracking = ConvenioEmailTracking::where('documento', $this->documento)
            ->where('nombre_archivo', $this->nombreArchivo)
            ->where('estado', 'pendiente')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($tracking) {
            $tracking->marcarComoFallido('Job falló después de ' . $this->tries . ' intentos: ' . $exception->getMessage());
        }
    }
}

