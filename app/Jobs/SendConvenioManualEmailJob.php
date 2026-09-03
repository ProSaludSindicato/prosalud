<?php

namespace App\Jobs;

use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\AfiliadoService;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioDelivery;
use App\Support\ConvenioPreGeneratedPdfFilename;
use App\Support\ConvenioRateLimiter;
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

    public $tries = 5;

    public $backoff = 60;

    public function __construct(
        public string $documento,
        public string $nombreArchivo,
        public string $rutaArchivoPdf,
        public string $nombreConvenio,
        public ?int $parentTrackingId = null,
        public ?string $optionalEmail = null,
        public ?string $sede = null,
        public ?array $convenioData = null,
        public ?int $generatedByUserId = null,
        public ?int $existingTrackingId = null,
    ) {}

    public function handle(
        AfiliadoService $afiliadoService,
        ConvenioDigitalSigningService $convenioDigitalSigningService,
        ConvenioPdfStorageService $convenioPdfStorageService,
    ): void {
        $trackingId = null;
        $tempPdfPath = null;

        try {
            Log::info('Iniciando envío de correo de convenio manual', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
                'email_provided' => ! empty($this->optionalEmail),
                'existing_tracking_id' => $this->existingTrackingId,
            ]);

            $nombreCompletoFromFile = $this->extractNameFromFilename($this->nombreArchivo);

            $tracking = $this->existingTrackingId
                ? ConvenioEmailTracking::find($this->existingTrackingId)
                : null;

            if ($this->existingTrackingId !== null && $tracking === null) {
                Log::error('SendConvenioManualEmailJob: tracking existente no encontrado', [
                    'existing_tracking_id' => $this->existingTrackingId,
                ]);

                return;
            }

            $afiliado = null;
            $emailFromAffiliate = null;
            $email = $this->resolveRecipientEmail();

            $skipProsanetLookup = $this->existingTrackingId !== null && ! empty($this->optionalEmail);

            if ($email === null) {
                if (! empty($this->optionalEmail)) {
                    $email = $this->optionalEmail;
                } else {
                    if (ConvenioRateLimiter::tooManyProsanetAttempts()) {
                        $this->release(ConvenioRateLimiter::prosanetAvailableIn());

                        return;
                    }

                    $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);
                    ConvenioRateLimiter::hitProsanet();

                    if ($afiliado === null) {
                        $errorMessage = 'Afiliado no encontrado para el documento: '.$this->documento.' y no se proporcionó email opcional.';
                        Log::warning('SendConvenioManualEmailJob: '.$errorMessage, [
                            'documento' => $this->documento,
                            'nombre_archivo' => $this->nombreArchivo,
                        ]);

                        $tracking = $tracking ?? $this->createTrackingRecord(null, $errorMessage, $nombreCompletoFromFile);
                        $tracking->marcarComoFallido($errorMessage);

                        return;
                    }

                    $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
                    if (empty($emailFromAffiliate)) {
                        $errorMessage = 'No se encontró correo electrónico para el documento: '.$this->documento.'.';
                        Log::warning('SendConvenioManualEmailJob: '.$errorMessage, [
                            'documento' => $this->documento,
                            'nombre_archivo' => $this->nombreArchivo,
                        ]);

                        $tracking = $tracking ?? $this->createTrackingRecord($afiliado, $errorMessage, $nombreCompletoFromFile);
                        $tracking->marcarComoFallido($errorMessage);

                        return;
                    }

                    $email = $emailFromAffiliate;
                }

                if (! empty($this->optionalEmail) && $afiliado === null && ! $skipProsanetLookup) {
                    if (ConvenioRateLimiter::tooManyProsanetAttempts()) {
                        $this->release(ConvenioRateLimiter::prosanetAvailableIn());

                        return;
                    }

                    $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);
                    ConvenioRateLimiter::hitProsanet();
                }
            } elseif ($afiliado === null && empty($this->optionalEmail)) {
                if (ConvenioRateLimiter::tooManyProsanetAttempts()) {
                    $this->release(ConvenioRateLimiter::prosanetAvailableIn());

                    return;
                }

                $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);
                ConvenioRateLimiter::hitProsanet();
                $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
            } elseif ($afiliado === null && ! empty($this->optionalEmail) && ! $skipProsanetLookup) {
                if (ConvenioRateLimiter::tooManyProsanetAttempts()) {
                    $this->release(ConvenioRateLimiter::prosanetAvailableIn());

                    return;
                }

                $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);
                ConvenioRateLimiter::hitProsanet();
                $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
            }

            $nombreCompleto = $this->resolveAffiliateDisplayName(
                $afiliado,
                $nombreCompletoFromFile,
                $this->convenioData,
            );

            if ($tracking === null) {
                $tracking = $this->createTrackingRecord($afiliado, null, $nombreCompletoFromFile);
            } else {
                $tracking->update([
                    'nombre_afiliado' => $nombreCompleto ?? $tracking->nombre_afiliado,
                    'email_afiliado' => $email,
                ]);
            }

            $trackingId = $tracking->id;

            if ($this->parentTrackingId) {
                $this->sincronizarIntentos($tracking);
            }

            $pdfPathForSend = $this->resolvePdfPath($tracking, $convenioPdfStorageService, $tempPdfPath);

            if ($pdfPathForSend === null) {
                $errorMessage = 'Archivo PDF no encontrado para este registro.';
                Log::error($errorMessage, [
                    'documento' => $this->documento,
                    'ruta_archivo' => $this->rutaArchivoPdf,
                    'tracking_id' => $trackingId,
                ]);

                $tracking->marcarComoFallido($errorMessage);

                if ($this->parentTrackingId) {
                    $this->sincronizarIntentos($tracking);
                }

                return;
            }

            $isTest = $tracking->is_test;

            if (ConvenioRateLimiter::tooManyEmailAttempts()) {
                $this->release(ConvenioRateLimiter::emailAvailableIn());

                return;
            }

            $signingUrl = null;
            if (config('convenio_signing.enabled', true)) {
                try {
                    $plainToken = ConvenioDigitalSigningService::generatePlainToken();
                    $trackingUpdate = [
                        'signing_token_hash' => ConvenioDigitalSigningService::hashPlainToken($plainToken),
                        'token_expires_at' => $convenioDigitalSigningService->tokenExpiresAt(),
                        'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
                    ];
                    if ($isTest) {
                        $trackingUpdate['viewer_header_title'] = '[TEST] '.(string) config('convenio_signing.viewer_header_title');
                    }
                    $tracking->update($trackingUpdate);
                    $signingUrl = $convenioDigitalSigningService->buildSigningUrl($plainToken);
                } catch (\Throwable $e) {
                    Log::error('[CONVENIO DIGITAL] No se pudo preparar enlace de firma; se enviará PDF adjunto', [
                        'tracking_id' => $tracking->id,
                        'error' => $e->getMessage(),
                    ]);
                    $signingUrl = null;
                }
            }

            $mailable = new ConvenioManualNotification(
                $nombreCompleto ?? '',
                $this->documento,
                $this->nombreConvenio,
                $signingUrl,
                $isTest,
            );

            if ($signingUrl === null) {
                $mailable->attachPdfFromPath($pdfPathForSend);
            }

            Mail::to($email)->send($mailable);
            ConvenioRateLimiter::hitEmail();

            $tracking->marcarComoEnviado();
            $tracking->refresh();

            $originalContents = file_get_contents($pdfPathForSend);
            $originalSha256 = is_string($originalContents) && $originalContents !== ''
                ? hash('sha256', $originalContents)
                : null;

            if ($tracking->pdf_original_path === null || $tracking->pdf_original_path === '') {
                $relativeStored = $convenioPdfStorageService->storeOriginalFromAbsolutePath($tracking, $pdfPathForSend);
                $tracking->update([
                    'pdf_original_path' => $relativeStored,
                    'pdf_original_sha256' => $originalSha256,
                ]);
            } else {
                $migratedPath = $convenioPdfStorageService->migrateToMnemonicPath(
                    $tracking,
                    \App\Enums\ConvenioPdfStage::Original,
                );
                if ($migratedPath !== null) {
                    $tracking->update([
                        'pdf_original_path' => $migratedPath,
                        'pdf_original_sha256' => $originalSha256 ?? $tracking->pdf_original_sha256,
                    ]);
                }
            }

            if ($this->parentTrackingId) {
                $this->sincronizarIntentos($tracking);
            }

            Log::info('Correo de convenio manual enviado exitosamente', [
                'tracking_id' => $trackingId,
                'documento' => $this->documento,
                'email' => $email,
                'email_source' => ! empty($this->optionalEmail) ? 'provided' : 'affiliate',
                'affiliate_email' => $emailFromAffiliate,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al enviar correo de convenio manual', [
                'tracking_id' => $trackingId,
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            if ($tempPdfPath !== null && is_file($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }

    private function resolveRecipientEmail(): ?string
    {
        if (ConvenioDelivery::isTestMode()) {
            if ($this->generatedByUserId !== null) {
                return User::query()->where('id', $this->generatedByUserId)->value('email');
            }

            if ($this->optionalEmail !== null && $this->optionalEmail !== '') {
                return $this->optionalEmail;
            }

            return null;
        }

        if (! empty($this->optionalEmail)) {
            return $this->optionalEmail;
        }

        return null;
    }

    private function resolvePdfPath(
        ConvenioEmailTracking $tracking,
        ConvenioPdfStorageService $convenioPdfStorageService,
        ?string &$tempPdfPath,
    ): ?string {
        if (is_file($this->rutaArchivoPdf)) {
            return $this->rutaArchivoPdf;
        }

        if (is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)) {
            return $tracking->ruta_archivo_pdf;
        }

        $materialized = $convenioPdfStorageService->materializeOriginalToTemp($tracking);
        if ($materialized !== null && is_file($materialized)) {
            if (str_contains($materialized, 's3-')) {
                $tempPdfPath = $materialized;
            }

            return $materialized;
        }

        return null;
    }

    private function sincronizarIntentos(ConvenioEmailTracking $tracking): void
    {
        if (! $this->parentTrackingId) {
            return;
        }

        $parentTracking = ConvenioEmailTracking::find($this->parentTrackingId);
        if (! $parentTracking) {
            return;
        }

        $numeroIntentos = $parentTracking->intentos;

        $tracking->update(['intentos' => $numeroIntentos]);
        $parentTracking->update(['intentos' => $numeroIntentos]);
        $parentTracking->resends()->update(['intentos' => $numeroIntentos]);
    }

    /**
     * @param  array<string, mixed>|null  $afiliado
     * @param  array<string, mixed>|null  $convenioData
     */
    private function resolveAffiliateDisplayName(?array $afiliado, string $nombreFromFile, ?array $convenioData): ?string
    {
        $nombreCompleto = '';

        if ($afiliado) {
            $nombreCompleto = trim((string) ($afiliado['nombre_completo'] ?? ''));
            if ($nombreCompleto === '') {
                $nombreCompleto = trim(trim((string) ($afiliado['nombres'] ?? '')).' '.trim((string) ($afiliado['apellidos'] ?? '')));
            }
        }

        if ($nombreCompleto === '' && is_array($convenioData)) {
            $nombreCompleto = trim(trim((string) ($convenioData['nombres'] ?? '')).' '.trim((string) ($convenioData['apellidos'] ?? '')));
        }

        if ($nombreCompleto === '' && $nombreFromFile !== '') {
            $nombreCompleto = trim($nombreFromFile);
        }

        return $nombreCompleto !== '' ? $nombreCompleto : null;
    }

    private function extractNameFromFilename(string $filename): string
    {
        $parsed = ConvenioPreGeneratedPdfFilename::parse($filename);

        return $parsed['nombre_afiliado'] ?? '';
    }

    private function createTrackingRecord(?array $afiliado, ?string $errorMessage, string $nombreFromFile = ''): ConvenioEmailTracking
    {
        $emailAfiliado = $afiliado['correo_personal'] ?? null;
        $emailToStore = ! empty($this->optionalEmail) ? $this->optionalEmail : $emailAfiliado;

        $parentTracking = $this->parentTrackingId
            ? ConvenioEmailTracking::find($this->parentTrackingId)
            : null;

        $convenioData = $this->convenioData;
        if (($convenioData === null || $convenioData === []) && $parentTracking) {
            $convenioData = $parentTracking->convenio_data;
        }

        $nombreAfiliado = $this->resolveAffiliateDisplayName(
            $afiliado,
            $nombreFromFile,
            $convenioData,
        ) ?? 'No disponible';

        $intentos = $parentTracking?->intentos ?? 0;

        $sedeParaTracking = $this->sede !== null && $this->sede !== '' ? $this->sede : null;
        if ($sedeParaTracking === null && $afiliado !== null) {
            $hospital = $afiliado['hospital'] ?? null;
            $sedeParaTracking = is_string($hospital) && trim($hospital) !== '' ? trim($hospital) : null;
        }

        $generatedByUserId = $this->generatedByUserId ?? $parentTracking?->generated_by_user_id;

        return ConvenioEmailTracking::create([
            'documento' => $this->documento,
            'nombre_afiliado' => $nombreAfiliado ?: 'No disponible',
            'email_afiliado' => $emailToStore ?: 'No disponible',
            'nombre_convenio' => $this->nombreConvenio,
            'nombre_archivo' => $this->nombreArchivo,
            'ruta_archivo_pdf' => $this->rutaArchivoPdf,
            'estado' => 'pendiente',
            'error_message' => $errorMessage,
            'intentos' => $intentos,
            'parent_tracking_id' => $this->parentTrackingId,
            'sede' => $sedeParaTracking,
            'convenio_data' => $convenioData,
            'generated_by_user_id' => $generatedByUserId,
            'is_test' => ConvenioDelivery::isTestMode() || (bool) $parentTracking?->is_test,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job de envío de correo de convenio manual falló después de todos los intentos', [
            'documento' => $this->documento,
            'nombre_archivo' => $this->nombreArchivo,
            'nombre_convenio' => $this->nombreConvenio,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $tracking = $this->existingTrackingId
            ? ConvenioEmailTracking::find($this->existingTrackingId)
            : ConvenioEmailTracking::query()
                ->where('documento', $this->documento)
                ->where('nombre_archivo', $this->nombreArchivo)
                ->where('estado', 'pendiente')
                ->orderByDesc('created_at')
                ->first();

        if ($tracking) {
            $tracking->marcarComoFallido('Job falló después de '.$this->tries.' intentos: '.$exception->getMessage());
        }
    }
}
