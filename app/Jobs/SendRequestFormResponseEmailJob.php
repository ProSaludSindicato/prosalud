<?php

namespace App\Jobs;

use App\Constants\RequestTypes;
use App\Mail\RequestFormResponse;
use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendRequestFormResponseEmailJob implements ShouldQueue
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
        public string $requestFormId,
        public string $recipientEmail,
        public string $emailSubject,
        public string $emailBody,
        public string $status,
        public array $attachmentData = [],
        public array $compressedFileUrls = [],
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Iniciando envío de correo de respuesta de solicitud', [
                'request_id' => $this->requestFormId,
                'email_to' => $this->recipientEmail,
                'status' => $this->status,
            ]);

            // Load the request form
            $requestForm = RequestForm::find($this->requestFormId);

            if (!$requestForm) {
                Log::warning('RequestForm no encontrado para envío de correo de respuesta', [
                    'request_id' => $this->requestFormId,
                ]);
                return;
            }

            $mail = Mail::to($this->recipientEmail);

            // Agregar CC para solicitudes de microcrédito
            if ($requestForm->request_type === RequestTypes::SOLICITUD_MICROCREDITO) {
                $mail->cc('ceiisas@hotmail.com');
            }

            // Agregar CC para solicitudes de retiro sindical
            if ($requestForm->request_type === RequestTypes::SOLICITUD_RETIRO_SINDICAL || $requestForm->request_type === 'retiro-sindical') {
                $mail->cc('talentohumano@sindicatoprosalud.com');
            }

            // Create mailable with serialized attachment data
            // The attachmentData array contains: ['content' => string, 'name' => string, 'mime' => string]
            // RequestFormResponse now accepts serialized data directly
            $mailable = new RequestFormResponse(
                $requestForm,
                $this->emailSubject,
                $this->emailBody,
                $this->status,
                $this->attachmentData, // Pass serialized attachment data directly
                $this->compressedFileUrls
            );

            $mail->send($mailable);

            Log::info('Correo de respuesta enviado exitosamente', [
                'request_id' => $this->requestFormId,
                'email_recipient' => $this->recipientEmail,
                'status' => $this->status,
                'attachments_count' => count($this->attachmentData),
                'compressed_files_count' => count($this->compressedFileUrls),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de respuesta de solicitud', [
                'request_id' => $this->requestFormId,
                'email_recipient' => $this->recipientEmail,
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
        Log::error('Job de envío de correo de respuesta de solicitud falló después de todos los intentos', [
            'request_id' => $this->requestFormId,
            'email_recipient' => $this->recipientEmail,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

