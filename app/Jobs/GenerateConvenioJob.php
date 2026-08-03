<?php

namespace App\Jobs;

use App\Models\ConvenioEmailTracking;
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

                if (ConvenioDelivery::isTestMode()) {
                    $tracking = $this->createVerificationTracking(
                        $documento,
                        $resultado['nombre'],
                        $resultado['ruta'],
                        $nombreConvenio,
                        $sede,
                    );

                    $creatorEmail = $this->resolveCreatorEmail();

                    if ($creatorEmail !== null) {
                        SendConvenioManualEmailJob::dispatch(
                            $documento,
                            $resultado['nombre'],
                            $resultado['ruta'],
                            $nombreConvenio,
                            $tracking->id,
                            $creatorEmail,
                            $sede,
                            $this->convenioData,
                        );

                        Log::info('[CONVENIO JOB] Modo test: PDF disponible y correo encolado al usuario creador', [
                            'documento' => $documento,
                            'generated_by_user_id' => $this->generatedByUserId,
                            'creator_email' => $creatorEmail,
                            'tracking_id' => $tracking->id,
                        ]);
                    } else {
                        Log::warning('[CONVENIO JOB] Modo test: PDF disponible pero no se pudo determinar el correo del usuario creador', [
                            'documento' => $documento,
                            'generated_by_user_id' => $this->generatedByUserId,
                        ]);
                    }

                    return;
                }

                if (empty($this->email)) {
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
                    $this->email,
                    $sede,
                    $this->convenioData,
                );

                Log::info('[CONVENIO JOB] PDF generado y correo encolado', [
                    'documento' => $documento,
                    'email' => $this->email,
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

        $email = User::query()->whereKey($this->generatedByUserId)->value('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }

    private function createVerificationTracking(
        string $documento,
        string $nombreArchivo,
        string $rutaPdf,
        string $nombreConvenio,
        ?string $sede,
    ): ConvenioEmailTracking {
        $apellidos = trim((string) ($this->convenioData['apellidos'] ?? ''));
        $nombres = trim((string) ($this->convenioData['nombres'] ?? ''));
        $nombreAfiliado = trim($apellidos.' '.$nombres);

        return ConvenioEmailTracking::create([
            'documento' => $documento,
            'nombre_afiliado' => $nombreAfiliado !== '' ? $nombreAfiliado : 'No disponible',
            'email_afiliado' => $this->email ?? 'No disponible',
            'nombre_convenio' => $nombreConvenio,
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo_pdf' => $rutaPdf,
            'estado' => ConvenioEmailTracking::ESTADO_VERIFICACION,
            'intentos' => 0,
            'sede' => $sede,
            'generated_by_user_id' => $this->generatedByUserId,
            'convenio_data' => $this->convenioData,
        ]);
    }
}
