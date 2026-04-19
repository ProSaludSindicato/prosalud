<?php

namespace App\Jobs;

use App\Mail\ConvenioManualNotification;
use App\Models\ConvenioEmailTracking;
use App\Services\AfiliadoService;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
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
        public ?int $parentTrackingId = null,
        public ?string $optionalEmail = null,
        public ?string $sede = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AfiliadoService $afiliadoService,
        ConvenioDigitalSigningService $convenioDigitalSigningService,
        ConvenioPdfStorageService $convenioPdfStorageService,
    ): void {
        $trackingId = null;

        try {
            Log::info('Iniciando envío de correo de convenio manual', [
                'documento' => $this->documento,
                'nombre_archivo' => $this->nombreArchivo,
                'nombre_convenio' => $this->nombreConvenio,
                'email_provided' => ! empty($this->optionalEmail),
            ]);

            // First, extract name from filename if possible
            // Format: "HLM-ASIS - RESTREPO RAMIREZ MARIANA - 1000757150.pdf"
            $nombreCompletoFromFile = $this->extractNameFromFilename($this->nombreArchivo);

            $afiliado = null;
            $emailFromAffiliate = null;
            $email = null;

            // Determine email to use: optional email provided, or from affiliate
            if (! empty($this->optionalEmail)) {
                // Use provided email - don't require affiliate to exist
                $email = $this->optionalEmail;
                Log::info('SendConvenioManualEmailJob: Usando correo electrónico proporcionado', [
                    'documento' => $this->documento,
                    'provided_email' => $email,
                ]);

                // Try to get affiliate info, but it's optional
                $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);
                if ($afiliado) {
                    $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
                    Log::info('SendConvenioManualEmailJob: Afiliado encontrado pero usando email proporcionado', [
                        'documento' => $this->documento,
                        'affiliate_email' => $emailFromAffiliate,
                    ]);
                } else {
                    Log::info('SendConvenioManualEmailJob: Afiliado no encontrado pero se proporcionó email, continuando', [
                        'documento' => $this->documento,
                        'nombre_from_file' => $nombreCompletoFromFile,
                    ]);
                }
            } else {
                // No optional email provided - must get from affiliate
                $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($this->documento);

                if ($afiliado === null) {
                    $errorMessage = 'Afiliado no encontrado para el documento: '.$this->documento.' y no se proporcionó email opcional.';
                    Log::warning('SendConvenioManualEmailJob: '.$errorMessage, [
                        'documento' => $this->documento,
                        'nombre_archivo' => $this->nombreArchivo,
                    ]);

                    // Create tracking record with error
                    $tracking = $this->createTrackingRecord(null, $errorMessage, $nombreCompletoFromFile);
                    if ($tracking) {
                        $tracking->marcarComoFallido($errorMessage);
                    }

                    return;
                }

                $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
                if (empty($emailFromAffiliate)) {
                    $errorMessage = 'No se encontró correo electrónico para el documento: '.$this->documento.'. No se proporcionó email opcional y el afiliado no tiene correo registrado.';
                    Log::warning('SendConvenioManualEmailJob: '.$errorMessage, [
                        'documento' => $this->documento,
                        'nombre_archivo' => $this->nombreArchivo,
                    ]);

                    // Create tracking record with error
                    $tracking = $this->createTrackingRecord($afiliado, $errorMessage, $nombreCompletoFromFile);
                    if ($tracking) {
                        $tracking->marcarComoFallido($errorMessage);
                    }

                    return;
                }

                // Use email from affiliate
                $email = $emailFromAffiliate;
                Log::info('SendConvenioManualEmailJob: Usando correo electrónico del afiliado', [
                    'documento' => $this->documento,
                    'affiliate_email' => $email,
                ]);
            }

            // Get full name: from affiliate if available, otherwise from filename
            $nombreCompleto = '';
            if ($afiliado) {
                $nombreCompleto = $afiliado['nombre_completo'] ?? '';
                if (empty($nombreCompleto)) {
                    $nombreCompleto = trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''));
                }
            }

            // If still empty, use name from filename
            if (empty($nombreCompleto)) {
                $nombreCompleto = $nombreCompletoFromFile;
            }

            // Fallback if still empty
            if (empty($nombreCompleto)) {
                $nombreCompleto = 'Estimado afiliado';
            }

            // Create tracking record before sending
            $tracking = $this->createTrackingRecord($afiliado, null, $nombreCompletoFromFile);
            $trackingId = $tracking->id;

            // Si es un reenvío, sincronizar el número de intentos con el padre y todos los registros relacionados
            if ($this->parentTrackingId) {
                $this->sincronizarIntentos($tracking);
            }

            // Verify PDF file exists
            if (! file_exists($this->rutaArchivoPdf)) {
                $errorMessage = 'Archivo PDF no encontrado: '.$this->rutaArchivoPdf;
                Log::error($errorMessage, [
                    'documento' => $this->documento,
                    'ruta_archivo' => $this->rutaArchivoPdf,
                ]);

                $tracking->marcarComoFallido($errorMessage);

                // Si es un reenvío, asegurar que los intentos estén sincronizados
                if ($this->parentTrackingId) {
                    $this->sincronizarIntentos($tracking);
                }

                return;
            }

            $signingUrl = null;
            if (config('convenio_signing.enabled', true)) {
                try {
                    $plainToken = ConvenioDigitalSigningService::generatePlainToken();
                    $relativeStored = $convenioPdfStorageService->storeOriginalFromAbsolutePath($tracking, $this->rutaArchivoPdf);
                    $tracking->update([
                        'signing_token_hash' => ConvenioDigitalSigningService::hashPlainToken($plainToken),
                        'token_expires_at' => $convenioDigitalSigningService->tokenExpiresAt(),
                        'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
                        'pdf_original_path' => $relativeStored,
                    ]);
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
                $nombreCompleto,
                $this->documento,
                $this->nombreConvenio,
                $signingUrl,
            );

            if ($signingUrl === null) {
                $mailable->attachPdfFromPath($this->rutaArchivoPdf);
            }

            Mail::to($email)->send($mailable);

            // Mark as sent successfully
            $tracking->marcarComoEnviado();

            // Si es un reenvío, asegurar que los intentos estén sincronizados
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
            $errorMessage = 'Error al enviar correo: '.$e->getMessage();
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

                    // Si es un reenvío, asegurar que los intentos estén sincronizados
                    if ($this->parentTrackingId) {
                        $this->sincronizarIntentos($tracking);
                    }
                }
            }

            throw $e; // Re-lanzar para que Laravel lo marque como fallido y pueda reintentar
        }
    }

    /**
     * Sincronizar el número de intentos con el padre y todos los registros relacionados
     */
    private function sincronizarIntentos(ConvenioEmailTracking $tracking): void
    {
        if (! $this->parentTrackingId) {
            return;
        }

        $parentTracking = ConvenioEmailTracking::find($this->parentTrackingId);
        if (! $parentTracking) {
            return;
        }

        // Obtener el número de intentos del padre (que ya fue incrementado)
        $numeroIntentos = $parentTracking->intentos;

        // Actualizar este registro
        $tracking->update(['intentos' => $numeroIntentos]);

        // Actualizar el padre y todos sus reenvíos para mantener consistencia
        $parentTracking->update(['intentos' => $numeroIntentos]);
        $parentTracking->resends()->update(['intentos' => $numeroIntentos]);
    }

    /**
     * Extract name from filename
     * Format: "HLM-ASIS - RESTREPO RAMIREZ MARIANA - 1000757150.pdf"
     * Returns the middle part (name) or empty string
     */
    private function extractNameFromFilename(string $filename): string
    {
        // Remove .pdf extension if present
        $filenameWithoutExt = preg_replace('/\.pdf$/i', '', $filename);

        // Split by " - " to get parts
        $parts = explode(' - ', $filenameWithoutExt);

        // Format should be: "CONVENIO - NOMBRE - DOCUMENTO"
        if (count($parts) >= 3) {
            // Return the middle part (index 1) which is the name
            return trim($parts[1]);
        } elseif (count($parts) === 2) {
            // Fallback: might be "CONVENIO - NOMBRE" or "NOMBRE - DOCUMENTO"
            // Try to detect which part is the name (longer text)
            return trim($parts[0]);
        }

        return '';
    }

    /**
     * Create tracking record
     */
    private function createTrackingRecord(?array $afiliado, ?string $errorMessage, string $nombreFromFile = ''): ConvenioEmailTracking
    {
        $emailAfiliado = $afiliado['correo_personal'] ?? null;
        // Use provided email if available, otherwise use affiliate email
        $emailToStore = ! empty($this->optionalEmail) ? $this->optionalEmail : $emailAfiliado;

        $nombreAfiliado = '';

        if ($afiliado) {
            $nombreAfiliado = $afiliado['nombre_completo'] ?? '';
            if (empty($nombreAfiliado)) {
                $nombreAfiliado = trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''));
            }
        }

        // If no name from affiliate, use name from filename
        if (empty($nombreAfiliado)) {
            $nombreAfiliado = $nombreFromFile;
        }

        // Final fallback
        if (empty($nombreAfiliado)) {
            $nombreAfiliado = 'No disponible';
        }

        // Si es un reenvío, obtener el número de intentos del tracking padre
        $intentos = 0;
        if ($this->parentTrackingId) {
            $parentTracking = ConvenioEmailTracking::find($this->parentTrackingId);
            if ($parentTracking) {
                $intentos = $parentTracking->intentos;
            }
        }

        $sedeParaTracking = $this->sede !== null && $this->sede !== '' ? $this->sede : null;
        if ($sedeParaTracking === null && $afiliado !== null) {
            $hospital = $afiliado['hospital'] ?? null;
            $sedeParaTracking = is_string($hospital) && trim($hospital) !== '' ? trim($hospital) : null;
        }

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
            $tracking->marcarComoFallido('Job falló después de '.$this->tries.' intentos: '.$exception->getMessage());
        }
    }
}
