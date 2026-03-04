<?php

namespace App\Jobs;

use App\Models\SocioDemographicSurvey;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateBulkSurveyPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 120; // 2 minutos entre reintentos

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutos

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $jobId,
        public array $filters,
        public ?int $userId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Máximo de encuestas por PDF individual
        $maxSurveysPerPdf = 300;

        try {
            // Aumentar tiempo de ejecución para el job
            set_time_limit($this->timeout);
            ini_set('max_execution_time', (string) $this->timeout);

            Log::info('[BULK PDF JOB] Iniciando generación de PDF masivo de encuestas sociodemográficas', [
                'job_id' => $this->jobId,
                'user_id' => $this->userId,
                'filters' => $this->filters,
            ]);

            // Build query with filters
            $query = SocioDemographicSurvey::query();

            // Filter by survey type
            $surveyType = $this->filters['survey_type'] ?? 'all';
            if ($surveyType !== 'all') {
                if ($surveyType === 'active_affiliate') {
                    $query->where(function ($q) {
                        $q->where('survey_type', 'active_affiliate')
                          ->orWhereNull('survey_type');
                    });
                } elseif ($surveyType === 'new_entry') {
                    $query->where('survey_type', 'new_entry');
                }
            }

            // Filter by date range
            $dateRange = $this->filters['date_range'] ?? [];
            if (!($dateRange['include_all'] ?? true)) {
                if (isset($dateRange['start_date'])) {
                    $startDate = \Carbon\Carbon::parse($dateRange['start_date'])->startOfDay();
                    $query->where('created_at', '>=', $startDate);
                }

                if (isset($dateRange['end_date'])) {
                    $endDate = \Carbon\Carbon::parse($dateRange['end_date'])->endOfDay();
                    $query->where('created_at', '<=', $endDate);
                }
            }

            // Filter by hospital (coincidencia al inicio)
            if (isset($this->filters['hospital']) && !empty($this->filters['hospital'])) {
                $query->byHospital($this->filters['hospital']);
            }

            // Filter by profesion (process)
            if (isset($this->filters['profesion']) && !empty($this->filters['profesion'])) {
                $query->where('profesion', $this->filters['profesion']);
            }

            // Get surveys
            $surveys = $query->orderBy('created_at', 'desc')->get();

            if ($surveys->isEmpty()) {
                cache()->put(
                    "survey_report_pdf:{$this->jobId}",
                    [
                        'status' => 'failed',
                        'error' => 'No se encontraron encuestas con los filtros especificados.',
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );
                return;
            }

            // Get logo path and convert to base64
            $logoBase64 = null;
            $logoPath = public_path('assets/logo.png');
            if (file_exists($logoPath)) {
                try {
                    $logoContent = file_get_contents($logoPath);
                    $logoBase64 = base64_encode($logoContent);
                } catch (\Exception $e) {
                    Log::warning('[BULK PDF JOB] Error loading logo for PDF', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fecha de generación para nombre y carpetas (año/mes en S3)
            $generatedAt = now()->setTimezone('America/Bogota');
            $baseFileName = 'Encuestas_Sociodemograficas_' . $generatedAt->format('Y-m-d_His');

            // Generar múltiples PDFs en lotes y empaquetarlos en un ZIP
            $tempDir = storage_path('app/temp/' . $this->jobId);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $pdfTempFiles = [];
            $totalSurveys = $surveys->count();
            $chunkIndex = 0;

            foreach ($surveys->chunk($maxSurveysPerPdf) as $chunk) {
                $chunkIndex++;

                // Cargar firmas solo para el lote actual
                $surveysWithSignatures = $chunk->map(function ($survey) {
                    $signatureImageBase64 = null;
                    if ($survey->firma_path) {
                        try {
                            $disk = Storage::disk('prosalud-private');
                            if ($disk->exists($survey->firma_path)) {
                                $signatureContent = $disk->get($survey->firma_path);
                                $signatureImageBase64 = base64_encode($signatureContent);
                            } else {
                                $localDisk = Storage::disk('local');
                                if ($localDisk->exists($survey->firma_path)) {
                                    $signatureContent = $localDisk->get($survey->firma_path);
                                    $signatureImageBase64 = base64_encode($signatureContent);
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning('[BULK PDF JOB] Error loading signature image for PDF chunk', [
                                'survey_id' => $survey->id,
                                'path' => $survey->firma_path,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    return [
                        'survey' => $survey,
                        'signatureImageBase64' => $signatureImageBase64,
                    ];
                });

                $partFileName = $baseFileName . '_parte_' . $chunkIndex . '.pdf';
                $tempPath = $tempDir . '/' . $partFileName;

                // Generar PDF para el lote actual
                $pdf = Pdf::loadView('surveys.socio-demographic-survey-bulk-pdf', [
                    'surveysWithSignatures' => $surveysWithSignatures,
                    'logoBase64' => $logoBase64,
                    'generatedAt' => $generatedAt,
                    'filters' => $this->filters,
                ]);

                // Set PDF options
                $pdf->setPaper('a4', 'portrait');
                $pdf->setOption('enable-local-file-access', true);
                $pdf->setOption('isHtml5ParserEnabled', true);
                $pdf->setOption('isRemoteEnabled', false);

                $pdf->save($tempPath);

                // Validar PDF generado
                if (!is_file($tempPath)) {
                    throw new \RuntimeException("El archivo PDF (parte {$chunkIndex}) no se generó correctamente.");
                }
                $tempSize = filesize($tempPath);
                if ($tempSize === 0) {
                    @unlink($tempPath);
                    throw new \RuntimeException("El archivo PDF generado (parte {$chunkIndex}) está vacío.");
                }
                $header = @file_get_contents($tempPath, false, null, 0, 8);
                if ($header === false || strpos($header, '%PDF-') !== 0) {
                    @unlink($tempPath);
                    throw new \RuntimeException("El archivo generado (parte {$chunkIndex}) no es un PDF válido (cabecera incorrecta).");
                }

                $pdfTempFiles[] = [
                    'path' => $tempPath,
                    'name' => $partFileName,
                    'count' => $chunk->count(),
                ];

                // Intentar liberar memoria entre lotes
                unset($surveysWithSignatures, $pdf);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            if (empty($pdfTempFiles)) {
                throw new \RuntimeException('No se generó ningún archivo PDF para el reporte.');
            }

            // Crear ZIP con todos los PDFs generados
            $zipFileName = $baseFileName . '.zip';
            $zipTempPath = $tempDir . '/' . $zipFileName;

            $zip = new \ZipArchive();
            if ($zip->open($zipTempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el archivo ZIP para el reporte.');
            }

            foreach ($pdfTempFiles as $file) {
                $zip->addFile($file['path'], $file['name']);
            }

            $zip->close();

            if (!is_file($zipTempPath)) {
                throw new \RuntimeException('El archivo ZIP no se generó correctamente.');
            }

            // Store ZIP in shared storage; en S3 se organiza por año/mes
            $year = $generatedAt->format('Y');
            $month = $generatedAt->format('m');
            $storagePath = 'reports/surveys-pdf/' . $year . '/' . $month . '/' . $zipFileName;
            $disk = Storage::disk(config('filesystems.survey_reports_disk', 'local'));
            $disk->put($storagePath, file_get_contents($zipTempPath));

            // Clean up temporary files
            foreach ($pdfTempFiles as $file) {
                @unlink($file['path']);
            }
            @unlink($zipTempPath);

            // Store metadata in cache for retrieval
            cache()->put(
                "survey_report_pdf:{$this->jobId}",
                [
                    'status' => 'completed',
                    'file_path' => $storagePath,
                    'file_name' => $zipFileName,
                    'created_at' => now()->toIso8601String(),
                    'count' => $totalSurveys,
                    'parts' => array_map(static function (array $file): array {
                        return [
                            'file_name' => $file['name'],
                            'count' => $file['count'],
                        ];
                    }, $pdfTempFiles),
                ],
                now()->addHours(24) // Keep for 24 hours
            );

            Log::info('[BULK PDF JOB] ZIP masivo de encuestas sociodemográficas generado exitosamente', [
                'job_id' => $this->jobId,
                'file_path' => $storagePath,
                'file_name' => $zipFileName,
                'count' => $totalSurveys,
                'parts' => count($pdfTempFiles),
                'user_id' => $this->userId,
            ]);

            // Register audit log
            $auditLogService = app(AuditLogService::class);
            $auditLogService->logBusinessProcess('socio_demographic_survey', 'bulk_pdf_download', [
                'job_id' => $this->jobId,
                'count' => $totalSurveys,
                'file_name' => $zipFileName,
                'filters' => $this->filters,
                'user_id' => $this->userId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[BULK PDF JOB] Error generando PDF masivo de encuestas sociodemográficas', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $this->userId,
            ]);

            // Store error in cache
            cache()->put(
                "survey_report_pdf:{$this->jobId}",
                [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'created_at' => now()->toIso8601String(),
                ],
                now()->addHours(24)
            );

            // Re-lanzar para que Laravel lo marque como fallido y pueda reintentar
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[BULK PDF JOB] Job de generación de PDF masivo falló después de todos los intentos', [
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'user_id' => $this->userId,
        ]);

        // Update cache with final failure status
        cache()->put(
            "survey_report_pdf:{$this->jobId}",
            [
                'status' => 'failed',
                'error' => 'El job falló después de todos los intentos: ' . $exception->getMessage(),
                'created_at' => now()->toIso8601String(),
            ],
            now()->addHours(24)
        );
    }
}

