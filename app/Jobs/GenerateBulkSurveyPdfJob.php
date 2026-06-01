<?php

namespace App\Jobs;

use App\Services\SocioDemographicSurveyBulkPdfExportService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class GenerateBulkSurveyPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $jobId,
        public array $filters,
        public ?int $userId = null
    ) {}

    public function handle(SocioDemographicSurveyBulkPdfExportService $exportService): void
    {
        set_time_limit($this->timeout);

        Log::info('[BULK PDF JOB] Iniciando orquestación de PDF masivo de encuestas sociodemográficas', [
            'job_id' => $this->jobId,
            'user_id' => $this->userId,
            'filters' => $this->filters,
        ]);

        $query = $exportService->buildFilteredQuery($this->filters);
        $surveyIds = $query->orderBy('created_at', 'desc')->pluck('id')->all();

        if ($surveyIds === []) {
            $exportService->markFailed($this->jobId, 'No se encontraron encuestas con los filtros especificados.');

            return;
        }

        $surveysPerPart = $exportService->surveysPerPart();
        $chunks = array_chunk($surveyIds, $surveysPerPart);
        $totalParts = count($chunks);
        $generatedAt = now()->setTimezone('America/Bogota');
        $baseFileName = 'Encuestas_Sociodemograficas_'.$generatedAt->format('Y-m-d_His');

        $exportService->putStatus($this->jobId, array_merge($exportService->getStatus($this->jobId) ?? [], [
            'status' => 'processing',
            'count' => count($surveyIds),
            'completed_parts' => 0,
            'total_parts' => $totalParts,
            'base_file_name' => $baseFileName,
            'user_id' => $this->userId,
            'finalize_dispatched' => false,
            'updated_at' => now()->toIso8601String(),
        ]));

        $chunkJobs = [];
        foreach ($chunks as $index => $chunkIds) {
            $chunkJobs[] = new GenerateBulkSurveyPdfChunkJob(
                jobId: $this->jobId,
                surveyIds: $chunkIds,
                partIndex: $index + 1,
                filters: $this->filters,
                baseFileName: $baseFileName,
                generatedAtIso: $generatedAt->toIso8601String(),
            );
        }

        $jobId = $this->jobId;

        Bus::batch($chunkJobs)
            ->name("bulk-survey-pdf:{$this->jobId}")
            ->allowFailures(false)
            ->catch(function (Batch $batch, \Throwable $exception) use ($exportService, $jobId): void {
                Log::error('[BULK PDF JOB] Falló la generación por lotes de PDF masivo', [
                    'job_id' => $jobId,
                    'batch_id' => $batch->id,
                    'error' => $exception->getMessage(),
                ]);

                $exportService->markFailed(
                    $jobId,
                    'Error al generar las partes del PDF: '.$exception->getMessage()
                );
                $exportService->cleanupTempParts($jobId);
            })
            ->dispatch();
    }

    public function failed(\Throwable $exception): void
    {
        $exportService = app(SocioDemographicSurveyBulkPdfExportService::class);

        Log::error('[BULK PDF JOB] Job de orquestación de PDF masivo falló', [
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
            'user_id' => $this->userId,
        ]);

        $exportService->markFailed(
            $this->jobId,
            'El job falló al iniciar la generación: '.$exception->getMessage()
        );
        $exportService->cleanupTempParts($this->jobId);
    }
}
