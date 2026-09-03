<?php

namespace App\Jobs;

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

    public $tries = 1;

    public $timeout = 300;

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
        $localZipPath = null;

        try {
            $localZipPath = $zipImportService->materializeZipToTemp($this->storedZipPath);

            foreach ($this->validEntries as $entryMeta) {
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
        } finally {
            if ($localZipPath !== null && is_file($localZipPath)) {
                @unlink($localZipPath);
            }

            try {
                $zipImportService->deleteStoredZip($this->storedZipPath);
            } catch (\Throwable $e) {
                Log::warning('[CONVENIO ZIP] No se pudo eliminar el ZIP de inbox', [
                    'stored_zip_path' => $this->storedZipPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[CONVENIO ZIP] Procesamiento de ZIP completado', [
            'batch_id' => $this->batchId,
            'total_entries' => count($this->validEntries),
            'send_email' => $this->sendEmail,
        ]);
    }
}
