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
        public string $emailSubject,
        public string $documentName,
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
            'email_subject' => $this->emailSubject,
        ]);

        $dispatchedCount = 0;

        foreach ($this->documentNumbers as $documentNumber) {
            try {
                // Dispatch individual job for each document
                SendDocumentSigningEmailJob::dispatch(
                    documentNumber: $documentNumber,
                    emailSubject: $this->emailSubject,
                    documentName: $this->documentName,
                    tipoDocumento: $this->tipoDocumento
                );

                $dispatchedCount++;

                Log::debug('ProcessBulkDocumentSigningEmailsJob: Job despachado', [
                    'document_number' => $documentNumber,
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
