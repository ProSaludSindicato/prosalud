<?php

namespace App\Jobs;

// ============================================================================
// CÓDIGO TEMPORAL PARA PRUEBAS - ELIMINAR DESPUÉS DE VERIFICAR FUNCIONAMIENTO
// ============================================================================
// Este job es solo para probar que las colas funcionen correctamente.
// Los correos se envían a "juanpapabon@gmail.com" (hardcodeado)
// ============================================================================

use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TestSendConvenioManualEmailJob implements ShouldQueue
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
     * Email hardcoded for testing
     */
    private const TEST_EMAIL = 'juanpapabon@gmail.com';

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
    public function handle(): void
    {
        $trackingId = null;

        try {
            Log::info('Iniciando envío de correo de PRUEBA de convenio manual', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
                'test_email' => self::TEST_EMAIL,
            ]);

            // Create tracking record with test data
            $tracking = $this->createTrackingRecord();
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
                'Afiliado de Prueba - ' . $this->documento, // Nombre de prueba
                $this->documento,
                $this->nombreConvenio
            );

            $mailable->attachPdfFromPath($this->rutaArchivoPdf);

            // Send email to hardcoded test email
            Mail::to(self::TEST_EMAIL)->send($mailable);

            // Mark as sent successfully
            $tracking->marcarComoEnviado();

            Log::info('Correo de PRUEBA de convenio manual enviado exitosamente', [
                'tracking_id' => $trackingId,
                'documento' => $this->documento,
                'email' => self::TEST_EMAIL,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = 'Error al enviar correo de prueba: ' . $e->getMessage();
            Log::error('Error al enviar correo de PRUEBA de convenio manual', [
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
     * Create tracking record with test data
     */
    private function createTrackingRecord(): ConvenioEmailTracking
    {
        return ConvenioEmailTracking::create([
            'documento' => $this->documento,
            'nombre_afiliado' => 'Afiliado de Prueba - ' . $this->documento,
            'email_afiliado' => self::TEST_EMAIL,
            'nombre_convenio' => $this->nombreConvenio,
            'nombre_archivo' => $this->nombreArchivo,
            'ruta_archivo_pdf' => $this->rutaArchivoPdf,
            'estado' => 'pendiente',
            'error_message' => null,
            'intentos' => 0,
            'parent_tracking_id' => null,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job de PRUEBA de envío de correo de convenio manual falló después de todos los intentos', [
            'documento' => $this->documento,
            'nombre_archivo' => $this->nombreArchivo,
            'nombre_convenio' => $this->nombreConvenio,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Try to find and update tracking record
        $tracking = ConvenioEmailTracking::where('documento', $this->documento)
            ->where('nombre_archivo', $this->nombreArchivo)
            ->where('email_afiliado', self::TEST_EMAIL)
            ->where('estado', 'pendiente')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($tracking) {
            $tracking->marcarComoFallido('Job de PRUEBA falló después de ' . $this->tries . ' intentos: ' . $exception->getMessage());
        }
    }
}
