<?php

namespace App\Jobs;

use App\Models\SocioDemographicSurvey;
use App\Services\SocioDemographicSurveyBulkPdfExportService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateBulkSurveyPdfChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    /**
     * @param  array<int, string>  $surveyIds
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $jobId,
        public array $surveyIds,
        public int $partIndex,
        public array $filters,
        public string $baseFileName,
        public string $generatedAtIso,
    ) {}

    public function handle(SocioDemographicSurveyBulkPdfExportService $exportService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        set_time_limit($this->timeout);

        $generatedAt = \Carbon\Carbon::parse($this->generatedAtIso)->setTimezone('America/Bogota');
        $storagePath = $exportService->tempPartStoragePath($this->jobId, $this->partIndex, $this->baseFileName);

        $surveys = SocioDemographicSurvey::query()
            ->whereIn('id', $this->surveyIds)
            ->get()
            ->sortBy(fn (SocioDemographicSurvey $survey): int|false => array_search($survey->id, $this->surveyIds, true))
            ->values();

        if ($surveys->isEmpty()) {
            throw new \RuntimeException("No se encontraron encuestas para la parte {$this->partIndex}.");
        }

        $surveysWithSignatures = $exportService->buildSurveysWithSignatures($surveys);
        $logoBase64 = $exportService->loadLogoBase64();

        $exportService->generatePdfPartToStorage(
            storagePath: $storagePath,
            surveysWithSignatures: $surveysWithSignatures,
            logoBase64: $logoBase64,
            generatedAt: $generatedAt,
            filters: $this->filters,
        );

        unset($surveysWithSignatures, $surveys);

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $exportService->incrementCompletedParts($this->jobId);
        $exportService->maybeDispatchFinalize($this->jobId, $this->filters);

        Log::info('[BULK PDF JOB] Parte de PDF generada', [
            'job_id' => $this->jobId,
            'part_index' => $this->partIndex,
            'count' => count($this->surveyIds),
            'storage_path' => $storagePath,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[BULK PDF JOB] Falló la generación de una parte del PDF', [
            'job_id' => $this->jobId,
            'part_index' => $this->partIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
