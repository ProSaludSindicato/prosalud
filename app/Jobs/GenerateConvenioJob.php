<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ConvenioGenerationService;
use App\Support\ConvenioDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateConvenioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = 60;

    public $timeout = 300;

    public function __construct(
        public array $convenioData,
        public ?string $email = null,
        public bool $sendEmail = false,
        public ?int $generatedByUserId = null,
    ) {}

    public function handle(ConvenioGenerationService $convenioGenerationService): void
    {
        try {
            set_time_limit($this->timeout);
            ini_set('max_execution_time', (string) $this->timeout);

            Log::info('[CONVENIO JOB] Iniciando generación de convenio desde job asíncrono', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'send_email' => $this->sendEmail,
                'email' => $this->email,
                'delivery_mode' => config('convenios.delivery_mode'),
            ]);

            $resultadoWord = $convenioGenerationService->generarConvenio($this->convenioData);
            $resultado = $convenioGenerationService->finalizeConvenioPdf($resultadoWord['ruta']);

            Log::info('[CONVENIO JOB] Convenio PDF generado exitosamente desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'nombre_archivo' => $resultado['nombre'],
                'ruta' => $resultado['ruta'],
            ]);

            if ($this->sendEmail) {
                $documento = preg_replace('/[^0-9]/', '', (string) ($this->convenioData['numero_documento'] ?? ''));
                $nombreConvenio = (string) ($this->convenioData['proceso'] ?? 'CONVENIO');
                $sede = isset($this->convenioData['sede']) ? (string) $this->convenioData['sede'] : null;
                $emailForJob = $this->email;

                if (ConvenioDelivery::isTestMode()) {
                    $creatorEmail = $this->resolveCreatorEmail();

                    if ($creatorEmail === null) {
                        Log::warning('[CONVENIO JOB] Modo test: no se pudo determinar el correo del usuario creador; no se envía al afiliado', [
                            'documento' => $documento,
                            'generated_by_user_id' => $this->generatedByUserId,
                        ]);

                        return;
                    }

                    $emailForJob = $creatorEmail;
                }

                if (empty($emailForJob)) {
                    Log::warning('[CONVENIO JOB] send_email activado pero no se proporcionó correo del afiliado', [
                        'documento' => $documento,
                    ]);

                    return;
                }

                SendConvenioManualEmailJob::dispatch(
                    $documento,
                    $resultado['nombre'],
                    $resultado['ruta'],
                    $nombreConvenio,
                    null,
                    $emailForJob,
                    $sede,
                    $this->convenioData,
                    $this->generatedByUserId,
                );

                Log::info('[CONVENIO JOB] PDF generado y correo encolado', [
                    'documento' => $documento,
                    'email' => $emailForJob,
                    'delivery_mode' => ConvenioDelivery::mode(),
                    'generated_by_user_id' => $this->generatedByUserId,
                ]);
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

    public function failed(\Throwable $exception): void
    {
        Log::error('[CONVENIO JOB] Job de generación de convenio falló después de todos los intentos', [
            'documento' => $this->convenioData['numero_documento'] ?? null,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function resolveCreatorEmail(): ?string
    {
        if ($this->generatedByUserId === null) {
            return null;
        }

        $email = User::query()->where('id', $this->generatedByUserId)->value('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }
}
