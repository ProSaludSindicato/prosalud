<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ConvenioGenerationService;
use App\Support\ConvenioDelivery;
use App\Support\ConvenioPreviewStorage;
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

    /**
     * TEST explícito elegido en el panel. Se declara fuera del constructor con valor por defecto
     * para que los jobs encolados antes de este campo sigan deserializando (null = modo global).
     */
    public ?bool $isTest = null;

    /**
     * Identifica esta generación para que el panel abra exactamente el PDF que acaba de crear
     * (y no uno anterior del mismo documento). Fuera del constructor por la misma razón que $isTest.
     */
    public ?string $previewToken = null;

    public function __construct(
        public array $convenioData,
        public ?string $email = null,
        public bool $sendEmail = false,
        public ?int $generatedByUserId = null,
        ?bool $isTest = null,
        ?string $previewToken = null,
    ) {
        $this->isTest = $isTest;
        $this->previewToken = $previewToken;
    }

    public function handle(ConvenioGenerationService $convenioGenerationService): void
    {
        try {
            set_time_limit($this->timeout);
            ini_set('max_execution_time', (string) $this->timeout);

            Log::debug('[CONVENIO JOB] Iniciando generación de convenio desde job asíncrono', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'send_email' => $this->sendEmail,
                'email' => $this->email,
                'delivery_mode' => config('convenios.delivery_mode'),
                'is_test' => ConvenioDelivery::resolveIsTest($this->isTest),
            ]);

            $resultadoWord = $convenioGenerationService->generarConvenio($this->convenioData);
            $resultado = $convenioGenerationService->finalizeConvenioPdf($resultadoWord['ruta']);

            Log::info('[CONVENIO JOB] Convenio PDF generado exitosamente desde job', [
                'documento' => $this->convenioData['numero_documento'] ?? null,
                'nombre_archivo' => $resultado['nombre'],
                'ruta' => $resultado['ruta'],
            ]);

            $this->storePreviewCopy($resultado['ruta'], $resultado['nombre']);

            if ($this->sendEmail) {
                $documento = preg_replace('/[^0-9]/', '', (string) ($this->convenioData['numero_documento'] ?? ''));
                $nombreConvenio = (string) ($this->convenioData['proceso'] ?? 'CONVENIO');
                $sede = isset($this->convenioData['sede']) ? (string) $this->convenioData['sede'] : null;
                $emailForJob = $this->email;
                $isTest = ConvenioDelivery::resolveIsTest($this->isTest);

                if ($isTest) {
                    $creatorEmail = $this->resolveCreatorEmail();

                    if ($creatorEmail === null) {
                        Log::warning('[CONVENIO JOB] Convenio TEST: no se pudo determinar el correo del usuario creador; no se envía al afiliado', [
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
                    isTest: $isTest,
                );

                Log::info('[CONVENIO JOB] PDF generado y correo encolado', [
                    'documento' => $documento,
                    'email' => $emailForJob,
                    'delivery_mode' => ConvenioDelivery::mode(),
                    'is_test' => $isTest,
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

    /**
     * Copia el PDF recién generado a una ruta única por token y usuario, para la vista previa del panel.
     */
    private function storePreviewCopy(string $pdfPath, string $pdfName): void
    {
        if ($this->previewToken === null || $this->generatedByUserId === null || ! is_file($pdfPath)) {
            return;
        }

        $previewPath = ConvenioPreviewStorage::path($this->generatedByUserId, $this->previewToken);
        if (! is_dir(dirname($previewPath))) {
            mkdir(dirname($previewPath), 0755, true);
        }

        if (! copy($pdfPath, $previewPath)) {
            Log::warning('[CONVENIO JOB] No se pudo guardar la copia de vista previa', ['preview_token' => $this->previewToken]);

            return;
        }

        file_put_contents($previewPath.'.name', $pdfName);
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
