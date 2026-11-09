<?php

namespace App\Jobs;

use App\Services\SstDeliveryReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateSstDeliveryReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Reports with signatures for a full year download thousands of images, so allow a long run.
     * Must stay below the queue connection's retry_after.
     */
    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $jobId,
        public array $filters,
        public array $options = []
    ) {}

    public static function cacheKey(string $jobId): string
    {
        return "sst_report:{$jobId}";
    }

    public function handle(SstDeliveryReportService $reportService): void
    {
        set_time_limit($this->timeout);
        ini_set('memory_limit', '1024M');

        Log::debug('Iniciando generación asíncrona de reporte de entregas SST', [
            'job_id' => $this->jobId,
            'include_signatures' => $this->options['includeSignatures'] ?? false,
        ]);

        $filePath = $reportService->generateReport($this->filters, $this->options);

        if (! file_exists($filePath)) {
            throw new \RuntimeException('El archivo del reporte no fue creado');
        }

        $fileName = 'reporte-dotacion-epp-'.now()->setTimezone('America/Bogota')->format('Ymd-His').'.xlsx';
        $storagePath = 'reports/sst-delivery/'.$this->jobId.'/'.$fileName;

        $stream = fopen($filePath, 'r');
        Storage::disk('local')->writeStream($storagePath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        @unlink($filePath);

        cache()->put(self::cacheKey($this->jobId), [
            'status' => 'completed',
            'file_path' => $storagePath,
            'file_name' => $fileName,
            'created_at' => now()->toIso8601String(),
        ], now()->addHours(24));

        Log::info('Reporte de entregas SST generado exitosamente', [
            'job_id' => $this->jobId,
            'file_path' => $storagePath,
            'file_name' => $fileName,
            'file_size' => Storage::disk('local')->size($storagePath),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Error generando reporte de entregas SST (background)', [
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
        ]);

        cache()->put(self::cacheKey($this->jobId), [
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'created_at' => now()->toIso8601String(),
        ], now()->addHours(24));
    }
}
