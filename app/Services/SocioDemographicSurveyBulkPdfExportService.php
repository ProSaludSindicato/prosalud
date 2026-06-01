<?php

namespace App\Services;

use App\Jobs\FinalizeBulkSurveyPdfZipJob;
use App\Models\SocioDemographicSurvey;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SocioDemographicSurveyBulkPdfExportService
{
    public function cacheKey(string $jobId): string
    {
        return "survey_report_pdf:{$jobId}";
    }

    public function cacheTtl(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildFilteredQuery(array $filters): Builder
    {
        $query = SocioDemographicSurvey::query();

        if (! empty($filters['year']) && $filters['year'] >= 2000 && $filters['year'] <= 2100) {
            $query->byYear((int) $filters['year']);
        }

        if (! empty($filters['month']) && $filters['month'] >= 1 && $filters['month'] <= 12) {
            $query->byMonth((int) $filters['month']);
        }

        $hospitals = $filters['hospitals'] ?? [];
        if (! empty($hospitals)) {
            $query->byHospitals($hospitals);
        } elseif (! empty($filters['hospital'])) {
            $query->byHospital($filters['hospital']);
        }

        if (! empty($filters['numero_documento'])) {
            if (! empty($filters['tipo_documento'])) {
                $query->byDocument($filters['tipo_documento'], $filters['numero_documento']);
            } else {
                $query->byDocumentNumber($filters['numero_documento']);
            }
        }

        if (! empty($filters['nombre'])) {
            $query->byName($filters['nombre']);
        }

        $surveyType = $filters['survey_type'] ?? 'all';
        if ($surveyType !== 'all') {
            if ($surveyType === 'active_affiliate') {
                $query->where(function ($q) {
                    $q->where('survey_type', 'active_affiliate')
                        ->orWhereNull('survey_type');
                });
            } elseif ($surveyType === 'new_entry') {
                $query->where('survey_type', 'new_entry');
            } elseif ($surveyType === 'bulk_entry') {
                $query->where('survey_type', 'bulk_entry');
            }
        }

        $dateRange = $filters['date_range'] ?? [];
        if (! ($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date'])) {
                $startDate = \Carbon\Carbon::parse($dateRange['start_date'])->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if (isset($dateRange['end_date'])) {
                $endDate = \Carbon\Carbon::parse($dateRange['end_date'])->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
        }

        if (! empty($filters['profesion'])) {
            $query->where('profesion', $filters['profesion']);
        }

        return $query;
    }

    public function surveysPerPart(): int
    {
        return max(1, (int) config('surveys.bulk_pdf.surveys_per_part', 20));
    }

    public function reportsDiskName(): string
    {
        return (string) config('filesystems.survey_reports_disk', 'local');
    }

    public function tempPartsDirectory(string $jobId): string
    {
        return 'reports/surveys-pdf-temp/'.$jobId;
    }

    public function tempPartStoragePath(string $jobId, int $partIndex, string $baseFileName): string
    {
        return $this->tempPartsDirectory($jobId).'/'.$baseFileName.'_parte_'.$partIndex.'.pdf';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function putStatus(string $jobId, array $data): void
    {
        $existing = $this->getStatus($jobId) ?? [];

        cache()->put(
            $this->cacheKey($jobId),
            array_merge($existing, $data),
            $this->cacheTtl()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(string $jobId): ?array
    {
        $status = cache()->get($this->cacheKey($jobId));

        return is_array($status) ? $status : null;
    }

    public function markFailed(string $jobId, string $error): void
    {
        $existing = $this->getStatus($jobId) ?? [];

        $this->putStatus($jobId, array_merge($existing, [
            'status' => 'failed',
            'error' => $error,
            'failed_at' => now()->toIso8601String(),
        ]));
    }

    public function incrementCompletedParts(string $jobId): void
    {
        Cache::lock($this->cacheKey($jobId).':lock', 10)->block(5, function () use ($jobId): void {
            $status = $this->getStatus($jobId) ?? [];
            $status['completed_parts'] = ($status['completed_parts'] ?? 0) + 1;
            $this->putStatus($jobId, $status);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function maybeDispatchFinalize(string $jobId, array $filters): void
    {
        Cache::lock($this->cacheKey($jobId).':finalize', 30)->block(10, function () use ($jobId, $filters): void {
            $status = $this->getStatus($jobId);

            if ($status === null || ($status['status'] ?? '') !== 'processing') {
                return;
            }

            if ($status['finalize_dispatched'] ?? false) {
                return;
            }

            $completedParts = (int) ($status['completed_parts'] ?? 0);
            $totalParts = (int) ($status['total_parts'] ?? 0);

            if ($totalParts <= 0 || $completedParts < $totalParts) {
                return;
            }

            $this->putStatus($jobId, [
                'finalize_dispatched' => true,
                'updated_at' => now()->toIso8601String(),
            ]);

            FinalizeBulkSurveyPdfZipJob::dispatch(
                jobId: $jobId,
                filters: $filters,
                userId: isset($status['user_id']) ? (int) $status['user_id'] : null,
                totalSurveys: (int) ($status['count'] ?? 0),
                totalParts: $totalParts,
                baseFileName: (string) ($status['base_file_name'] ?? 'Encuestas_Sociodemograficas_'.now()->format('Y-m-d_His')),
            );

            Log::info('[BULK PDF] Finalización de ZIP encolada', [
                'job_id' => $jobId,
                'completed_parts' => $completedParts,
                'total_parts' => $totalParts,
            ]);
        });
    }

    /**
     * Re-intenta encolar la finalización si quedó marcada pero nunca completó.
     */
    public function recoverStuckFinalize(string $jobId): void
    {
        $status = $this->getStatus($jobId);

        if ($status === null || ($status['status'] ?? '') !== 'processing') {
            return;
        }

        if (! ($status['finalize_dispatched'] ?? false)) {
            return;
        }

        $completedParts = (int) ($status['completed_parts'] ?? 0);
        $totalParts = (int) ($status['total_parts'] ?? 0);

        if ($totalParts <= 0 || $completedParts < $totalParts) {
            return;
        }

        $updatedAt = $status['updated_at'] ?? $status['created_at'] ?? null;
        if ($updatedAt === null) {
            return;
        }

        if (\Carbon\Carbon::parse($updatedAt)->lte(now()->subMinutes(3)) === false) {
            return;
        }

        Log::warning('[BULK PDF] Reintentando finalización de ZIP encolada previamente', [
            'job_id' => $jobId,
        ]);

        $this->putStatus($jobId, [
            'finalize_dispatched' => false,
        ]);

        $filters = is_array($status['filters'] ?? null) ? $status['filters'] : [];
        $this->maybeDispatchFinalize($jobId, $filters);
    }

    public function loadLogoBase64(): ?string
    {
        $logoPath = public_path('assets/logo.png');

        if (! file_exists($logoPath)) {
            return null;
        }

        try {
            return base64_encode((string) file_get_contents($logoPath));
        } catch (\Throwable $e) {
            Log::warning('[BULK PDF] Error loading logo for PDF', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int, SocioDemographicSurvey>  $surveys
     * @return array<int, array{survey: SocioDemographicSurvey, signatureImageBase64: string|null}>
     */
    public function buildSurveysWithSignatures(Collection $surveys): array
    {
        return $surveys->map(function (SocioDemographicSurvey $survey): array {
            return [
                'survey' => $survey,
                'signatureImageBase64' => $this->loadSignatureBase64($survey),
            ];
        })->all();
    }

    public function loadSignatureBase64(SocioDemographicSurvey $survey): ?string
    {
        if (! $survey->firma_path) {
            return null;
        }

        try {
            $disk = Storage::disk('prosalud-private');
            if ($disk->exists($survey->firma_path)) {
                return base64_encode((string) $disk->get($survey->firma_path));
            }

            $localDisk = Storage::disk('local');
            if ($localDisk->exists($survey->firma_path)) {
                return base64_encode((string) $localDisk->get($survey->firma_path));
            }
        } catch (\Throwable $e) {
            Log::warning('[BULK PDF] Error loading signature image for PDF chunk', [
                'survey_id' => $survey->id,
                'path' => $survey->firma_path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array<int, array{survey: SocioDemographicSurvey, signatureImageBase64: string|null}>  $surveysWithSignatures
     * @param  array<string, mixed>  $filters
     */
    public function generatePdfPartToStorage(
        string $storagePath,
        array $surveysWithSignatures,
        ?string $logoBase64,
        \Carbon\Carbon $generatedAt,
        array $filters
    ): void {
        $pdf = Pdf::loadView('surveys.socio-demographic-survey-bulk-pdf', [
            'surveysWithSignatures' => $surveysWithSignatures,
            'logoBase64' => $logoBase64,
            'generatedAt' => $generatedAt,
            'filters' => $filters,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('enable-local-file-access', true);
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', false);

        $pdfContent = $pdf->output();

        if ($pdfContent === '' || strncmp($pdfContent, '%PDF-', 5) !== 0) {
            throw new \RuntimeException('El contenido generado no es un PDF válido.');
        }

        $disk = Storage::disk($this->reportsDiskName());
        $disk->put($storagePath, $pdfContent);
    }

    /**
     * @param  array<int, array{storage_path: string, file_name: string, count: int}>  $parts
     */
    public function createZipFromParts(string $jobId, string $zipFileName, array $parts): string
    {
        $disk = Storage::disk($this->reportsDiskName());
        $localTempDir = storage_path('app/temp/'.$jobId);

        if (! is_dir($localTempDir)) {
            mkdir($localTempDir, 0755, true);
        }

        $zipTempPath = $localTempDir.'/'.$zipFileName;
        $zip = new \ZipArchive;

        if ($zip->open($zipTempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo ZIP para el reporte.');
        }

        foreach ($parts as $part) {
            if (! $disk->exists($part['storage_path'])) {
                $zip->close();
                throw new \RuntimeException("No se encontró la parte del PDF: {$part['file_name']}");
            }

            $localPartPath = $localTempDir.'/'.$part['file_name'];
            file_put_contents($localPartPath, $disk->get($part['storage_path']));
            $zip->addFile($localPartPath, $part['file_name']);
        }

        $zip->close();

        if (! is_file($zipTempPath)) {
            throw new \RuntimeException('El archivo ZIP no se generó correctamente.');
        }

        foreach ($parts as $part) {
            @unlink($localTempDir.'/'.$part['file_name']);
        }

        $generatedAt = now()->setTimezone('America/Bogota');
        $finalStoragePath = 'reports/surveys-pdf/'.$generatedAt->format('Y').'/'.$generatedAt->format('m').'/'.$zipFileName;

        $stream = fopen($zipTempPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo leer el archivo ZIP generado.');
        }

        try {
            $disk->writeStream($finalStoragePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        @unlink($zipTempPath);

        return $finalStoragePath;
    }

    public function cleanupTempParts(string $jobId): void
    {
        $disk = Storage::disk($this->reportsDiskName());
        $directory = $this->tempPartsDirectory($jobId);

        if ($disk->allFiles($directory) !== []) {
            $disk->deleteDirectory($directory);
        }

        $localTempDir = storage_path('app/temp/'.$jobId);
        if (is_dir($localTempDir)) {
            foreach (glob($localTempDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($localTempDir);
        }
    }
}
