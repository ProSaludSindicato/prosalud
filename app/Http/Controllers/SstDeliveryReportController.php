<?php

namespace App\Http\Controllers;

use App\Services\SstDeliveryReportService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Log, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, StreamedResponse};

class SstDeliveryReportController extends Controller
{
    public function __construct(
        private readonly SstDeliveryReportService $reportService
    ) {
    }

    /**
     * Generate and download Excel report with delivery records.
     * If signatures are included, the report is generated asynchronously.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            // Get filters from request
            $filters = $request->only([
                'hospital',
                'startDate',
                'endDate',
                'documentNumber',
                'deliveredBy',
            ]);

            // Get options from request
            $includeSignatures = $request->boolean('includeSignatures', false);
            $options = [
                'includeSignatures' => $includeSignatures,
                'signatureSize' => [
                    'width' => $request->integer('signatureWidth', 100),
                    'height' => $request->integer('signatureHeight', 50),
                ],
            ];

            // If signatures are included, generate report asynchronously
            if ($includeSignatures) {
                $jobId = Str::uuid()->toString();

                // Store initial status in cache
                cache()->put(
                    "sst_report:{$jobId}",
                    [
                        'status' => 'processing',
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );

                // Generate report asynchronously after response (no workers needed)
                $reportService = $this->reportService;
                dispatch(function () use ($reportService, $filters, $options, $jobId) {
                    try {
                        Log::info('Iniciando generación asíncrona de reporte de entregas SST', [
                            'job_id' => $jobId,
                            'include_signatures' => true,
                        ]);

                        // Generate report
                        $filePath = $reportService->generateReport($filters, $options);

                        if (!file_exists($filePath)) {
                            throw new \Exception('El archivo del reporte no fue creado');
                        }

                        // Generate file name
                        $fileName = 'reporte-dotacion-epp-' . now()->setTimezone('America/Bogota')->format('Ymd-His') . '.xlsx';

                        // Store file in storage for later download
                        $storagePath = 'reports/sst-delivery/' . $jobId . '/' . $fileName;
                        $disk = Storage::disk('local');
                        $disk->put($storagePath, file_get_contents($filePath));

                        // Clean up temporary file
                        @unlink($filePath);

                        // Store metadata in cache for retrieval
                        cache()->put(
                            "sst_report:{$jobId}",
                            [
                                'status' => 'completed',
                                'file_path' => $storagePath,
                                'file_name' => $fileName,
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24) // Keep for 24 hours
                        );

                        Log::info('Reporte de entregas SST generado exitosamente', [
                            'job_id' => $jobId,
                            'file_path' => $storagePath,
                            'file_name' => $fileName,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Error generando reporte de entregas SST (background)', [
                            'job_id' => $jobId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Store error in cache
                        cache()->put(
                            "sst_report:{$jobId}",
                            [
                                'status' => 'failed',
                                'error' => $e->getMessage(),
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24)
                        );
                    }
                })->afterResponse();

                Log::info('Reporte de entregas SST encolado para generación asíncrona', [
                    'job_id' => $jobId,
                    'include_signatures' => true,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
                    'job_id' => $jobId,
                    'status' => 'processing',
                    'check_status_url' => url("/api/dotacion-epp/reports/deliveries/status/{$jobId}"),
                ], 202);
            }

            // Generate report synchronously (without signatures)
            $filePath = $this->reportService->generateReport($filters, $options);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte de entregas SST: archivo no creado');

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'reporte-dotacion-epp-' . now()->setTimezone('America/Bogota')->format('Ymd-His') . '.xlsx';

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte de entregas SST', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check the status of an async report generation job.
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        $cacheKey = "sst_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        $response = [
            'success' => true,
            'job_id' => $jobId,
            'status' => $status['status'],
        ];

        if ($status['status'] === 'completed') {
            $response['download_url'] = url("/api/dotacion-epp/reports/deliveries/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed report.
     */
    public function downloadReport(string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "sst_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'El reporte aún no está listo. Estado: ' . ($status['status'] ?? 'unknown'),
                'status' => $status['status'],
            ], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'reporte-dotacion-epp.xlsx';

        if (!$filePath) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta del archivo no encontrada',
            ], 404);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = $disk->get($filePath);

            Log::info('Reporte de entregas SST descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Exception $e) {
            Log::error('Error descargando reporte de entregas SST', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
}

