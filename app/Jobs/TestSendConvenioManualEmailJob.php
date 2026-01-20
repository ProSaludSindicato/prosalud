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
        try {
            Log::info('Iniciando envío de correo de PRUEBA de convenio manual (SIN trazabilidad)', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
                'test_email' => self::TEST_EMAIL,
            ]);

            // Verify PDF file exists
            if (!file_exists($this->rutaArchivoPdf)) {
                $errorMessage = 'Archivo PDF no encontrado: ' . $this->rutaArchivoPdf;
                Log::error($errorMessage, [
                    'documento' => $this->documento,
                    'ruta_archivo' => $this->rutaArchivoPdf,
                ]);
                return;
            }

            // Create mailable and attach PDF
            $mailable = new ConvenioManualNotification(
                'Afiliado de Prueba - ' . $this->documento, // Nombre de prueba
                $this->documento,
                $this->nombreConvenio
            );

            $mailable->attachPdfFromPath($this->rutaArchivoPdf);

            // Send email to hardcoded test email (NO SE CREA REGISTRO DE TRAZABILIDAD)
            Mail::to(self::TEST_EMAIL)->send($mailable);

            Log::info('Correo de PRUEBA de convenio manual enviado exitosamente (SIN trazabilidad)', [
                'documento' => $this->documento,
                'email' => self::TEST_EMAIL,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = 'Error al enviar correo de prueba: ' . $e->getMessage();
            Log::error('Error al enviar correo de PRUEBA de convenio manual', [
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
        Log::error('Job de PRUEBA de envío de correo de convenio manual falló después de todos los intentos', [
            'documento' => $this->documento,
            'nombre_archivo' => $this->nombreArchivo,
            'nombre_convenio' => $this->nombreConvenio,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        
        // NO SE ACTUALIZA TRAZABILIDAD porque no se crean registros en modo de prueba
    }
}
