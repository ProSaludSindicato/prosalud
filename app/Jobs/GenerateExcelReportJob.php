<?php

namespace App\Jobs;

use App\Enums\ExcelReport;
use App\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateExcelReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Reports with images for a full year download thousands of files, so allow a long run.
     * Must stay below the queue connection's retry_after.
     */
    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public ExcelReport $report,
        public string $jobId,
        public array $filters,
        public array $options = [],
        public ?int $userId = null
    ) {}

    /**
     * Mark the report as processing and queue its generation. Returns the job id used to poll its status.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public static function start(ExcelReport $report, array $filters, array $options = [], ?int $userId = null): string
    {
        $jobId = Str::uuid()->toString();

        static::putStatus($report, $jobId, [
            'status' => 'processing',
            'created_at' => now()->toIso8601String(),
        ]);

        static::dispatch($report, $jobId, $filters, $options, $userId);

        Log::info('Reporte Excel encolado para generación asíncrona', [
            'report' => $report->value,
            'job_id' => $jobId,
            'user_id' => $userId,
        ]);

        return $jobId;
    }

    /**
     * Disk shared between web and workers where reports and their status are stored.
     */
    public static function reportsDisk(): string
    {
        return (string) config('filesystems.excel_reports_disk', 'local');
    }

    /**
     * Current status of a report. The status file on the shared disk is the source of truth because
     * web and worker instances may not share the cache; the cache is only a fallback.
     *
     * @return array<string, mixed>|null
     */
    public static function status(ExcelReport $report, string $jobId): ?array
    {
        if (! Str::isUuid($jobId)) {
            return null;
        }

        try {
            $content = Storage::disk(static::reportsDisk())->get(static::statusPath($report, $jobId));
            $status = $content ? json_decode($content, true) : null;
        } catch (\Throwable) {
            $status = null;
        }

        if (is_array($status)) {
            return $status;
        }

        $cached = cache()->get($report->cacheKey($jobId));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function putStatus(ExcelReport $report, string $jobId, array $status): void
    {
        cache()->put($report->cacheKey($jobId), $status, now()->addHours(24));

        try {
            Storage::disk(static::reportsDisk())->put(static::statusPath($report, $jobId), json_encode($status));
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el estado del reporte Excel en almacenamiento', [
                'report' => $report->value,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function statusPath(ExcelReport $report, string $jobId): string
    {
        return $report->storageDirectory($jobId).'/status.json';
    }

    public function handle(AuditLogService $auditLogService): void
    {
        set_time_limit($this->timeout);
        ini_set('memory_limit', '1024M');

        Log::debug('Iniciando generación asíncrona de reporte Excel', [
            'report' => $this->report->value,
            'job_id' => $this->jobId,
            'user_id' => $this->userId,
        ]);

        $filePath = $this->report->generate($this->filters, $this->options);

        if (! file_exists($filePath)) {
            throw new \RuntimeException('El archivo del reporte no fue creado');
        }

        $fileName = $this->report->fileName();
        $storagePath = $this->report->storageDirectory($this->jobId).'/'.$fileName;

        $disk = static::reportsDisk();
        $fileSize = filesize($filePath) ?: null;

        $stream = fopen($filePath, 'r');
        $stored = Storage::disk($disk)->writeStream($storagePath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $stored) {
            throw new \RuntimeException("No se pudo guardar el reporte en el disco {$disk}");
        }

        @unlink($filePath);

        static::putStatus($this->report, $this->jobId, [
            'status' => 'completed',
            'disk' => $disk,
            'file_path' => $storagePath,
            'file_name' => $fileName,
            'created_at' => now()->toIso8601String(),
        ]);

        Log::info('Reporte Excel generado exitosamente', [
            'report' => $this->report->value,
            'job_id' => $this->jobId,
            'file_path' => $storagePath,
            'file_size' => $fileSize,
            'user_id' => $this->userId,
        ]);

        if ($auditProcess = $this->report->auditProcess()) {
            $auditLogService->logBusinessProcess($auditProcess, 'excel_export', [
                'job_id' => $this->jobId,
                'filters' => $this->filters,
                'options' => $this->options,
                'file_name' => $fileName,
                'user_id' => $this->userId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Error generando reporte Excel (background)', [
            'report' => $this->report->value,
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
            'user_id' => $this->userId,
        ]);

        static::putStatus($this->report, $this->jobId, [
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
