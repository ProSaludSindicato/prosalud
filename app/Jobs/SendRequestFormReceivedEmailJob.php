<?php

namespace App\Jobs;

use App\Constants\RequestTypes;
use App\Mail\RequestFormReceived;
use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendRequestFormReceivedEmailJob implements ShouldQueue
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
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Iniciando envío de correo de confirmación de recepción', [
                'request_id' => $this->requestFormId,
            ]);

            // Load the request form
            $requestForm = RequestForm::find($this->requestFormId);

            if (!$requestForm) {
                Log::warning('RequestForm no encontrado para envío de correo de confirmación', [
                    'request_id' => $this->requestFormId,
                ]);
                return;
            }

            $mail = Mail::to($requestForm->email);

            // Agregar CC para solicitudes de microcrédito
            if ($requestForm->request_type === RequestTypes::SOLICITUD_MICROCREDITO || 
                $requestForm->request_type === 'solicitud-microcredito') {
                $mail->cc('ceiisas@hotmail.com');
            }

            // Agregar CC para solicitudes de retiro sindical
            if ($requestForm->request_type === RequestTypes::SOLICITUD_RETIRO_SINDICAL || $requestForm->request_type === 'retiro-sindical') {
                $mail->cc('talentohumano@sindicatoprosalud.com');
            }
            
            // Agregar CC para solicitudes de actualización de datos personales que incluyen información bancaria
            if ($requestForm->hasBankInfoUpdate()) {
                $mail->cc('comunicaciones@sindicatoprosalud.com');
            }

            $mail->send(new RequestFormReceived($requestForm));

            Log::info('Correo de confirmación de solicitud enviado exitosamente', [
                'request_id' => $this->requestFormId,
                'email' => $requestForm->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de confirmación de solicitud', [
                'request_id' => $this->requestFormId,
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
        Log::error('Job de envío de correo de confirmación de recepción falló después de todos los intentos', [
            'request_id' => $this->requestFormId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

