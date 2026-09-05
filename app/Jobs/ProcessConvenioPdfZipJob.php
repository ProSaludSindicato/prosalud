<?php

namespace App\Jobs;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioPdfZipImportService;
use App\Services\ConvenioPreGeneratedPdfDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessConvenioPdfZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 840;

    public bool $failOnTimeout = true;

    /**
     * @param  list<array{entry: string, filename: string, documento: string, nombre_convenio: string}>  $validEntries
     */
    public function __construct(
        public string $batchId,
        public string $storedZipPath,
        public array $validEntries,
        public bool $sendEmail,
        public ?int $generatedByUserId,
    ) {}

    public function handle(
        ConvenioPdfZipImportService $zipImportService,
        ConvenioPreGeneratedPdfDispatchService $dispatchService,
    ): void {
        set_time_limit($this->timeout);
        ini_set('max_execution_time', (string) $this->timeout);

        $localZipPath = null;

        try {
            $localZipPath = $zipImportService->materializeZipToTemp($this->storedZipPath);

            foreach ($this->validEntries as $entryMeta) {
                if ($this->entryAlreadyProcessed($entryMeta)) {
                    continue;
                }

                $tempPdfPath = null;

                try {
                    $tempPdfPath = $zipImportService->extractEntryToTemp($localZipPath, $entryMeta);

                    $dispatchService->persistAndOptionallySend(
                        absolutePdfPath: $tempPdfPath,
                        filename: $entryMeta['filename'],
                        source: 'pdf_zip',
                        batchId: $this->batchId,
                        generatedByUserId: $this->generatedByUserId,
                        sendEmail: $this->sendEmail,
                    );
                } catch (\Throwable $e) {
                    Log::error('[CONVENIO ZIP] Error procesando PDF del ZIP', [
                        'batch_id' => $this->batchId,
                        'entry' => $entryMeta['entry'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    if ($tempPdfPath !== null && is_file($tempPdfPath)) {
                        @unlink($tempPdfPath);
                    }
                }
            }

            $zipImportService->deleteStoredZip($this->storedZipPath);
        } finally {
            if ($localZipPath !== null && is_file($localZipPath)) {
                @unlink($localZipPath);
            }
        }

        Log::info('[CONVENIO ZIP] Procesamiento de ZIP completado', [
            'batch_id' => $this->batchId,
            'total_entries' => count($this->validEntries),
            'send_email' => $this->sendEmail,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[CONVENIO ZIP] Job falló después de todos los intentos', [
            'batch_id' => $this->batchId,
            'stored_zip_path' => $this->storedZipPath,
            'total_entries' => count($this->validEntries),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array{documento?: string}  $entryMeta
     */
    private function entryAlreadyProcessed(array $entryMeta): bool
    {
        $documento = $entryMeta['documento'] ?? null;
        if (! is_string($documento) || $documento === '') {
            return false;
        }

        return ConvenioEmailTracking::query()
            ->where('documento', $documento)
            ->where('convenio_data->batch_id', $this->batchId)
            ->where('convenio_data->source', 'pdf_zip')
            ->exists();
    }
}
