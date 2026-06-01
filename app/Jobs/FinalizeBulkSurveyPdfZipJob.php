<?php

namespace App\Jobs;

use App\Services\AuditLogService;
use App\Services\SocioDemographicSurveyBulkPdfExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FinalizeBulkSurveyPdfZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $jobId,
        public array $filters,
        public ?int $userId,
        public int $totalSurveys,
        public int $totalParts,
        public string $baseFileName,
    ) {}

    public function handle(
        SocioDemographicSurveyBulkPdfExportService $exportService,
        AuditLogService $auditLogService
    ): void {
        set_time_limit($this->timeout);

        $zipFileName = $this->baseFileName.'.zip';
        $disk = Storage::disk($exportService->reportsDiskName());
        $tempDirectory = $exportService->tempPartsDirectory($this->jobId);
        $tempFiles = $disk->files($tempDirectory);

        if ($tempFiles === []) {
            throw new \RuntimeException('No se encontraron partes del PDF para empaquetar.');
        }

        sort($tempFiles, SORT_NATURAL);

        $parts = [];
        foreach ($tempFiles as $index => $storagePath) {
            $parts[] = [
                'storage_path' => $storagePath,
                'file_name' => basename($storagePath),
                'count' => $exportService->surveysPerPart(),
            ];
        }

        $lastPartIndex = count($parts);
        $remainder = $this->totalSurveys - (($lastPartIndex - 1) * $exportService->surveysPerPart());
        if ($remainder > 0 && $remainder < $exportService->surveysPerPart()) {
            $parts[$lastPartIndex - 1]['count'] = $remainder;
        }

        $finalStoragePath = $exportService->createZipFromParts($this->jobId, $zipFileName, $parts);

        $exportService->putStatus($this->jobId, [
            'status' => 'completed',
            'file_path' => $finalStoragePath,
            'file_name' => $zipFileName,
            'created_at' => now()->toIso8601String(),
            'count' => $this->totalSurveys,
            'completed_parts' => $this->totalParts,
            'total_parts' => $this->totalParts,
            'finalize_dispatched' => true,
            'parts' => array_map(static function (array $part): array {
                return [
                    'file_name' => $part['file_name'],
                    'count' => $part['count'],
                ];
            }, $parts),
        ]);

        $exportService->cleanupTempParts($this->jobId);

        Log::info('[BULK PDF JOB] ZIP masivo de encuestas sociodemográficas generado exitosamente', [
            'job_id' => $this->jobId,
            'file_path' => $finalStoragePath,
            'file_name' => $zipFileName,
            'count' => $this->totalSurveys,
            'parts' => count($parts),
            'user_id' => $this->userId,
        ]);

        $auditLogService->logBusinessProcess('socio_demographic_survey', 'bulk_pdf_download', [
            'job_id' => $this->jobId,
            'count' => $this->totalSurveys,
            'file_name' => $zipFileName,
            'filters' => $this->filters,
            'user_id' => $this->userId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $exportService = app(SocioDemographicSurveyBulkPdfExportService::class);

        Log::error('[BULK PDF JOB] Falló la finalización del ZIP masivo', [
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
            'user_id' => $this->userId,
        ]);

        $exportService->markFailed(
            $this->jobId,
            'Error al empaquetar el ZIP: '.$exception->getMessage()
        );
        $exportService->cleanupTempParts($this->jobId);
    }
}
