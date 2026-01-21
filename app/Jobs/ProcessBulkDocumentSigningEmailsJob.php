<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBulkDocumentSigningEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1; // Only try once, individual jobs will retry

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $documentNumbers,
        public array $emailMap = [],
        public string $emailSubject = 'Firma de Convenio de Afiliación',
        public string $documentName = 'Convenio de Afiliación',
        public ?string $tipoDocumento = 'CC'
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessBulkDocumentSigningEmailsJob: Iniciando procesamiento de envío masivo', [
            'total_documents' => count($this->documentNumbers),
            'emails_provided' => count($this->emailMap),
            'email_subject' => $this->emailSubject,
        ]);

        $dispatchedCount = 0;

        foreach ($this->documentNumbers as $documentNumber) {
            try {
                // Get optional email for this document if provided
                $optionalEmail = $this->emailMap[$documentNumber] ?? null;

                // Dispatch individual job for each document
                SendDocumentSigningEmailJob::dispatch(
                    documentNumber: $documentNumber,
                    emailSubject: $this->emailSubject,
                    documentName: $this->documentName,
                    tipoDocumento: $this->tipoDocumento,
                    optionalEmail: $optionalEmail
                );

                $dispatchedCount++;

                Log::debug('ProcessBulkDocumentSigningEmailsJob: Job despachado', [
                    'document_number' => $documentNumber,
                    'email_provided' => !empty($optionalEmail),
                ]);
            } catch (\Exception $e) {
                Log::error('ProcessBulkDocumentSigningEmailsJob: Error al despachar job individual', [
                    'document_number' => $documentNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessBulkDocumentSigningEmailsJob: Procesamiento completado', [
            'total_documents' => count($this->documentNumbers),
            'dispatched_count' => $dispatchedCount,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessBulkDocumentSigningEmailsJob: Job falló', [
            'total_documents' => count($this->documentNumbers),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
