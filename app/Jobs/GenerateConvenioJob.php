<?php

namespace App\Jobs;

use App\Services\ConvenioGenerationService;
use App\Services\DocxToPdfService;
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
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $convenioData,
        public ?string $email = null,
        public bool $sendEmail = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ConvenioGenerationService $convenioGenerationService,
        DocxToPdfService $docxToPdfService,
    ): void {
        try {
            set_time_limit($this->timeout);
            ini_set('max_execution_time', (string) $this->timeout);

            Log::info('[CONVENIO JOB] Iniciando generación de convenio desde job asíncrono', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'send_email' => $this->sendEmail,
                'email' => $this->email,
            ]);

            $resultado = $convenioGenerationService->generarConvenio($this->convenioData);

            Log::info('[CONVENIO JOB] Convenio generado exitosamente desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'nombre_archivo' => $resultado['nombre'],
                'ruta' => $resultado['ruta'],
            ]);

            if ($this->sendEmail && $this->email) {
                try {
                    $pdfResult = $docxToPdfService->convert($resultado['ruta'], true);
                    $pdfAbsolute = storage_path('app/'.$pdfResult['path']);
                    $documento = preg_replace('/[^0-9]/', '', (string) ($this->convenioData['numero_documento'] ?? ''));
                    $nombrePdf = preg_replace('/\.docx$/i', '.pdf', $resultado['nombre']);
                    $nombreConvenio = (string) ($this->convenioData['proceso'] ?? 'CONVENIO');

                    SendConvenioManualEmailJob::dispatch(
                        $documento,
                        $nombrePdf,
                        $pdfAbsolute,
                        $nombreConvenio,
                        null,
                        $this->email,
                        isset($this->convenioData['sede']) ? (string) $this->convenioData['sede'] : null,
                    );

                    if (file_exists($resultado['ruta'])) {
                        @unlink($resultado['ruta']);
                    }

                    Log::info('[CONVENIO JOB] PDF generado y correo encolado', [
                        'documento' => $documento,
                        'email' => $this->email,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[CONVENIO JOB] Error al convertir a PDF o encolar envío de correo', [
                        'documento' => $this->convenioData['numero_documento'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            Log::error('[CONVENIO JOB] Error generando convenio desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
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
