<?php

namespace App\Jobs;

use App\Contracts\DocumentSigningServiceInterface;
use App\Mail\DocumentSigningInvitation;
use App\Models\DocumentSigningEmailTracking;
use App\Services\AfiliadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDocumentSigningEmailJob implements ShouldQueue
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
        public string $documentNumber,
        public string $emailSubject,
        public string $documentName,
        public ?string $tipoDocumento = 'CC',
        public ?string $optionalEmail = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        DocumentSigningServiceInterface $documentSigningService,
        AfiliadoService $afiliadoService
    ): void {
        $trackingId = null;

        try {
            Log::info('SendDocumentSigningEmailJob: Iniciando envío de correo de firma', [
                'document_number' => $this->documentNumber,
            ]);

            // Get affiliate information
            // Since we don't have fecha_expedicion, use getAfiliadoByDocumentoOnly first
            $afiliadoBasic = $afiliadoService->getAfiliadoByDocumentoOnly($this->documentNumber);

            if (!$afiliadoBasic) {
                $errorMessage = 'Afiliado no encontrado para el documento: ' . $this->documentNumber;
                Log::warning('SendDocumentSigningEmailJob: ' . $errorMessage, [
                    'document_number' => $this->documentNumber,
                ]);

                $this->createFailedTracking($this->documentNumber, $errorMessage);
                return;
            }

            // Build afiliado array with the information we have
            // This matches the structure expected by DocuSignService for prefilling
            $afiliado = [
                'documento' => $afiliadoBasic['documento'] ?? $this->documentNumber,
                'tipo_documento' => $afiliadoBasic['tipo_documento'] ?? $this->tipoDocumento,
                'nombres' => $afiliadoBasic['nombres'] ?? '',
                'apellidos' => $afiliadoBasic['apellidos'] ?? '',
                'correo_personal' => $afiliadoBasic['correo_personal'] ?? null,
                'nombre_completo' => $afiliadoBasic['nombre_completo'] ?? '',
                // Add other fields that might be needed for prefilling (will be empty if not available)
                'lugar_nacimiento' => null,
                'fecha_nacimiento' => null,
                'direccion' => null,
                'telefono' => null,
                'celular' => null,
            ];

            // Determine email to use: optional email provided, or from affiliate
            $emailFromAffiliate = $afiliado['correo_personal'] ?? null;
            $email = null;

            if (!empty($this->optionalEmail)) {
                // Use provided email if available
                $email = $this->optionalEmail;
                Log::info('SendDocumentSigningEmailJob: Usando correo electrónico proporcionado', [
                    'document_number' => $this->documentNumber,
                    'provided_email' => $email,
                    'affiliate_email' => $emailFromAffiliate,
                ]);
            } elseif (!empty($emailFromAffiliate)) {
                // Use email from affiliate if available
                $email = $emailFromAffiliate;
                Log::info('SendDocumentSigningEmailJob: Usando correo electrónico del afiliado', [
                    'document_number' => $this->documentNumber,
                    'affiliate_email' => $email,
                ]);
            } else {
                // No email available
                $errorMessage = 'No se encontró correo electrónico para el documento: ' . $this->documentNumber . '. No se proporcionó email opcional y el afiliado no tiene correo registrado.';
                Log::warning('SendDocumentSigningEmailJob: ' . $errorMessage, [
                    'document_number' => $this->documentNumber,
                ]);

                $this->createFailedTracking($this->documentNumber, $errorMessage);
                return;
            }

            $nombres = $afiliado['nombres'] ?? '';
            $apellidos = $afiliado['apellidos'] ?? '';
            $nombreCompleto = trim($nombres . ' ' . $apellidos);

            if (empty($nombreCompleto)) {
                $errorMessage = 'No se pudo obtener el nombre completo del afiliado: ' . $this->documentNumber;
                Log::warning('SendDocumentSigningEmailJob: ' . $errorMessage, [
                    'document_number' => $this->documentNumber,
                ]);

                $this->createFailedTracking($this->documentNumber, $errorMessage);
                return;
            }

            // Find PDF contract file
            $pdfPath = $documentSigningService->findContractPdfByDocumentNumber($this->documentNumber);

            if (null === $pdfPath) {
                $errorMessage = 'No se encontró el PDF del convenio para el documento: ' . $this->documentNumber;
                Log::warning('SendDocumentSigningEmailJob: ' . $errorMessage, [
                    'document_number' => $this->documentNumber,
                ]);

                $this->createFailedTracking($this->documentNumber, $errorMessage);
                return;
            }

            // Create envelope with status='created' (not 'sent') to get signing URL
            // We will send the email from our application, not from DocuSign
            $result = $documentSigningService->createEnvelopeAndGetSigningUrl(
                pdfPath: $pdfPath,
                signer: [
                    'email' => $email,
                    'name' => $nombreCompleto,
                    'documento' => $this->documentNumber,
                    'afiliado' => $afiliado,
                ],
                returnUrl: config('app.frontend_url', '') . '/servicios/firma-convenio?envelope_id={envelope_id}&documento=' . $this->documentNumber . '&event={event}',
                emailSubject: $this->emailSubject,
                documentName: $this->documentName,
                sendEmail: false // Don't send email from DocuSign, we'll send it ourselves
            );

            if (empty($result['signing_url'])) {
                $errorMessage = 'No se pudo generar la URL de firma para el documento: ' . $this->documentNumber;
                Log::error('SendDocumentSigningEmailJob: ' . $errorMessage, [
                    'document_number' => $this->documentNumber,
                    'envelope_id' => $result['envelope_id'] ?? null,
                ]);

                $this->createFailedTracking($this->documentNumber, $errorMessage);
                return;
            }

            // Create tracking record manually (since we're not using DocuSign email)
            $tracking = DocumentSigningEmailTracking::create([
                'envelope_id' => $result['envelope_id'],
                'document_number' => $this->documentNumber,
                'recipient_email' => $email,
                'recipient_name' => $nombreCompleto,
                'provider' => config('services.document_signing.provider', 'docusign'),
                'email_status' => 'pending',
            ]);
            $trackingId = $tracking->id;

            // Send email from our application
            $mailable = new DocumentSigningInvitation(
                nombreAfiliado: $nombreCompleto,
                documento: $this->documentNumber,
                signingUrl: $result['signing_url'],
                emailSubject: $this->emailSubject
            );

            Mail::to($email)->send($mailable);

            // Mark as sent
            $tracking->markAsSent();

            Log::info('SendDocumentSigningEmailJob: Correo de firma enviado exitosamente desde la aplicación', [
                'tracking_id' => $trackingId,
                'document_number' => $this->documentNumber,
                'envelope_id' => $result['envelope_id'],
                'email' => $email,
                'email_source' => !empty($this->optionalEmail) ? 'provided' : 'affiliate',
                'affiliate_email' => $emailFromAffiliate,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = 'Error al enviar correo de firma: ' . $e->getMessage();
            Log::error('SendDocumentSigningEmailJob: Error al enviar correo', [
                'tracking_id' => $trackingId,
                'document_number' => $this->documentNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Create or update tracking with error
            $this->createFailedTracking($this->documentNumber, $errorMessage);

            throw $e; // Re-lanzar para que Laravel lo marque como fallido y pueda reintentar
        }
    }

    /**
     * Create a failed tracking record.
     */
    private function createFailedTracking(string $documentNumber, string $errorMessage): void
    {
        try {
            // Try to get basic info for tracking
            $tracking = DocumentSigningEmailTracking::create([
                'envelope_id' => 'pending-' . $documentNumber . '-' . time(), // Temporary ID
                'document_number' => $documentNumber,
                'recipient_email' => 'unknown',
                'recipient_name' => 'Unknown',
                'provider' => config('services.document_signing.provider', 'docusign'),
                'email_status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            Log::info('SendDocumentSigningEmailJob: Tracking de error creado', [
                'tracking_id' => $tracking->id,
                'document_number' => $documentNumber,
            ]);
        } catch (\Exception $e) {
            Log::error('SendDocumentSigningEmailJob: Error al crear tracking de error', [
                'document_number' => $documentNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendDocumentSigningEmailJob: Job falló después de todos los intentos', [
            'document_number' => $this->documentNumber,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Update tracking record if exists
        $tracking = DocumentSigningEmailTracking::where('document_number', $this->documentNumber)
            ->where('email_status', 'failed')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($tracking) {
            $tracking->markAsFailed('Job falló después de ' . $this->tries . ' intentos: ' . $exception->getMessage());
        }
    }
}
