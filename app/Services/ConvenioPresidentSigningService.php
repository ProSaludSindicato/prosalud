<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConvenioPresidentSigningService
{
    public function __construct(
        private readonly ConvenioPdfStorageService $storageService,
    ) {}

    /**
     * Firma el convenio ya firmado por el afiliado con la firma del presidente.
     *
     * Flujo:
     *  1. Verifica feature flag
     *  2. Adquiere lock en DB, valida estado
     *  3. Marca como "firmando_presidente" (transitorio)
     *  4. Lee PDF del afiliado, lo envía al servicio Node
     *  5. Guarda el PDF final en storage
     *  6. Marca como "completado"
     *
     * @throws \RuntimeException si el feature flag está deshabilitado o hay error irrecuperable
     */
    public function signTracking(ConvenioEmailTracking $tracking): void
    {
        if (! config('convenio_auto_sign.enabled')) {
            throw new \RuntimeException('La firma automática del presidente está deshabilitada.');
        }

        $startedAt = microtime(true);
        $errorMessage = null;

        try {
            DB::transaction(function () use ($tracking, $startedAt, &$errorMessage): void {
                /** @var ConvenioEmailTracking $locked */
                $locked = ConvenioEmailTracking::query()
                    ->lockForUpdate()
                    ->findOrFail($tracking->id);

                $allowedStates = [
                    ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
                    ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
                ];

                if (! in_array($locked->signing_estado, $allowedStates, true)) {
                    Log::info('[PRESIDENT SIGN] Tracking ignorado — estado no válido para firmar', [
                        'tracking_id' => $locked->id,
                        'signing_estado' => $locked->signing_estado,
                    ]);

                    return;
                }

                $oldState = $locked->signing_estado;

                $locked->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
                    'president_sign_attempts' => $locked->president_sign_attempts + 1,
                    'president_sign_last_error' => null,
                ]);

                Log::info('[PRESIDENT SIGN] Iniciando firma presidencial', [
                    'tracking_id' => $locked->id,
                    'old_state' => $oldState,
                    'attempt' => $locked->president_sign_attempts,
                ]);

                // ── Leer PDF firmado por el afiliado ──────────────────────
                $afiliateSignedPath = $this->storageService->absolutePathForRelative(
                    $locked->pdf_firmado_afiliado_path,
                );

                if ($afiliateSignedPath === null || ! is_file($afiliateSignedPath)) {
                    $errorMessage = 'PDF firmado por el afiliado no encontrado en storage.';
                    throw new \RuntimeException($errorMessage);
                }

                $pdfBytes = file_get_contents($afiliateSignedPath);

                if ($pdfBytes === false || strlen($pdfBytes) < 5) {
                    $errorMessage = 'No se pudo leer el PDF firmado por el afiliado.';
                    throw new \RuntimeException($errorMessage);
                }

                // ── Llamar al servicio Node de firma presidencial ──────────
                $apiUrl = rtrim((string) config('convenio_auto_sign.api_url'), '/');
                $apiKey = (string) config('convenio_auto_sign.api_key');
                $timeout = (int) config('convenio_auto_sign.timeout_seconds', 60);
                $connectTimeout = (int) config('convenio_auto_sign.connect_timeout_seconds', 10);

                try {
                    $response = Http::withHeaders([
                        'X-Api-Key' => $apiKey,
                        'X-Reference-Id' => (string) $locked->id,
                    ])
                        ->timeout($timeout)
                        ->connectTimeout($connectTimeout)
                        ->attach('pdf', $pdfBytes, 'convenio_firmado_afiliado.pdf')
                        ->post($apiUrl.'/api/auto-sign');
                } catch (\Exception $e) {
                    $errorMessage = 'Error de conexión con el servicio de firma: '.$e->getMessage();
                    throw new \RuntimeException($errorMessage, 0, $e);
                }

                // ── Manejar respuesta ─────────────────────────────────────
                if ($response->status() === 422) {
                    $body = $response->json() ?? [];
                    $code = $body['code'] ?? 'unknown';
                    $errorMessage = 'El servicio de firma rechazó el documento: '.($body['error'] ?? 'error desconocido')." (code: {$code})";
                    throw new \RuntimeException($errorMessage);
                }

                if (! $response->successful()) {
                    $errorMessage = "El servicio de firma respondió con HTTP {$response->status()}.";
                    throw new \RuntimeException($errorMessage);
                }

                $signedPdfBytes = $response->body();

                if (strlen($signedPdfBytes) < 5 || substr($signedPdfBytes, 0, 5) !== '%PDF-') {
                    $errorMessage = 'La respuesta del servicio de firma no es un PDF válido.';
                    throw new \RuntimeException($errorMessage);
                }

                // ── Guardar PDF final en storage ─────────────────────────
                $detectionMethod = $response->header('X-Signature-Detection-Method') ?? null;
                $relativeDir = $this->storageService->relativeDirectory($locked);
                $relativeFinalPath = $relativeDir.'/final.pdf';
                $targetDir = storage_path('app/'.$relativeDir);

                if (! is_dir($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }

                $targetPath = storage_path('app/'.$relativeFinalPath);
                if (file_put_contents($targetPath, $signedPdfBytes) === false) {
                    $errorMessage = 'No se pudo escribir el PDF final en storage.';
                    throw new \RuntimeException($errorMessage);
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $locked->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
                    'pdf_final_path' => $relativeFinalPath,
                    'firmado_presidente_at' => now(),
                    'president_sign_detection_method' => $detectionMethod,
                    'president_sign_duration_ms' => $durationMs,
                    'president_sign_last_error' => null,
                ]);

                Log::info('[PRESIDENT SIGN] Firma presidencial completada', [
                    'tracking_id' => $locked->id,
                    'detection_method' => $detectionMethod,
                    'duration_ms' => $durationMs,
                    'attempt' => $locked->president_sign_attempts,
                ]);
            });
        } catch (\RuntimeException $e) {
            // Transaction was rolled back. Persist the error state in a new (auto-commit) operation.
            if ($errorMessage !== null) {
                $this->markError($tracking, $errorMessage);
            }

            throw $e;
        }
    }

    /**
     * Marca el tracking con el estado de error de firma presidencial y guarda el mensaje.
     */
    private function markError(ConvenioEmailTracking $tracking, string $errorMessage): void
    {
        try {
            $tracking->update([
                'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
                'president_sign_last_error' => $errorMessage,
            ]);

            Log::warning('[PRESIDENT SIGN] Error al firmar', [
                'tracking_id' => $tracking->id,
                'error' => $errorMessage,
            ]);
        } catch (\Exception $e) {
            Log::error('[PRESIDENT SIGN] No se pudo actualizar el estado de error', [
                'tracking_id' => $tracking->id,
                'original_error' => $errorMessage,
                'update_error' => $e->getMessage(),
            ]);
        }
    }
}
